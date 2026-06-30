<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ScenarioStatus;
use App\Forecast\AssumptionOverrides;
use App\Forecast\BuilderStateDelta;
use App\Forecast\HouseholdAssembler;
use App\Forecast\ScenarioForecaster;
use App\Forecast\WhatIfChanges;
use App\Import\ImportException;
use App\Import\ImportRegistry;
use App\Import\ImportResult;
use App\Import\ReconciliationLine;
use App\Import\SpreadsheetReader;
use App\Models\AssumptionSet;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
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

    /** True when this builder produces a delta-child what-if (creating one, or editing one). */
    public bool $childMode = false;

    /** The base plan a what-if overrides; its effective inputs pre-fill the form. */
    public ?int $parentScenarioId = null;

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
        'variant' => 1, 'assumptionSetId' => 1, 'assumptionOverrides' => 1, 'ihtModelled' => 1, 'people' => 1,
        'pensions' => 2, 'incomeStreams' => 2,
        'accounts' => 3, 'property' => 3, 'hasProperty' => 3,
        'expense' => 4, 'expenseLines' => 4, 'oneOffCosts' => 4,
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

    /**
     * The user's edits to the chosen assumption set's economic figures — a sparse map
     * of {@see AssumptionOverrides::KEYS} => percentage string, empty by default. Each
     * filled key overrides one preset figure into a derived custom set; an empty key
     * keeps the preset (which therefore stays the single source for untouched figures).
     *
     * @var array<string, string>
     */
    public array $assumptionOverrides = [];

    /** @var list<array<string, mixed>> */
    public array $people = [];

    /** @var array<string, mixed> Holds the survivor factor; essential/discretionary are derived from the lines now. */
    public array $expense = ['essential' => '', 'discretionary' => '', 'survivorFactor' => '70'];

    /**
     * The 3-tier spending lines — the source of truth for spend (Phase C1). Each:
     * {id, label, amount (annual £), category ∈ essential|discretionary|self_investment,
     * savedAsAsset (self-investment only — true = builds net worth, not spend)}.
     *
     * @var list<array<string, mixed>>
     */
    public array $expenseLines = [];

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

    public function mount(?Scenario $scenario = null, bool $asChild = false): void
    {
        $this->people = [$this->blankPerson('p1')];
        $this->property = $this->blankProperty();
        $this->housing = $this->blankHousing();
        $this->assumptionSetId = AssumptionSet::where('is_default', true)->value('id');

        if ($scenario !== null && $scenario->exists) {
            abort_unless($scenario->user_id === auth()->id(), 403);

            if ($asChild || request()->routeIs('scenarios.child')) {
                // Creating a what-if of $scenario (the base): the form is the full builder
                // pre-filled from the base's effective inputs, and whatever the user
                // changes becomes the child's override on save. No draft is auto-saved.
                $this->childMode = true;
                $this->parentScenarioId = $scenario->id;
                $this->loadState($scenario->effectiveBuilderState());
                $this->name = $this->childName($scenario->name);
                $this->step = 1;

                return;
            }

            // Editing a saved forecast (a base or a what-if), pre-filled from its
            // effective form-state (the single source of truth).
            $this->editing = true;
            $this->scenarioId = $scenario->id;
            if ($scenario->isChild()) {
                $this->childMode = true;
                $this->parentScenarioId = $scenario->parent_scenario_id;
            }
            $this->loadState($scenario->effectiveBuilderState());

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
            // Editable economic assumptions: each is an optional override of the chosen
            // preset's figure (empty = keep the preset). Real growth rates may be negative;
            // inflation and the income yield cannot. Loose bounds keep an obvious typo out
            // without second-guessing a deliberate stress test.
            'assumptionOverrides.investmentGrowth' => ['nullable', 'numeric', 'between:-10,30'],
            'assumptionOverrides.inflation' => ['nullable', 'numeric', 'between:0,30'],
            'assumptionOverrides.houseGrowth' => ['nullable', 'numeric', 'between:-15,30'],
            'assumptionOverrides.rentGrowth' => ['nullable', 'numeric', 'between:-15,30'],
            'assumptionOverrides.salaryGrowth' => ['nullable', 'numeric', 'between:-15,30'],
            'assumptionOverrides.incomeYield' => ['nullable', 'numeric', 'between:0,30'],

            'people' => ['required', 'array', 'min:1', 'max:2'],
            'people.*.dob' => ['required', 'date', 'before:today'],
            'people.*.sex' => ['required', Rule::in(['male', 'female'])],
            'people.*.employmentStatus' => ['required', Rule::in(['employed', 'self_employed', 'retired', 'not_working'])],
            'people.*.grossSalary' => [...$money, 'required_if:people.*.employmentStatus,employed,self_employed'],
            'people.*.salaryGrowth' => $rate,
            'people.*.plannedRetirementAge' => ['nullable', 'integer', 'min:50', 'max:80'],
            'people.*.niCategory' => ['nullable', 'string', 'max:2'],
            // Lifespan what-if (optional): peer = cohort-table average; fixed_age needs an
            // age, offset_years a ± year shift. The range spans both uses; the mortality
            // grid clamps anything extreme (ages 50–110), so a loose bound is safe.
            'people.*.longevityMode' => ['nullable', Rule::in(['peer', 'fixed_age', 'offset_years'])],
            'people.*.longevityValue' => ['nullable', 'integer', 'min:-25', 'max:110', 'required_if:people.*.longevityMode,fixed_age,offset_years'],

            'expense.survivorFactor' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Spending is entered as 3-tier line items (the source of truth); the
            // essential/discretionary totals are derived from them, never stored apart.
            'expenseLines' => ['required', 'array', 'min:1'],
            'expenseLines.*.label' => ['nullable', 'string', 'max:255'],
            'expenseLines.*.amount' => $moneyReq,
            'expenseLines.*.category' => ['required', Rule::in(['essential', 'discretionary', 'self_investment'])],
            'expenseLines.*.savedAsAsset' => ['boolean'],
            // The per-line cost condition: blank = auto-classify by label, else an explicit override.
            'expenseLines.*.condition' => ['nullable', Rule::in(['', 'always', 'while_owning_home', 'while_working'])],
            // A line switched off is excluded from the forecast but kept so it can be switched back on.
            'expenseLines.*.included' => ['boolean'],

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
            // Selling costs are a set of named components, each entered as a % of the sale
            // (basis "percent") or a flat £ (basis "fixed"). A blank line just costs nothing.
            'housing.sellingCosts' => ['nullable', 'array'],
            'housing.sellingCosts.*.label' => ['nullable', 'string', 'max:255'],
            'housing.sellingCosts.*.basis' => ['nullable', Rule::in(['percent', 'fixed'])],
            'housing.sellingCosts.*.value' => ['nullable', 'numeric', 'min:0'],
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
        if ($result->expenseLines !== []) {
            // The profile read per-line detail: those 3-tier lines are the source of truth.
            // Assign stable ids here (the profile leaves them out — id assignment is the
            // builder's job, gotcha N).
            $this->expenseLines = array_map(fn (array $l): array => [
                'id' => $this->newRowId(),
                'label' => (string) ($l['label'] ?? ''),
                'amount' => (string) ($l['amount'] ?? ''),
                'category' => (string) ($l['category'] ?? 'essential'),
                'savedAsAsset' => (bool) ($l['savedAsAsset'] ?? false),
                'included' => true,
            ], $result->expenseLines);
        } elseif ($result->expense !== []) {
            // Only category totals: seed the 3-tier editor with two generic lines from them.
            $this->expense = array_merge($this->expense, $result->expense);
            $this->expenseLines = [];
            $this->seedExpenseLinesFromFlat();
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
            // Each aggregated total set beside the sheet's own independent figure, so a
            // double-count or a dropped line is a visible failure in the panel, not silent
            // (CLAUDE.md data-layer integrity rule). Flattened to arrays for the public prop.
            'reconciliation' => array_map(
                fn (ReconciliationLine $r): array => $r->toArray(),
                $result->reconciliation,
            ),
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
        // Editing a saved forecast or building a what-if must not auto-save: an edit's
        // changes land only on an explicit Save (gotcha D), and a what-if is a focused
        // tweak with no draft of its own.
        if (auth()->id() === null || $this->editing || $this->childMode) {
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

        $this->normaliseRowIds();
        $this->seedExpenseLinesFromFlat();
        $this->seedSellingCostsFromRate();

        // Every spend line carries an explicit `included` flag so its on/off checkbox binds to a
        // real boolean; a line saved before the toggle existed (no flag) defaults to included.
        foreach ($this->expenseLines as $i => $line) {
            $this->expenseLines[$i]['included'] = ($line['included'] ?? true) !== false;
        }
    }

    /**
     * Backfill the selling-cost components for a scenario saved before the breakdown existed
     * (it carried only the old single `sellingCostRate`), so it opens in the new editor with
     * its total preserved: the old rate becomes the estate-agent component (defaulting to the
     * old 2% when none was set), the other components start empty (they cost nothing, so the
     * total is unchanged). No-op once components exist, and skipped for a what-if child (its
     * selling costs come from its base's effective state). One home per figure — the stale
     * `sellingCostRate` is dropped so the two shapes never coexist.
     */
    private function seedSellingCostsFromRate(): void
    {
        if ($this->childMode || ! empty($this->housing['sellingCosts'])) {
            return;
        }

        $oldRate = trim((string) ($this->housing['sellingCostRate'] ?? ''));
        $this->housing['sellingCosts'] = [
            'estate_agent' => ['label' => 'Estate agent', 'basis' => 'percent', 'value' => $oldRate === '' ? '2' : $oldRate],
            'legal' => ['label' => 'Legal / conveyancing', 'basis' => 'fixed', 'value' => ''],
            'epc_removals' => ['label' => 'EPC & removals', 'basis' => 'fixed', 'value' => ''],
        ];
        unset($this->housing['sellingCostRate']);
    }

    /**
     * Backfill 3-tier spending lines from the legacy flat essential/discretionary totals
     * for a scenario saved before line items existed (or just imported as totals), so it
     * opens in the new line-item editor with nothing lost. No-op once lines are present.
     */
    private function seedExpenseLinesFromFlat(): void
    {
        if ($this->expenseLines !== []) {
            return;
        }

        $seeded = [];
        foreach (['essential' => 'Essential spending', 'discretionary' => 'Discretionary spending'] as $category => $label) {
            $amount = $this->expense[$category] ?? '';
            if ($amount !== '' && $amount !== null) {
                $seeded[] = ['id' => $this->newRowId(), 'label' => $label, 'amount' => (string) $amount, 'category' => $category, 'savedAsAsset' => false, 'included' => true];
            }
        }

        $this->expenseLines = $seeded;
    }

    /**
     * Give every list row a stable id, backfilling any saved before ids existed, so a
     * delta-child override can always target a row by id rather than its position
     * (gotcha N). People keep their p1/p2 ids; assignment is idempotent.
     */
    private function normaliseRowIds(): void
    {
        foreach (['pensions', 'accounts', 'incomeStreams', 'oneOffCosts', 'expenseLines'] as $collection) {
            foreach ($this->{$collection} as $i => $row) {
                if (($row['id'] ?? '') === '') {
                    $this->{$collection}[$i]['id'] = $this->newRowId();
                }
            }
        }

        foreach ($this->pensions as $pi => $pension) {
            foreach ($pension['withdrawals'] ?? [] as $wi => $withdrawal) {
                if (($withdrawal['id'] ?? '') === '') {
                    $this->pensions[$pi]['withdrawals'][$wi]['id'] = $this->newRowId();
                }
            }
        }
    }

    /** A stable, collision-free id for a freshly added list row. */
    private function newRowId(): string
    {
        return (string) Str::uuid();
    }

    /** Everything needed to restore the builder exactly, including which step the user was on. */
    private function builderState(): array
    {
        // When line items are present they are the sole source of spend; clear the flat
        // essential/discretionary so no stale total is stored beside them (one home per
        // figure). The survivor factor stays — it is not a line.
        $expense = $this->expense;
        if ($this->expenseLines !== []) {
            $expense['essential'] = '';
            $expense['discretionary'] = '';
        }

        // Selling costs are the component breakdown now; never store the legacy single rate
        // beside it, so the two shapes can't coexist and drift (one home per figure).
        $housing = $this->housing;
        if (! empty($housing['sellingCosts'])) {
            unset($housing['sellingCostRate']);
        }

        // Store a line's `included` flag only when it is switched OFF (false); an included line
        // (the default) omits the flag, so a scenario predating the toggle and a what-if that
        // changes nothing record no spurious delta — sparse, mirroring assumptionOverrides.
        $expenseLines = array_map(static function (array $line): array {
            if (($line['included'] ?? true) !== false) {
                unset($line['included']);
            }

            return $line;
        }, $this->expenseLines);

        $state = [
            'step' => $this->step,
            'name' => $this->name,
            'householdName' => $this->householdName,
            'region' => $this->region,
            'baseTaxYear' => $this->baseTaxYear,
            'variant' => $this->variant,
            'ihtModelled' => $this->ihtModelled,
            'assumptionSetId' => $this->assumptionSetId,
            'people' => $this->people,
            'expense' => $expense,
            'expenseLines' => $expenseLines,
            'oneOffCosts' => $this->oneOffCosts,
            'pensions' => $this->pensions,
            'accounts' => $this->accounts,
            'incomeStreams' => $this->incomeStreams,
            'hasProperty' => $this->hasProperty,
            'property' => $this->property,
            'housing' => $housing,
        ];

        // Carry the edited assumptions only when the user actually changed one — an untouched
        // assumption keeps following the preset (one home per figure, never the preset's value
        // stored back), and the key is omitted entirely so a what-if child records no delta for it.
        $assumptionOverrides = AssumptionOverrides::sparse($this->assumptionOverrides);
        if ($assumptionOverrides !== []) {
            $state['assumptionOverrides'] = $assumptionOverrides;
        }

        return $state;
    }

    /**
     * The inputs whose current value differs from the base plan, mapped to the base value
     * they diverged from (formatted for display) — only when building a what-if (childMode).
     * Keyed by the form-state leaf path, **index-based** so the keys match each input's
     * `wire:model`; a bundled script rings each changed input and shows its base value ("was
     * £18,000") so the user sees what this what-if changes and what it changed from. A pure
     * read; the auto-generated name and the wizard step are not "changes" to surface.
     *
     * @return array<string, string> wire:model path => formatted base value
     */
    public function changedFromBase(): array
    {
        if (! $this->childMode || $this->parentScenarioId === null) {
            return [];
        }

        $base = Scenario::where('user_id', auth()->id())->find($this->parentScenarioId)?->effectiveBuilderState() ?? [];

        $changes = [];
        self::collectChangedLeaves($base, $this->builderState(), '', $changes);
        unset($changes['name'], $changes['step']);

        $out = [];
        foreach ($changes as $path => $baseValue) {
            $segments = explode('.', (string) $path);
            $out[$path] = WhatIfChanges::formatValue((string) end($segments), $baseValue);
        }

        return $out;
    }

    /**
     * Walk $current against $base by position, collecting each scalar leaf that differs as
     * `dot-path => base value` (a value the base lacks counts as different, base value null).
     * List rows are walked by index, matching the inputs' `wire:model` paths — a what-if child
     * cannot reorder, add or remove rows, so positions align with the base.
     *
     * @param  array<string, mixed>  $changes
     */
    private static function collectChangedLeaves(mixed $base, mixed $current, string $prefix, array &$changes): void
    {
        if (! is_array($current)) {
            if ($base !== $current) {
                $changes[$prefix] = $base;
            }

            return;
        }

        foreach ($current as $key => $value) {
            $baseValue = is_array($base) ? ($base[$key] ?? null) : null;
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            self::collectChangedLeaves($baseValue, $value, $path, $changes);
        }
    }

    /**
     * A cheap one-path deterministic forecast of the inputs as they stand right now, run
     * against a transient {@see Scenario} assembled from the current form-state and never
     * saved (in childMode the component already holds the full effective inputs, so a plain
     * base scenario is the right thing to forecast either way). Single-sourced from the same
     * {@see ScenarioForecaster::deterministic()} the results page and Monte Carlo build on,
     * so the live readouts cannot drift from the full run. Returns null while the inputs are
     * too incomplete to forecast — a half-filled wizard is not a failure. Computed once in
     * {@see render()} and fed to both the live-preview panel and the modelled-death readout.
     */
    private function previewForecast(): ?ForecastResult
    {
        try {
            $scenario = (new Scenario)->fillFromBuilderState($this->builderState());

            return (new ScenarioForecaster)->deterministic($scenario);
        } catch (\Throwable) {
            return null; // not enough valid input yet to forecast — the panel shows the finish-the-fields note
        }
    }

    /**
     * The verdict + end-wealth readout for the live-preview panel, recomputed on every
     * round-trip so changing any input — an assumption, a salary, a spend line — updates it
     * live (the ProjectionLab "edit and watch it move" pattern; the wizard's only on-the-spot
     * feedback before a full Monte Carlo run). Null when the inputs are not yet forecastable,
     * so the panel invites the user to finish rather than show a misleading figure.
     *
     * @return array{lasts: bool, depletionYear: int|null, finalYear: int, essentialsMet: bool, fullSpendMet: bool, usable: string, total: string, level: string, verdict: string, spendNote: string}|null
     */
    private function previewReadout(?ForecastResult $result): ?array
    {
        if ($result === null) {
            return null;
        }

        $lasts = $result->depletionCalendarYear === null;
        $essentialsMet = $result->essentialsAlwaysMet;
        $fullSpendMet = $result->fullSpendAlwaysMet;

        return [
            'lasts' => $lasts,
            'depletionYear' => $result->depletionCalendarYear,
            'finalYear' => $result->finalCalendarYear,
            'essentialsMet' => $essentialsMet,
            'fullSpendMet' => $fullSpendMet,
            'usable' => $result->terminalUsableWealth->format(),
            'total' => $result->terminalTotalWealth->format(),
            'level' => $lasts && $essentialsMet ? 'good' : 'warn',
            'verdict' => $lasts
                ? "On these figures, the money lasts to {$result->finalCalendarYear}."
                : "On these figures, the money runs short in {$result->depletionCalendarYear}.",
            'spendNote' => match (true) {
                $fullSpendMet => 'Essential and discretionary spending met every year.',
                $essentialsMet => 'Essentials met every year; discretionary spending falls short in some years.',
                default => 'Essential spending falls short in some years.',
            },
        ];
    }

    /**
     * The modelled calendar year and age at death for each person under the current lifespan
     * levers, so the builder can show what "peer" / "+10 years" actually resolves to (the
     * plan's "surface the lever and show the resulting age"). Keyed by person id, read from
     * the same forecast as the panel ({@see ForecastResult::$deathCalendarYears}, which the
     * engine keys by the same person id the form-state uses); age = death year − birth year.
     * Empty while the inputs are not forecastable. Education only, never a recommendation.
     *
     * @return array<string, array{age: int, year: int}>
     */
    private function modelledDeaths(?ForecastResult $result): array
    {
        if ($result === null) {
            return [];
        }

        $out = [];
        foreach ($this->people as $person) {
            $id = $person['id'] ?? null;
            $dob = (string) ($person['dob'] ?? '');
            $deathYear = $id !== null ? ($result->deathCalendarYears[$id] ?? null) : null;
            if ($deathYear === null || strlen($dob) < 4) {
                continue;
            }

            $out[$id] = ['age' => $deathYear - (int) substr($dob, 0, 4), 'year' => $deathYear];
        }

        return $out;
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
        $this->pensions[$pi]['withdrawals'][] = ['id' => $this->newRowId(), 'kind' => 'pcls', 'amount' => '', 'atAge' => ''];
    }

    public function removeWithdrawal(int $pi, int $wi): void
    {
        unset($this->pensions[$pi]['withdrawals'][$wi]);
        $this->pensions[$pi]['withdrawals'] = array_values($this->pensions[$pi]['withdrawals']);
    }

    public function addAccount(): void
    {
        $this->accounts[] = ['id' => $this->newRowId(), 'ownerId' => $this->firstPersonId(), 'type' => 'isa', 'balance' => '', 'unrealisedGain' => '', 'yield' => ''];
    }

    public function removeAccount(int $i): void
    {
        unset($this->accounts[$i]);
        $this->accounts = array_values($this->accounts);
    }

    public function addIncome(): void
    {
        $this->incomeStreams[] = ['id' => $this->newRowId(), 'ownerId' => $this->firstPersonId(), 'type' => 'rental', 'grossAnnual' => '', 'taxable' => true, 'inflationLinked' => true, 'startAge' => '', 'endAge' => ''];
    }

    public function removeIncome(int $i): void
    {
        unset($this->incomeStreams[$i]);
        $this->incomeStreams = array_values($this->incomeStreams);
    }

    /**
     * For each spend line, a plain-English description of the condition it would auto-classify
     * to from its label (when "Applies" is left on Auto), so the inferred behaviour is visible
     * before the user overrides it. Single source: the same {@see HouseholdAssembler::autoCondition}
     * the forecast uses. Keyed by line index to match the inputs' wire:model.
     *
     * @return array<int, string>
     */
    private function conditionHints(): array
    {
        $labels = [
            'always' => 'charged every year',
            'while_owning_home' => 'charged only while you own this home',
            'while_working' => 'charged only while you are working',
        ];

        $hints = [];
        foreach ($this->expenseLines as $i => $line) {
            $hints[$i] = $labels[HouseholdAssembler::autoCondition($line)] ?? '';
        }

        return $hints;
    }

    /**
     * Live tier subtotals for the Spending step, derived from the lines for display
     * (the authoritative exact-pence derivation lives in {@see HouseholdAssembler}).
     * Essential = essential lines; discretionary = discretionary + *spent* self-
     * investment; saved = *saved* self-investment (a contribution, not spend).
     *
     * @return array{essential: float, discretionary: float, saved: float, total: float}
     */
    private function expenseTotals(): array
    {
        $sum = function (string $category, ?bool $saved = null): float {
            $total = 0.0;
            foreach ($this->expenseLines as $line) {
                if (($line['included'] ?? true) === false) {
                    continue; // a switched-off line is excluded from the forecast, so from the totals too
                }
                if (($line['category'] ?? '') !== $category) {
                    continue;
                }
                if ($saved !== null && (bool) ($line['savedAsAsset'] ?? false) !== $saved) {
                    continue;
                }
                $total += (float) ($line['amount'] ?? 0);
            }

            return $total;
        };

        $essential = $sum('essential');
        $discretionary = $sum('discretionary') + $sum('self_investment', false);

        return [
            'essential' => $essential,
            'discretionary' => $discretionary,
            'saved' => $sum('self_investment', true),
            'total' => $essential + $discretionary,
        ];
    }

    public function addExpenseLine(string $category = 'essential'): void
    {
        $this->expenseLines[] = [
            'id' => $this->newRowId(), 'label' => '', 'amount' => '',
            'category' => in_array($category, ['essential', 'discretionary', 'self_investment'], true) ? $category : 'essential',
            'savedAsAsset' => false, 'condition' => '', 'included' => true,
        ];
    }

    public function removeExpenseLine(int $i): void
    {
        unset($this->expenseLines[$i]);
        $this->expenseLines = array_values($this->expenseLines);
    }

    public function addOneOff(): void
    {
        $this->oneOffCosts[] = ['id' => $this->newRowId(), 'atAge' => '', 'amount' => '', 'label' => ''];
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
        $scenario->status = ScenarioStatus::Ready;

        if ($this->childMode) {
            if (! $this->persistAsChild($scenario)) {
                return; // a structural change cannot be stored as a delta; stay put
            }
        } else {
            $scenario->fillFromBuilderState($this->builderState());
            $scenario->save();
        }

        $this->scenarioId = $scenario->id;

        // Editing changed the inputs, so any earlier run is now from stale inputs — drop it
        // (cascading to its results) and prompt a fresh run (gotcha B).
        if ($hadRuns) {
            $scenario->simulationRuns()->delete();
        }

        // A base edit changes every child's effective inputs too: refresh their projected
        // columns and drop their now-stale runs, so the base stays the single source.
        $this->refreshChildren($scenario);

        session()->flash('status', $this->editing
            ? 'Forecast updated. Run it again to refresh the results.'
            : 'Forecast saved. Run it to see the results.');

        return redirect()->route('scenarios.results', $scenario);
    }

    /**
     * Persist $scenario as a delta-child of its base: store only the overrides (the
     * leaves the user changed against the base's effective inputs), never a full copy.
     * Returns false — leaving the form intact with an error — when the user added or
     * removed a list row, which a delta cannot represent without forking the base.
     */
    private function persistAsChild(Scenario $scenario): bool
    {
        $base = Scenario::where('user_id', auth()->id())->findOrFail($this->parentScenarioId);
        // The step is UI position only — never part of a what-if's delta, so strip it
        // from both sides (a child has no draft of its own to resume onto a step).
        $effectiveBase = $this->withoutEphemeral($base->effectiveBuilderState());
        $edited = $this->withoutEphemeral($this->builderState());

        if (BuilderStateDelta::structurallyDiffers($effectiveBase, $edited)) {
            $this->addError('childStructure', 'A what-if only changes values on your base plan. To add or remove a person, pension, account or income, edit the base plan itself or start a new forecast.');
            $this->dispatch('validation-failed');

            return false;
        }

        $scenario->parent_scenario_id = $base->id;
        $scenario->overrides = BuilderStateDelta::diff($effectiveBase, $edited);
        $scenario->builder_state = [];
        $scenario->projectFrom($edited);
        $scenario->save();

        return true;
    }

    /** Refresh each child's projected columns from its (now-changed) effective state and drop its stale runs. */
    private function refreshChildren(Scenario $scenario): void
    {
        foreach ($scenario->children()->get() as $child) {
            $child->projectFrom($child->effectiveBuilderState())->save();
            $child->simulationRuns()->delete();
        }
    }

    /** A sensible default name for a new what-if, derived from its base. */
    private function childName(string $baseName): string
    {
        return trim($baseName) === '' ? 'What-if' : "{$baseName} — what-if";
    }

    /**
     * Form-state with UI-only keys removed, so a what-if's delta carries forecast
     * inputs alone (the step a base happened to be saved on is not an override).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function withoutEphemeral(array $state): array
    {
        unset($state['step']);

        return $state;
    }

    public function render(): View
    {
        // One cheap deterministic forecast per round-trip, feeding both the live-preview panel
        // and the per-person modelled-death readout (so the lifespan lever shows what it resolves to).
        $previewForecast = $this->previewForecast();

        return view('livewire.scenario-builder', [
            'assumptionSets' => AssumptionSet::orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            // The editable economic assumptions, in {@see AssumptionOverrides::KEYS} order:
            // the label/note are presentation; the keys are the single source the override
            // map and apply() share, so the form and the engine can't list different figures.
            'assumptionFields' => [
                ['key' => 'investmentGrowth', 'label' => 'Investment growth (blended, real)', 'note' => 'for invested pots and proceeds'],
                ['key' => 'inflation', 'label' => 'Inflation (CPI)', 'note' => 'figures are shown in today\'s money'],
                ['key' => 'houseGrowth', 'label' => 'House price growth (real)', 'note' => 'a year above inflation'],
                ['key' => 'rentGrowth', 'label' => 'Rent growth (real)', 'note' => 'a year above inflation'],
                ['key' => 'salaryGrowth', 'label' => 'Salary growth (real)', 'note' => 'a year above inflation'],
                ['key' => 'incomeYield', 'label' => 'Investment income yield (nominal)', 'note' => 'the part of the return paid out and taxed each year'],
            ],
            // The chosen preset's current figures, so each editable assumption shows the
            // value it would override as its placeholder (and updates when the set changes).
            'assumptionDefaults' => AssumptionOverrides::presetFigures(
                $this->selectedAssumptionSet(),
                PortfolioAllocation::cautious40_60(),
            ),
            // For a what-if: the inputs whose value differs from the base, mapped to the base
            // value they diverged from, so each can be highlighted and show its base value
            // (matched to inputs by their wire:model path, client-side). Empty for a base.
            'changedFromBase' => $this->changedFromBase(),
            // A cheap one-path deterministic readout of the forecast as it stands, so editing
            // any input updates a live verdict + end-wealth panel before the full run, and the
            // per-person modelled death age/year so the lifespan lever shows what it resolves to.
            'livePreview' => $this->previewReadout($previewForecast),
            'modelledDeaths' => $this->modelledDeaths($previewForecast),
            'steps' => self::STEPS,
            'expenseTotals' => $this->expenseTotals(),
            // What each spend line's "Applies" Auto setting infers from its label, shown beside it.
            'conditionHints' => $this->conditionHints(),
            'importProfiles' => array_map(static fn ($p): array => [
                'key' => $p->key(),
                'label' => $p->label(),
                'description' => $p->description(),
                'available' => $p->isAvailable(),
            ], (new ImportRegistry)->all()),
        ])->title('New forecast');
    }

    /**
     * The engine DTO for the currently-selected assumption set (the chosen preset, or the
     * engine default when none is picked). Used only to show the preset's figures as the
     * editable assumptions' placeholders — the forecast itself resolves the set through
     * {@see ScenarioForecaster::assumptions()}.
     */
    private function selectedAssumptionSet(): \RetireForecast\FinanceEngine\Dto\AssumptionSet
    {
        $model = $this->assumptionSetId !== null ? AssumptionSet::find($this->assumptionSetId) : null;

        return $model?->toDto() ?? AssumptionSetLibrary::default();
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
            'longevityMode' => 'peer', 'longevityValue' => '',
        ];
    }

    private function blankPension(string $subtype): array
    {
        return [
            'id' => $this->newRowId(), 'ownerId' => $this->firstPersonId(), 'subtype' => $subtype, 'level' => 'amount',
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
            'rentInflationReal' => '', 'movingCosts' => '',
            'sellingCosts' => self::defaultSellingCosts(),
        ];
    }

    /**
     * The default selling-cost breakdown for a new forecast: an estate-agent fee on a % of
     * the sale (how agents quote) plus flat legal/conveyancing and EPC/removals fees (how
     * those quote). Sensible UK assumptions, all editable; each is the value the user starts
     * from and can change, or switch its basis between % and £.
     *
     * @return array<string, array{label: string, basis: string, value: string}>
     */
    private static function defaultSellingCosts(): array
    {
        return [
            'estate_agent' => ['label' => 'Estate agent', 'basis' => 'percent', 'value' => '1.25'],
            'legal' => ['label' => 'Legal / conveyancing', 'basis' => 'fixed', 'value' => '1500'],
            'epc_removals' => ['label' => 'EPC & removals', 'basis' => 'fixed', 'value' => '800'],
        ];
    }
}
