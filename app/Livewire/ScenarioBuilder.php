<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ScenarioStatus;
use App\Forecast\HouseholdAssembler;
use App\Models\AssumptionSet;
use App\Models\Household;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
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
            'incomeStreams.*.endAge' => ['nullable', 'integer', 'min:0', 'max:110'],

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
        $this->validate();

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

        session()->flash('status', 'Forecast saved.');

        return redirect()->route('dashboard');
    }

    public function render(): View
    {
        return view('livewire.scenario-builder', [
            'assumptionSets' => AssumptionSet::orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
        ])->title('New forecast');
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
