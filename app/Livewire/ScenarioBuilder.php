<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ScenarioStatus;
use App\Forecast\HouseholdAssembler;
use App\Import\ImportException;
use App\Import\ImportRegistry;
use App\Import\ImportResult;
use App\Import\SpreadsheetReader;
use App\Models\AssumptionSet;
use App\Models\Household;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The scenario builder: a household and a housing decision entered by hand, then
 * assembled into the engine's DTOs and persisted (household encrypted, scenario
 * holding the encrypted housing action). One shape, three consumers — this is the UI
 * consumer; {@see HouseholdAssembler} does the lossless string-to-DTO conversion.
 *
 * Validation enforces the rules the plan calls out: salary only required when a
 * person is earning, no negative money, and Scotland refused until its band pack is
 * loaded (mirroring the engine's own refusal, rather than silently using rUK bands).
 */
#[Layout('components.layouts.app')]
class ScenarioBuilder extends Component
{
    use WithFileUploads;

    /** The wizard guides through these steps; the user may also jump between them freely. */
    public const STEPS = [
        1 => 'About & people',
        2 => 'Pensions & income',
        3 => 'Your net worth',
        4 => 'Spending',
        5 => 'The decision',
    ];

    /** Which top-level form section lives on which step — drives the jump-to-first-error on save. */
    private const STEP_OF_FIELD = [
        'name' => 1, 'householdName' => 1, 'region' => 1, 'baseTaxYear' => 1,
        'variant' => 1, 'assumptionSetId' => 1, 'ihtModelled' => 1, 'people' => 1,
        'pensions' => 2, 'incomeStreams' => 2,
        'accounts' => 3, 'property' => 3, 'hasProperty' => 3,
        'expense' => 4, 'oneOffCosts' => 4,
        'housing' => 5,
    ];

    public int $step = 1;

    public string $name = '';

    public string $householdName = '';

    public string $region = 'england_wales_ni';

    public string $baseTaxYear = '2026-27';

    public string $variant = 'rent';

    public bool $ihtModelled = false;

    public ?int $assumptionSetId = null;

    /** @var list<array<string, mixed>> */
    public array $people = [];

    /** @var array<string, mixed> */
    public array $expense = ['essential' => '', 'discretionary' => '', 'survivorFactor' => '70'];

    /** @var list<array<string, mixed>> */
    public array $oneOffCosts = [];

    /** @var list<array<string, mixed>> */
    public array $pensions = [];

    /** @var list<array<string, mixed>> */
    public array $accounts = [];

    /** @var list<array<string, mixed>> */
    public array $incomeStreams = [];

    public bool $hasProperty = true;

    /** @var array<string, mixed> */
    public array $property = [];

    /** @var array<string, mixed> */
    public array $housing = [];

    /** Optional spreadsheet upload used to pre-fill spending and salary. */
    public $importFile = null;

    public string $importProfile = 'retireforecast';

    /** Sheet/tab names in the uploaded file, and the chosen one (for multi-tab workbooks). */
    public array $importSheets = [];

    public ?string $importSheet = null;

    /** @var array<string, list<string>> what an import filled / still needs / noted */
    public array $importSummary = [];

    public function mount(): void
    {
        $this->people = [$this->blankPerson('p1')];
        $this->property = $this->blankProperty();
        $this->housing = $this->blankHousing();
        $this->assumptionSetId = AssumptionSet::where('is_default', true)->value('id');
    }

    protected function rules(): array
    {
        $ids = array_column($this->people, 'id');
        $money = ['nullable', 'numeric', 'min:0', 'decimal:0,2'];
        $moneyReq = ['required', 'numeric', 'min:0', 'decimal:0,2'];
        $rate = ['nullable', 'numeric'];

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'householdName' => ['required', 'string', 'max:255'],
            'region' => ['required', Rule::in(['england_wales_ni', 'scotland']), $this->regionSupported(...)],
            'baseTaxYear' => ['required', Rule::in(['2025-26', '2026-27'])],
            'variant' => ['required', Rule::in(['buy_outright', 'rent', 'stay_put'])],
            'assumptionSetId' => ['nullable', 'integer', 'exists:assumption_sets,id'],

            'people' => ['required', 'array', 'min:1', 'max:2'],
            'people.*.dob' => ['required', 'date', 'before:today'],
            'people.*.sex' => ['required', Rule::in(['male', 'female'])],
            'people.*.employmentStatus' => ['required', Rule::in(['employed', 'self_employed', 'retired', 'not_working'])],
            'people.*.grossSalary' => [...$money, 'required_if:people.*.employmentStatus,employed,self_employed'],
            'people.*.salaryGrowth' => $rate,
            'people.*.plannedRetirementAge' => ['nullable', 'integer', 'min:50', 'max:80'],
            'people.*.niCategory' => ['nullable', 'string', 'max:2'],

            'expense.essential' => $moneyReq,
            'expense.discretionary' => $money,
            'expense.survivorFactor' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'oneOffCosts.*.atAge' => ['required', 'integer', 'min:0', 'max:110'],
            'oneOffCosts.*.amount' => $moneyReq,
            'oneOffCosts.*.label' => ['nullable', 'string', 'max:255'],

            'pensions.*.subtype' => ['required', Rule::in(['dc', 'db', 'state'])],
            'pensions.*.ownerId' => ['required', Rule::in($ids)],
            'pensions.*.currentValue' => [...$money, 'required_if:pensions.*.subtype,dc'],
            'pensions.*.ongoingContribution' => $money,
            'pensions.*.employerContribution' => $money,
            'pensions.*.earliestAccessAge' => ['nullable', 'integer', 'min:55', 'max:75', 'required_if:pensions.*.subtype,dc'],
            'pensions.*.pclsTakenToDate' => $money,
            'pensions.*.growthAssumptionOverride' => $rate,
            'pensions.*.withdrawals.*.kind' => ['required', Rule::in(['pcls', 'ufpls', 'drawdown'])],
            'pensions.*.withdrawals.*.amount' => $moneyReq,
            'pensions.*.withdrawals.*.atAge' => ['required', 'integer', 'min:55', 'max:110'],
            'pensions.*.accruedAnnualPension' => [...$money, 'required_if:pensions.*.subtype,db'],
            'pensions.*.normalRetirementAge' => ['nullable', 'integer', 'min:50', 'max:75', 'required_if:pensions.*.subtype,db'],
            'pensions.*.revaluationBasis' => ['nullable', Rule::in(['none', 'cpi', 'rpi', 'cpi_capped_5', 'fixed'])],
            'pensions.*.escalationInPayment' => ['nullable', Rule::in(['none', 'cpi', 'rpi', 'cpi_capped_5', 'fixed'])],
            'pensions.*.spousePensionFraction' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pensions.*.commutationLumpSum' => $money,
            'pensions.*.commutationFactor' => ['nullable', 'numeric', 'min:0'],
            'pensions.*.weeklyForecast' => $money,
            'pensions.*.qualifyingYears' => ['nullable', 'integer', 'min:0', 'max:50'],
            'pensions.*.deferralWeeks' => ['nullable', 'integer', 'min:0'],

            'accounts.*.ownerId' => ['required', Rule::in($ids)],
            'accounts.*.type' => ['required', Rule::in(['isa', 'gia', 'cash', 'premium_bonds'])],
            'accounts.*.balance' => $moneyReq,
            'accounts.*.unrealisedGain' => $money,
            'accounts.*.yield' => $rate,

            'incomeStreams.*.ownerId' => ['required', Rule::in($ids)],
            'incomeStreams.*.type' => ['required', Rule::in(['rental', 'annuity', 'other'])],
            'incomeStreams.*.grossAnnual' => $moneyReq,
            'incomeStreams.*.startAge' => ['required', 'integer', 'min:0', 'max:110'],
            'incomeStreams.*.endAge' => ['nullable', 'integer', 'min:0', 'max:110', $this->endAfterStart(...)],

            'housing.salePrice' => $moneyReq,
            'housing.buyPrice' => $money,
            'housing.annualRent' => $money,
            'housing.rentInflationReal' => $rate,
            'housing.movingCosts' => $money,
            'housing.sellingCostRate' => ['nullable', 'numeric', 'min:0'],
        ];

        if ($this->hasProperty) {
            $rules['property.currentValue'] = $moneyReq;
            $rules['property.ownership'] = ['required', Rule::in(['outright', 'mortgaged'])];
            $rules['property.outstandingMortgage'] = $money;
            $rules['property.runningCosts'] = $money;
            $rules['property.growthAssumptionOverride'] = $rate;
            $rules['property.ownershipShare'] = ['nullable', 'numeric', 'min:0', 'max:100'];
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        return [
            'householdName' => 'household name',
            'people.*.dob' => 'date of birth',
            'people.*.grossSalary' => 'gross salary',
            'expense.essential' => 'essential annual spend',
            'housing.salePrice' => 'sale price',
        ];
    }

    /** An income stream's end age, if given, must not precede its start age. */
    public function endAfterStart(string $attribute, mixed $value, callable $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $i = explode('.', $attribute)[1] ?? null;
        $start = $i !== null ? ($this->incomeStreams[$i]['startAge'] ?? '') : '';

        if ($start !== '' && (int) $value < (int) $start) {
            $fail('The end age must be the same as or after the start age.');
        }
    }

    /** Region is allowed only if the engine can actually build its tax config (Scotland cannot yet). */
    public function regionSupported(string $attribute, mixed $value, callable $fail): void
    {
        if ($value !== 'scotland') {
            return;
        }

        try {
            TaxYearRegistry::for($this->baseTaxYear, RegionProfile::Scotland);
        } catch (\Throwable) {
            $fail('Scottish tax bands are not available yet. Choose England, Wales & Northern Ireland.');
        }
    }

    /**
     * Pre-fill the form from an uploaded budget spreadsheet, then land the user on the
     * spending step to review. The file is read once and never stored; an unreadable or
     * unrecognised file is reported, not swallowed.
     */
    /** When a file is chosen, list its tabs so a multi-tab workbook can be narrowed to one. */
    public function updatedImportFile(): void
    {
        $this->importSheets = [];
        $this->importSheet = null;

        if (! $this->importFile) {
            return;
        }

        try {
            $sheet = (new SpreadsheetReader)->read(
                (string) $this->importFile->getRealPath(),
                (string) $this->importFile->getClientOriginalName(),
            );
            $this->importSheets = $sheet->sheetNames();
            $this->importSheet = $this->importSheets[0] ?? null;
        } catch (\Throwable) {
            // A bad file is reported when the user presses Import, not here.
        }
    }

    public function import(): void
    {
        $this->validate(
            ['importFile' => ['required', 'file', 'max:2048']],
            [],
            ['importFile' => 'spreadsheet'],
        );

        $profile = (new ImportRegistry)->find($this->importProfile);

        if ($profile === null || ! $profile->isAvailable()) {
            $this->addError('importFile', 'That spreadsheet type cannot be imported yet. Choose the RetireForecast template, or enter the figures by hand.');

            return;
        }

        try {
            $sheet = (new SpreadsheetReader)->read(
                (string) $this->importFile->getRealPath(),
                (string) $this->importFile->getClientOriginalName(),
            );
            if ($this->importSheet !== null && $this->importSheet !== '') {
                $sheet = $sheet->select($this->importSheet);
            }
            $result = $profile->parse($sheet);
        } catch (ImportException $e) {
            $this->addError('importFile', $e->getMessage());

            return;
        }

        $this->applyImport($result);
        $this->reset(['importFile', 'importSheets', 'importSheet']);
    }

    private function applyImport(ImportResult $result): void
    {
        if ($result->expense !== []) {
            $this->expense = array_merge($this->expense, $result->expense);
        }

        if ($result->salaryAnnual !== null && isset($this->people[0])) {
            $this->people[0]['grossSalary'] = $result->salaryAnnual;
            $this->people[0]['employmentStatus'] = 'employed';
        }

        foreach ($result->pensions as $pension) {
            $this->pensions[] = $pension;
        }

        foreach ($result->incomeStreams as $stream) {
            $this->incomeStreams[] = $stream;
        }

        $this->importSummary = [
            'filled' => $result->filled,
            'missing' => $result->missing,
            'notes' => $result->notes,
        ];

        // Most of what was imported is spending, so land there to review it.
        $this->goToStep(4);
    }

    /** Free navigation between steps; clamped to the valid range. */
    public function goToStep(int $step): void
    {
        $this->step = max(1, min(count(self::STEPS), $step));
        $this->dispatch('step-changed');
    }

    public function nextStep(): void
    {
        $this->goToStep($this->step + 1);
    }

    public function prevStep(): void
    {
        $this->goToStep($this->step - 1);
    }

    public function addPerson(): void
    {
        if (count($this->people) < 2) {
            $this->people[] = $this->blankPerson('p'.(count($this->people) + 1));
            $this->resequencePeople();
        }
    }

    public function removePerson(int $i): void
    {
        unset($this->people[$i]);
        $this->resequencePeople();
    }

    public function addPension(string $subtype): void
    {
        $this->pensions[] = $this->blankPension($subtype);
    }

    public function removePension(int $i): void
    {
        unset($this->pensions[$i]);
        $this->pensions = array_values($this->pensions);
    }

    public function addWithdrawal(int $pi): void
    {
        $this->pensions[$pi]['withdrawals'][] = ['kind' => 'pcls', 'amount' => '', 'atAge' => ''];
    }

    public function removeWithdrawal(int $pi, int $wi): void
    {
        unset($this->pensions[$pi]['withdrawals'][$wi]);
        $this->pensions[$pi]['withdrawals'] = array_values($this->pensions[$pi]['withdrawals']);
    }

    public function addAccount(): void
    {
        $this->accounts[] = ['ownerId' => $this->firstPersonId(), 'type' => 'isa', 'balance' => '', 'unrealisedGain' => '', 'yield' => ''];
    }

    public function removeAccount(int $i): void
    {
        unset($this->accounts[$i]);
        $this->accounts = array_values($this->accounts);
    }

    public function addIncome(): void
    {
        $this->incomeStreams[] = ['ownerId' => $this->firstPersonId(), 'type' => 'rental', 'grossAnnual' => '', 'taxable' => true, 'inflationLinked' => true, 'startAge' => '', 'endAge' => ''];
    }

    public function removeIncome(int $i): void
    {
        unset($this->incomeStreams[$i]);
        $this->incomeStreams = array_values($this->incomeStreams);
    }

    public function addOneOff(): void
    {
        $this->oneOffCosts[] = ['atAge' => '', 'amount' => '', 'label' => ''];
    }

    public function removeOneOff(int $i): void
    {
        unset($this->oneOffCosts[$i]);
        $this->oneOffCosts = array_values($this->oneOffCosts);
    }

    public function save()
    {
        try {
            $this->validate();
        } catch (ValidationException $e) {
            // Land the user on the first step that has a problem, and announce it.
            $this->step = $this->firstStepWithError(array_keys($e->errors()));
            $this->dispatch('validation-failed');

            throw $e;
        }

        $assembled = (new HouseholdAssembler)->assemble($this->formState());

        $household = Household::fromDto($assembled['household'], auth()->id());
        $household->save();

        $scenario = new Scenario([
            'household_id' => $household->id,
            'user_id' => auth()->id(),
            'assumption_set_id' => $this->assumptionSetId,
            'name' => $this->name,
            'variant' => $this->variant,
            'base_tax_year' => $this->baseTaxYear,
            'iht_modelled' => $this->ihtModelled,
            'status' => ScenarioStatus::Ready,
        ]);
        $scenario->setHousingAction($assembled['housingAction']);
        $scenario->save();

        session()->flash('status', 'Forecast saved. Run it to see the results.');

        return redirect()->route('scenarios.results', $scenario);
    }

    public function render(): View
    {
        return view('livewire.scenario-builder', [
            'assumptionSets' => AssumptionSet::orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'steps' => self::STEPS,
            'importProfiles' => array_map(static fn ($p): array => [
                'key' => $p->key(),
                'label' => $p->label(),
                'description' => $p->description(),
                'available' => $p->isAvailable(),
            ], (new ImportRegistry)->all()),
        ])->title('New forecast');
    }

    /**
     * The earliest step that owns any of the errored fields, so a failed save lands the
     * user where the first problem is.
     *
     * @param  list<string>  $erroredFields  dotted paths like "people.0.dob", "housing.salePrice"
     */
    private function firstStepWithError(array $erroredFields): int
    {
        $steps = [];
        foreach ($erroredFields as $field) {
            $top = explode('.', $field)[0];
            $steps[] = self::STEP_OF_FIELD[$top] ?? 1;
        }

        return $steps === [] ? $this->step : min($steps);
    }

    private function formState(): array
    {
        return [
            'householdName' => $this->householdName,
            'region' => $this->region,
            'people' => $this->people,
            'expense' => $this->expense,
            'oneOffCosts' => $this->oneOffCosts,
            'pensions' => $this->pensions,
            'accounts' => $this->accounts,
            'incomeStreams' => $this->incomeStreams,
            'hasProperty' => $this->hasProperty,
            'property' => $this->property,
            'housing' => $this->housing,
        ];
    }

    private function firstPersonId(): string
    {
        return $this->people[0]['id'] ?? 'p1';
    }

    /** Keep person ids contiguous (p1, p2) and repair any ownership reference left dangling. */
    private function resequencePeople(): void
    {
        $this->people = array_values($this->people);
        foreach ($this->people as $i => $person) {
            $this->people[$i]['id'] = 'p'.($i + 1);
        }

        $valid = array_column($this->people, 'id');
        $fallback = $valid[0] ?? 'p1';
        foreach (['pensions', 'accounts', 'incomeStreams'] as $collection) {
            foreach ($this->{$collection} as $i => $row) {
                if (! in_array($row['ownerId'] ?? null, $valid, true)) {
                    $this->{$collection}[$i]['ownerId'] = $fallback;
                }
            }
        }
    }

    private function blankPerson(string $id): array
    {
        return [
            'id' => $id, 'dob' => '', 'sex' => 'female', 'employmentStatus' => 'retired',
            'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => '',
        ];
    }

    private function blankPension(string $subtype): array
    {
        return [
            'ownerId' => $this->firstPersonId(), 'subtype' => $subtype,
            'currentValue' => '', 'ongoingContribution' => '', 'employerContribution' => '',
            'earliestAccessAge' => '57', 'pclsTakenToDate' => '', 'growthAssumptionOverride' => '', 'withdrawals' => [],
            'accruedAnnualPension' => '', 'normalRetirementAge' => '65', 'revaluationBasis' => 'cpi',
            'escalationInPayment' => 'cpi', 'spousePensionFraction' => '', 'commutationLumpSum' => '', 'commutationFactor' => '',
            'weeklyForecast' => '', 'qualifyingYears' => '', 'deferralWeeks' => '0',
        ];
    }

    private function blankProperty(): array
    {
        return [
            'currentValue' => '', 'ownership' => 'outright', 'everLet' => false,
            'outstandingMortgage' => '', 'runningCosts' => '', 'growthAssumptionOverride' => '', 'ownershipShare' => '',
        ];
    }

    private function blankHousing(): array
    {
        return [
            'salePrice' => '', 'buyPrice' => '', 'annualRent' => '',
            'rentInflationReal' => '', 'movingCosts' => '', 'sellingCostRate' => '',
        ];
    }
}
