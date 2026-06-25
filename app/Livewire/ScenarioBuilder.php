<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ScenarioStatus;
use App\Import\ImportException;
use App\Import\ImportRegistry;
use App\Import\ImportResult;
use App\Import\SpreadsheetReader;
use App\Models\AssumptionSet;
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
 *
 * Storage is inverted (Phase B): the builder form-state is the single source of truth,
 * persisted as the scenario's encrypted `builder_state`; the engine DTOs are derived
 * from it. The same component creates a new forecast and edits a saved one, and an
 * in-progress build auto-saves as a `draft`-status scenario so leaving never loses work.
 */
#[Layout('components.layouts.app')]
class ScenarioBuilder extends Component
{
    use WithFileUploads;

    /** The scenario row this builder is bound to: a resumable draft, or the forecast being edited. */
    public ?int $scenarioId = null;

    /** True when editing a saved (ready) forecast — auto-save then must not clobber it. */
    public bool $editing = false;

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

    public function mount(?Scenario $scenario = null): void
    {
        $this->people = [$this->blankPerson('p1')];
        $this->property = $this->blankProperty();
        $this->housing = $this->blankHousing();
        $this->assumptionSetId = AssumptionSet::where('is_default', true)->value('id');

        if ($scenario !== null && $scenario->exists) {
            // Editing a saved forecast: owner-scoped, pre-filled from its stored form-state.
            abort_unless($scenario->user_id === auth()->id(), 403);
            $this->editing = true;
            $this->scenarioId = $scenario->id;
            $this->loadState($scenario->builder_state ?? []);

            return;
        }

        // A new forecast: resume the user's in-progress draft if one was left unsaved.
        $this->loadDraft();
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

    /** Free navigation between steps; clamped to the valid range. The draft is saved on each move. */
    public function goToStep(int $step): void
    {
        $this->step = max(1, min(count(self::STEPS), $step));
        $this->saveDraft();
        $this->dispatch('step-changed');
    }

    /**
     * Persist the in-progress form state as a draft scenario (encrypted) so leaving never
     * loses work. Editing a saved forecast is skipped: an auto-save must not silently
     * overwrite a ready scenario — that only happens on an explicit Save (gotcha D).
     */
    public function saveDraft(): void
    {
        if (auth()->id() === null || $this->editing) {
            return;
        }

        $draft = ($this->scenarioId !== null
            ? Scenario::where('user_id', auth()->id())->find($this->scenarioId)
            : null) ?? $this->currentDraft() ?? new Scenario;

        $draft->user_id = auth()->id();
        $draft->fillFromBuilderState($this->builderState());
        $draft->status = ScenarioStatus::Draft;
        $draft->save();

        $this->scenarioId = $draft->id;
    }

    /** Save the draft and return to the dashboard — leaving never loses work. */
    public function leave()
    {
        $this->saveDraft();

        return redirect()->route('dashboard');
    }

    /** Throw the in-progress forecast away entirely (the only path that deletes a draft on purpose). */
    public function discardDraft()
    {
        if (auth()->id() !== null) {
            Scenario::where('user_id', auth()->id())->where('status', ScenarioStatus::Draft)->delete();
        }

        return redirect()->route('dashboard');
    }

    /** Restore the user's last in-progress draft, if one exists. */
    private function loadDraft(): void
    {
        $draft = $this->currentDraft();
        if ($draft === null) {
            return;
        }

        $this->scenarioId = $draft->id;
        $this->loadState($draft->builder_state ?? []);
    }

    /** The user's single in-progress draft scenario, if any. */
    private function currentDraft(): ?Scenario
    {
        if (auth()->id() === null) {
            return null;
        }

        return Scenario::where('user_id', auth()->id())
            ->where('status', ScenarioStatus::Draft)
            ->latest()
            ->first();
    }

    /** Apply a stored form-state map onto the matching component properties. */
    private function loadState(array $state): void
    {
        foreach ($state as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /** Everything needed to restore the builder exactly, including which step the user was on. */
    private function builderState(): array
    {
        return [
            'step' => $this->step,
            'name' => $this->name,
            'householdName' => $this->householdName,
            'region' => $this->region,
            'baseTaxYear' => $this->baseTaxYear,
            'variant' => $this->variant,
            'ihtModelled' => $this->ihtModelled,
            'assumptionSetId' => $this->assumptionSetId,
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
        $pension = $this->blankPension($subtype);

        // A State pension defaults to the full new flat rate, pre-filled from the tax year —
        // most people get the full amount, so this is zero-effort; they can switch to a
        // specific figure or qualifying years if theirs differs.
        if ($subtype === 'state') {
            $pension['level'] = 'full';
            $pension['weeklyForecast'] = $this->fullStatePensionWeekly();
        }

        $this->pensions[] = $pension;
    }

    /** The full new State Pension weekly rate for the chosen base year, as a pounds string. */
    public function fullStatePensionWeekly(): string
    {
        return TaxYearRegistry::for($this->baseTaxYear, RegionProfile::EnglandWalesNi)
            ->statePension->newStatePensionWeekly->toDecimal();
    }

    /** When a State pension's "level" changes, derive (or clear) its weekly figure so the user need not. */
    public function updatedPensions(mixed $value, ?string $key): void
    {
        if ($key === null || ! str_ends_with($key, '.level')) {
            return;
        }

        $i = (int) explode('.', $key)[0];
        if ($value === 'full') {
            $this->pensions[$i]['weeklyForecast'] = $this->fullStatePensionWeekly();
            $this->pensions[$i]['qualifyingYears'] = '';
        } elseif ($value === 'years') {
            $this->pensions[$i]['weeklyForecast'] = '';
        }
        // 'amount' leaves whatever the user typed.
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

        // The bound row: the forecast being edited, the resumed draft promoted to ready,
        // or a fresh scenario. Owner-scoped, so a tampered id cannot target another user.
        $scenario = $this->scenarioId !== null
            ? Scenario::where('user_id', auth()->id())->findOrFail($this->scenarioId)
            : new Scenario;

        $hadRuns = $scenario->exists && $scenario->simulationRuns()->exists();

        $scenario->user_id = auth()->id();
        $scenario->fillFromBuilderState($this->builderState());
        $scenario->status = ScenarioStatus::Ready;
        $scenario->save();
        $this->scenarioId = $scenario->id;

        // Editing changed the inputs, so any earlier run is now from stale inputs — drop it
        // (cascading to its results) and prompt a fresh run (gotcha B).
        if ($hadRuns) {
            $scenario->simulationRuns()->delete();
        }

        session()->flash('status', $this->editing
            ? 'Forecast updated. Run it again to refresh the results.'
            : 'Forecast saved. Run it to see the results.');

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
            'id' => $id, 'name' => '', 'dob' => '', 'sex' => 'female', 'employmentStatus' => 'retired',
            'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => '',
        ];
    }

    private function blankPension(string $subtype): array
    {
        return [
            'ownerId' => $this->firstPersonId(), 'subtype' => $subtype, 'level' => 'amount',
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
