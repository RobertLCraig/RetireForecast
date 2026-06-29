@php
    $field = 'mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500';
    $label = 'block text-sm font-medium text-gray-700';
    $section = 'rounded-lg border border-gray-200 bg-white p-5';
    $legend = 'text-lg font-semibold text-gray-900';
    $personName = fn ($p, $i) => trim((string) ($p['name'] ?? '')) !== '' ? $p['name'] : 'Person '.($i + 1);
    $ownerOptions = collect($people)->map(fn ($p, $i) => ['id' => $p['id'], 'label' => $personName($p, $i)])->all();
    $lastStep = count($steps);
@endphp

<div class="space-y-6"
    x-data
    x-on:step-changed.window="$nextTick(() => $refs.stepHeading?.focus())"
    x-on:validation-failed.window="$nextTick(() => $refs.errorSummary?.focus())">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900">
            @if ($childMode){{ $editing ? 'Edit what-if' : 'Create a what-if' }}@elseif ($editing)Edit forecast@else New forecast @endif
        </h1>
        <p class="mt-1 text-sm text-gray-600">
            @if ($childMode)
                This is a what-if of your base plan, pre-filled from it. Change the values you want to test —
                anything you leave alone tracks the base plan. Saving stores only your changes.
            @else
                Enter the household and the housing decision to compare. Figures are stored encrypted and private to your
                account. This tool illustrates consequences; it does not recommend a course of action.
            @endif
        </p>
    </div>

    @error('childStructure')
        <div role="alert" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ $message }}
        </div>
    @enderror

    {{-- Optional: pre-fill from a budget spreadsheet. Sits outside the form so the file
         input never triggers a save; the file is read once and not stored. Only for a
         fresh forecast — a what-if or an edit starts from existing inputs. --}}
    @unless ($childMode || $editing)
    <details open class="rounded-lg border-2 border-blue-200 bg-blue-50 p-5">
        <summary class="cursor-pointer text-lg font-semibold text-blue-900">⬆ Import from a spreadsheet (optional) — start here if you have one</summary>
        <div class="mt-4 space-y-3">
            <p class="text-sm text-gray-600">Pre-fill your spending and salary from a budget spreadsheet, then review and complete the rest by hand. The file is read once and not stored.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="importProfile" class="{{ $label }}">Spreadsheet type</label>
                    <select id="importProfile" wire:model="importProfile" class="{{ $field }}">
                        @foreach ($importProfiles as $p)
                            <option value="{{ $p['key'] }}" @if (! $p['available']) disabled @endif>
                                {{ $p['label'] }}{{ $p['available'] ? '' : ' — coming soon' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">{{ collect($importProfiles)->firstWhere('key', $importProfile)['description'] ?? '' }}</p>
                </div>
                <div>
                    <label for="importFile" class="{{ $label }}">File (.xlsx or .csv)</label>
                    <input id="importFile" type="file" accept=".csv,.xlsx,.xls" wire:model="importFile" class="mt-1 block w-full text-sm" @error('importFile') aria-invalid="true" aria-describedby="importFile-error" @enderror>
                    @error('importFile') <p id="importFile-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
            @if (count($importSheets) > 1)
                <div>
                    <label for="importSheet" class="{{ $label }}">Tab to import</label>
                    <select id="importSheet" wire:model="importSheet" class="{{ $field }} sm:max-w-sm">
                        @foreach ($importSheets as $name)
                            <option value="{{ $name }}">{{ $name === '' ? 'Sheet 1' : $name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">This workbook has several tabs — choose the scenario to read.</p>
                </div>
            @endif

            <button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import,importFile"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-100 disabled:opacity-50">
                <span wire:loading.remove wire:target="import">Import</span>
                <span wire:loading wire:target="import">Reading…</span>
            </button>

            @if ($importSummary)
                @php($reconMismatch = collect($importSummary['reconciliation'] ?? [])->contains('mismatch', true))
                <div role="status" class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
                    <p class="font-medium">Imported. Review the figures below and complete the rest of the wizard.</p>
                    @if (! empty($importSummary['filled']))
                        <p class="mt-2 font-medium">Filled in for you:</p>
                        <ul class="list-disc pl-5">@foreach ($importSummary['filled'] as $f)<li>{{ $f }}</li>@endforeach</ul>
                    @endif
                    @if (! empty($importSummary['missing']))
                        <p class="mt-2 font-medium">Still needs your input:</p>
                        <ul class="list-disc pl-5">@foreach ($importSummary['missing'] as $m)<li>{{ $m }}</li>@endforeach</ul>
                    @endif
                    @if (! empty($importSummary['notes']))
                        <ul class="mt-2 list-disc pl-5 text-xs text-green-800">@foreach ($importSummary['notes'] as $n)<li>{{ $n }}</li>@endforeach</ul>
                    @endif
                </div>

                {{-- Reconciliation: every imported total set beside the sheet's own figure, so a
                     double-count or a dropped line shows up as a visible failure, not silently. --}}
                @if (! empty($importSummary['reconciliation']))
                    <div role="{{ $reconMismatch ? 'alert' : 'status' }}"
                        class="mt-3 rounded-md border px-4 py-3 text-sm {{ $reconMismatch ? 'border-red-300 bg-red-50 text-red-900' : 'border-gray-200 bg-white text-gray-800' }}">
                        <p class="font-medium">Reconciliation — check these totals against your spreadsheet</p>
                        @if ($reconMismatch)
                            <p class="mt-1 font-semibold text-red-800">A figure below does not reconcile with your spreadsheet. Check it before saving.</p>
                        @endif
                        <ul class="mt-2 space-y-2">
                            @foreach ($importSummary['reconciliation'] as $r)
                                <li>
                                    <span class="font-medium text-gray-900">{{ $r['label'] }}:</span>
                                    £{{ $r['imported'] }}/yr
                                    @if ($r['detail'])<span class="text-xs text-gray-500">({{ $r['detail'] }})</span>@endif
                                    <br>
                                    @if ($r['mismatch'])
                                        <span class="font-semibold text-red-700">⚠ Does not reconcile: £{{ $r['imported'] }}/yr is in the form, but your spreadsheet's own figure for this is £{{ $r['stated'] }}/yr. Check your spreadsheet before saving.</span>
                                    @elseif ($r['stated'] !== null)
                                        <span class="text-green-800">✓ Reconciles with your spreadsheet's own figure (£{{ $r['stated'] }}/yr).</span>
                                    @else
                                        <span class="text-gray-600">No separate total in the file to cross-check — please verify against your spreadsheet.</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </div>
    </details>
    @endunless

    {{-- Step navigation. Jump to any step freely, or move with Back / Next below. --}}
    <nav aria-label="Forecast steps">
        <ol class="flex flex-wrap gap-2 text-sm">
            @foreach ($steps as $n => $title)
                <li>
                    <button type="button" wire:click="goToStep({{ $n }})"
                        @if ($step === $n) aria-current="step" @endif
                        class="flex items-center gap-2 rounded-full border px-3 py-1.5 {{ $step === $n ? 'border-blue-600 bg-blue-600 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-100' }}">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full text-xs {{ $step === $n ? 'bg-white text-blue-700' : 'bg-gray-200 text-gray-700' }}">{{ $n }}</span>
                        {{ $title }}
                    </button>
                </li>
            @endforeach
        </ol>
    </nav>

    <p class="text-sm text-gray-500">Step {{ $step }} of {{ $lastStep }}</p>
    <h2 id="step-heading" x-ref="stepHeading" tabindex="-1" class="text-xl font-semibold text-gray-900 focus:outline-none">{{ $steps[$step] }}</h2>

    @if ($errors->any())
        <div role="alert" tabindex="-1" x-ref="errorSummary"
            class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 focus:outline-none focus:ring-2 focus:ring-red-500">
            <p class="font-medium">Please fix the following before saving:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <div role="group" aria-labelledby="step-heading" class="space-y-6">

        {{-- Step 1: About this forecast & the people -------------------------------- --}}
        @if ($step === 1)
            <fieldset class="{{ $section }}">
                <legend class="{{ $legend }}">This forecast</legend>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="{{ $label }}">Forecast name</label>
                        <input id="name" type="text" wire:model="name" class="{{ $field }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name') <p id="name-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="householdName" class="{{ $label }}">Household name</label>
                        <input id="householdName" type="text" wire:model="householdName" class="{{ $field }}" @error('householdName') aria-invalid="true" aria-describedby="householdName-error" @enderror>
                        @error('householdName') <p id="householdName-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="region" class="{{ $label }}">Tax region</label>
                        <select id="region" wire:model="region" class="{{ $field }}" @error('region') aria-invalid="true" aria-describedby="region-error" @enderror>
                            <option value="england_wales_ni">England, Wales &amp; Northern Ireland</option>
                            <option value="scotland">Scotland</option>
                        </select>
                        @error('region') <p id="region-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="baseTaxYear" class="{{ $label }}">Base tax year</label>
                        <select id="baseTaxYear" wire:model="baseTaxYear" class="{{ $field }}" @error('baseTaxYear') aria-invalid="true" aria-describedby="baseTaxYear-error" @enderror>
                            <option value="2025-26">2025-26</option>
                            <option value="2026-27">2026-27</option>
                        </select>
                        @error('baseTaxYear') <p id="baseTaxYear-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="variant" class="{{ $label }}">Primary option</label>
                        <select id="variant" wire:model="variant" class="{{ $field }}">
                            <option value="stay_put">Stay put</option>
                            <option value="buy_outright">Sell &amp; buy cheaper outright</option>
                            <option value="rent">Sell &amp; rent</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">All three are run and compared; this is just the headline.</p>
                    </div>
                    <div>
                        <label for="assumptionSetId" class="{{ $label }}">Assumption set</label>
                        <select id="assumptionSetId" wire:model="assumptionSetId" class="{{ $field }}">
                            <option value="">Engine default</option>
                            @foreach ($assumptionSets as $set)
                                <option value="{{ $set->id }}">{{ $set->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="ihtModelled" class="rounded border-gray-300">
                            Model inheritance tax (estate &amp; legacy)
                        </label>
                    </div>
                </div>
            </fieldset>

            {{-- Editable economic assumptions. The set chosen above is a starting point;
                 any figure can be tuned into a custom set. A blank box uses the set's
                 figure (shown as the faint placeholder + named in the hint), so an
                 untouched assumption keeps following the preset. --}}
            <fieldset class="{{ $section }}">
                <legend class="{{ $legend }}">Economic assumptions</legend>
                <p class="mt-1 text-sm text-gray-600">
                    These come from the assumption set above. Leave a box blank to use that set's figure; type a value to use your own. Growth and returns are <strong>real</strong> (a year above inflation).
                </p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ($assumptionFields as $f)
                        <div wire:key="assumption-{{ $f['key'] }}">
                            <label for="assumption-{{ $f['key'] }}" class="{{ $label }}">{{ $f['label'] }}</label>
                            <input id="assumption-{{ $f['key'] }}" type="text" inputmode="decimal"
                                wire:model="assumptionOverrides.{{ $f['key'] }}"
                                placeholder="{{ $assumptionDefaults[$f['key']] ?? '' }}"
                                class="{{ $field }}"
                                @error('assumptionOverrides.'.$f['key']) aria-invalid="true" aria-describedby="assumption-{{ $f['key'] }}-error" @enderror>
                            <p class="mt-1 text-xs text-gray-500">{{ $f['note'] }} · set's figure: {{ $assumptionDefaults[$f['key']] ?? '—' }}%</p>
                            @error('assumptionOverrides.'.$f['key']) <p id="assumption-{{ $f['key'] }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="{{ $section }}">
                <legend class="{{ $legend }}">People</legend>
                @foreach ($people as $i => $person)
                    <div wire:key="person-{{ $i }}" class="mt-4 rounded-md border border-gray-100 bg-gray-50 p-4">
                        <div class="flex items-center justify-between">
                            <h3 class="font-medium text-gray-800">{{ $personName($person, $i) }}</h3>
                            @if (count($people) > 1)
                                <button type="button" wire:click="removePerson({{ $i }})" class="text-sm text-red-700 underline">Remove</button>
                            @endif
                        </div>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <label for="people-{{ $i }}-name" class="{{ $label }}">Name</label>
                                <input id="people-{{ $i }}-name" type="text" wire:model.blur="people.{{ $i }}.name" placeholder="Person {{ $i + 1 }}" class="{{ $field }}">
                                <p class="mt-1 text-xs text-gray-500">Used to label this person through the rest of the form.</p>
                            </div>
                            <div>
                                <label for="people-{{ $i }}-dob" class="{{ $label }}">Date of birth</label>
                                <input id="people-{{ $i }}-dob" type="date" wire:model="people.{{ $i }}.dob" class="{{ $field }}" @error('people.'.$i.'.dob') aria-invalid="true" aria-describedby="people-{{ $i }}-dob-error" @enderror>
                                @error('people.'.$i.'.dob') <p id="people-{{ $i }}-dob-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="people-{{ $i }}-sex" class="{{ $label }}">Sex (for mortality table)</label>
                                <select id="people-{{ $i }}-sex" wire:model="people.{{ $i }}.sex" class="{{ $field }}">
                                    <option value="female">Female</option>
                                    <option value="male">Male</option>
                                </select>
                            </div>
                            <div>
                                <label for="people-{{ $i }}-employmentStatus" class="{{ $label }}">Employment</label>
                                <select id="people-{{ $i }}-employmentStatus" wire:model="people.{{ $i }}.employmentStatus" class="{{ $field }}">
                                    <option value="employed">Employed</option>
                                    <option value="self_employed">Self-employed</option>
                                    <option value="retired">Retired</option>
                                    <option value="not_working">Not working</option>
                                </select>
                            </div>
                            <div>
                                <label for="people-{{ $i }}-grossSalary" class="{{ $label }}">Gross salary (£/yr)</label>
                                <input id="people-{{ $i }}-grossSalary" type="text" inputmode="decimal" wire:model="people.{{ $i }}.grossSalary" class="{{ $field }}" @error('people.'.$i.'.grossSalary') aria-invalid="true" aria-describedby="people-{{ $i }}-grossSalary-error" @enderror>
                                @error('people.'.$i.'.grossSalary') <p id="people-{{ $i }}-grossSalary-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="people-{{ $i }}-salaryGrowth" class="{{ $label }}">Salary growth (%/yr)</label>
                                <input id="people-{{ $i }}-salaryGrowth" type="text" inputmode="decimal" wire:model="people.{{ $i }}.salaryGrowth" class="{{ $field }}">
                            </div>
                            <div>
                                <label for="people-{{ $i }}-plannedRetirementAge" class="{{ $label }}">Planned retirement age</label>
                                <input id="people-{{ $i }}-plannedRetirementAge" type="number" wire:model="people.{{ $i }}.plannedRetirementAge" class="{{ $field }}" @error('people.'.$i.'.plannedRetirementAge') aria-invalid="true" aria-describedby="people-{{ $i }}-plannedRetirementAge-error" @enderror>
                                @error('people.'.$i.'.plannedRetirementAge') <p id="people-{{ $i }}-plannedRetirementAge-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="people-{{ $i }}-longevityMode" class="{{ $label }}">Lifespan assumption</label>
                                <select id="people-{{ $i }}-longevityMode" wire:model.live="people.{{ $i }}.longevityMode" class="{{ $field }}">
                                    <option value="peer">Average for their age (default)</option>
                                    <option value="fixed_age">Assume a specific age at death</option>
                                    <option value="offset_years">Live longer or shorter than average</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500">A what-if on how long this person lives. It shifts only when the money has to last, never any tax figure.</p>
                            </div>
                            @if (($person['longevityMode'] ?? 'peer') !== 'peer')
                                <div>
                                    <label for="people-{{ $i }}-longevityValue" class="{{ $label }}">
                                        {{ ($person['longevityMode'] ?? '') === 'fixed_age' ? 'Assumed age at death' : 'Years vs average (+ longer, − shorter)' }}
                                    </label>
                                    <input id="people-{{ $i }}-longevityValue" type="number" wire:model="people.{{ $i }}.longevityValue" class="{{ $field }}" @error('people.'.$i.'.longevityValue') aria-invalid="true" aria-describedby="people-{{ $i }}-longevityValue-error" @enderror>
                                    @error('people.'.$i.'.longevityValue') <p id="people-{{ $i }}-longevityValue-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
                @if (count($people) < 2)
                    <button type="button" wire:click="addPerson" class="mt-4 rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-100">+ Add a second person</button>
                @endif
            </fieldset>
        @endif

        {{-- Step 2: Pensions & other income ----------------------------------------- --}}
        @if ($step === 2)
            <fieldset class="{{ $section }}">
                <legend class="{{ $legend }}">Pensions</legend>
                @forelse ($pensions as $i => $pension)
                    <div wire:key="pension-{{ $i }}" class="mt-4 rounded-md border border-gray-100 bg-gray-50 p-4">
                        <div class="flex items-center justify-between">
                            <h3 class="font-medium text-gray-800">
                                {{ ['dc' => 'Defined contribution', 'db' => 'Defined benefit', 'state' => 'State pension'][$pension['subtype']] }}
                            </h3>
                            <button type="button" wire:click="removePension({{ $i }})" class="text-sm text-red-700 underline">Remove</button>
                        </div>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <label for="pensions-{{ $i }}-ownerId" class="{{ $label }}">Owner</label>
                                <select id="pensions-{{ $i }}-ownerId" wire:model="pensions.{{ $i }}.ownerId" class="{{ $field }}">
                                    @foreach ($ownerOptions as $o)
                                        <option value="{{ $o['id'] }}">{{ $o['label'] }}</option>
                                    @endforeach
                                </select>
                                @error('pensions.'.$i.'.ownerId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>

                            @if ($pension['subtype'] === 'dc')
                                <div>
                                    <label for="pensions-{{ $i }}-currentValue" class="{{ $label }}">Current pot value (£)</label>
                                    <input id="pensions-{{ $i }}-currentValue" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.currentValue" class="{{ $field }}">
                                    @error('pensions.'.$i.'.currentValue') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-earliestAccessAge" class="{{ $label }}">Earliest access age</label>
                                    <input id="pensions-{{ $i }}-earliestAccessAge" type="number" wire:model="pensions.{{ $i }}.earliestAccessAge" class="{{ $field }}">
                                    @error('pensions.'.$i.'.earliestAccessAge') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-ongoingContribution" class="{{ $label }}">Your contribution (£/yr)</label>
                                    <input id="pensions-{{ $i }}-ongoingContribution" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.ongoingContribution" class="{{ $field }}">
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-employerContribution" class="{{ $label }}">Employer contribution (£/yr)</label>
                                    <input id="pensions-{{ $i }}-employerContribution" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.employerContribution" class="{{ $field }}">
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-growthAssumptionOverride" class="{{ $label }}">Growth override (%/yr, optional)</label>
                                    <input id="pensions-{{ $i }}-growthAssumptionOverride" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.growthAssumptionOverride" class="{{ $field }}">
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-pclsTakenToDate" class="{{ $label }}">Tax-free cash already taken (£)</label>
                                    <input id="pensions-{{ $i }}-pclsTakenToDate" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.pclsTakenToDate" class="{{ $field }}">
                                </div>
                                <div class="sm:col-span-2 lg:col-span-3">
                                    <p class="{{ $label }} mb-2">Planned withdrawals</p>
                                    @foreach ($pension['withdrawals'] as $wi => $w)
                                        <div wire:key="pension-{{ $i }}-wd-{{ $wi }}" class="mb-2 grid items-end gap-2 sm:grid-cols-4">
                                            <div>
                                                <label for="pensions-{{ $i }}-wd-{{ $wi }}-kind" class="text-xs text-gray-600">Kind</label>
                                                <select id="pensions-{{ $i }}-wd-{{ $wi }}-kind" wire:model="pensions.{{ $i }}.withdrawals.{{ $wi }}.kind" class="{{ $field }}">
                                                    <option value="pcls">Tax-free lump (PCLS)</option>
                                                    <option value="ufpls">UFPLS</option>
                                                    <option value="drawdown">Drawdown income</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label for="pensions-{{ $i }}-wd-{{ $wi }}-amount" class="text-xs text-gray-600">Amount (£)</label>
                                                <input id="pensions-{{ $i }}-wd-{{ $wi }}-amount" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.withdrawals.{{ $wi }}.amount" class="{{ $field }}">
                                                @error('pensions.'.$i.'.withdrawals.'.$wi.'.amount') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                            </div>
                                            <div>
                                                <label for="pensions-{{ $i }}-wd-{{ $wi }}-atAge" class="text-xs text-gray-600">At age</label>
                                                <input id="pensions-{{ $i }}-wd-{{ $wi }}-atAge" type="number" wire:model="pensions.{{ $i }}.withdrawals.{{ $wi }}.atAge" class="{{ $field }}">
                                                @error('pensions.'.$i.'.withdrawals.'.$wi.'.atAge') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                            </div>
                                            <button type="button" wire:click="removeWithdrawal({{ $i }}, {{ $wi }})" class="mb-2 text-sm text-red-700 underline">Remove</button>
                                        </div>
                                    @endforeach
                                    <button type="button" wire:click="addWithdrawal({{ $i }})" class="mt-1 rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">+ Add withdrawal</button>
                                </div>
                            @elseif ($pension['subtype'] === 'db')
                                <div>
                                    <label for="pensions-{{ $i }}-accruedAnnualPension" class="{{ $label }}">Annual pension (£/yr)</label>
                                    <input id="pensions-{{ $i }}-accruedAnnualPension" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.accruedAnnualPension" class="{{ $field }}">
                                    @error('pensions.'.$i.'.accruedAnnualPension') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-normalRetirementAge" class="{{ $label }}">Normal retirement age</label>
                                    <input id="pensions-{{ $i }}-normalRetirementAge" type="number" wire:model="pensions.{{ $i }}.normalRetirementAge" class="{{ $field }}">
                                    @error('pensions.'.$i.'.normalRetirementAge') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-revaluationBasis" class="{{ $label }}">Revaluation (pre-payment)</label>
                                    <select id="pensions-{{ $i }}-revaluationBasis" wire:model="pensions.{{ $i }}.revaluationBasis" class="{{ $field }}">
                                        <option value="none">None</option>
                                        <option value="cpi">CPI</option>
                                        <option value="rpi">RPI</option>
                                        <option value="cpi_capped_5">CPI capped at 5%</option>
                                        <option value="fixed">Fixed</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-escalationInPayment" class="{{ $label }}">Escalation (in payment)</label>
                                    <select id="pensions-{{ $i }}-escalationInPayment" wire:model="pensions.{{ $i }}.escalationInPayment" class="{{ $field }}">
                                        <option value="none">None</option>
                                        <option value="cpi">CPI</option>
                                        <option value="rpi">RPI</option>
                                        <option value="cpi_capped_5">CPI capped at 5%</option>
                                        <option value="fixed">Fixed</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-spousePensionFraction" class="{{ $label }}">Survivor fraction (%)</label>
                                    <input id="pensions-{{ $i }}-spousePensionFraction" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.spousePensionFraction" class="{{ $field }}">
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-commutationLumpSum" class="{{ $label }}">Commutation lump sum (£, optional)</label>
                                    <input id="pensions-{{ $i }}-commutationLumpSum" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.commutationLumpSum" class="{{ $field }}">
                                </div>
                            @else
                                @php($spLevel = $pension['level'] ?? 'amount')
                                <div class="sm:col-span-2 lg:col-span-3">
                                    <label for="pensions-{{ $i }}-level" class="{{ $label }}">How much State Pension?</label>
                                    <select id="pensions-{{ $i }}-level" wire:model.live="pensions.{{ $i }}.level" class="{{ $field }} sm:max-w-md">
                                        <option value="full">Full new State Pension (£{{ $this->fullStatePensionWeekly() }}/wk)</option>
                                        <option value="amount">A specific weekly amount</option>
                                        <option value="years">Work it out from my qualifying years</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">Not sure? <a href="https://www.gov.uk/check-state-pension" target="_blank" rel="noopener noreferrer" class="text-blue-700 underline">Check your State Pension forecast on gov.uk</a> and copy the weekly figure.</p>
                                </div>
                                @if ($spLevel === 'full')
                                    <div>
                                        <span class="{{ $label }}">Weekly amount</span>
                                        <p class="mt-1 rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-800">£{{ $this->fullStatePensionWeekly() }}/wk — full new State Pension ({{ $baseTaxYear }})</p>
                                    </div>
                                @elseif ($spLevel === 'years')
                                    <div>
                                        <label for="pensions-{{ $i }}-qualifyingYears" class="{{ $label }}">Qualifying years (out of 35)</label>
                                        <input id="pensions-{{ $i }}-qualifyingYears" type="number" min="0" max="50" wire:model="pensions.{{ $i }}.qualifyingYears" class="{{ $field }}">
                                        <p class="mt-1 text-xs text-gray-500">We work the weekly amount out from this.</p>
                                    </div>
                                @else
                                    <div>
                                        <label for="pensions-{{ $i }}-weeklyForecast" class="{{ $label }}">Weekly amount (£, from your statement)</label>
                                        <input id="pensions-{{ $i }}-weeklyForecast" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.weeklyForecast" class="{{ $field }}">
                                        @error('pensions.'.$i.'.weeklyForecast') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                                <div>
                                    <label for="pensions-{{ $i }}-deferralWeeks" class="{{ $label }}">Deferral (weeks, optional)</label>
                                    <input id="pensions-{{ $i }}-deferralWeeks" type="number" wire:model="pensions.{{ $i }}.deferralWeeks" class="{{ $field }}">
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-gray-500">No pensions added.</p>
                @endforelse
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="addPension('dc')" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-100">+ DC pension</button>
                    <button type="button" wire:click="addPension('db')" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-100">+ DB pension</button>
                    <button type="button" wire:click="addPension('state')" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-100">+ State pension</button>
                </div>
            </fieldset>

            <fieldset class="{{ $section }}">
                <legend class="{{ $legend }}">Other income</legend>
                <p class="mt-1 text-sm text-gray-600">Rent, annuities or anything else not already captured as a pension.</p>
                @foreach ($incomeStreams as $i => $stream)
                    <div wire:key="income-{{ $i }}" class="mt-4 grid items-end gap-3 sm:grid-cols-6">
                        <div>
                            <label for="incomeStreams-{{ $i }}-ownerId" class="text-xs text-gray-600">Owner</label>
                            <select id="incomeStreams-{{ $i }}-ownerId" wire:model="incomeStreams.{{ $i }}.ownerId" class="{{ $field }}">
                                @foreach ($ownerOptions as $o)<option value="{{ $o['id'] }}">{{ $o['label'] }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="incomeStreams-{{ $i }}-type" class="text-xs text-gray-600">Type</label>
                            <select id="incomeStreams-{{ $i }}-type" wire:model="incomeStreams.{{ $i }}.type" class="{{ $field }}">
                                <option value="rental">Rental</option>
                                <option value="annuity">Annuity</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label for="incomeStreams-{{ $i }}-grossAnnual" class="text-xs text-gray-600">Gross (£/yr)</label>
                            <input id="incomeStreams-{{ $i }}-grossAnnual" type="text" inputmode="decimal" wire:model="incomeStreams.{{ $i }}.grossAnnual" class="{{ $field }}">
                            @error('incomeStreams.'.$i.'.grossAnnual') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="incomeStreams-{{ $i }}-startAge" class="text-xs text-gray-600">Start age</label>
                            <input id="incomeStreams-{{ $i }}-startAge" type="number" wire:model="incomeStreams.{{ $i }}.startAge" class="{{ $field }}">
                            @error('incomeStreams.'.$i.'.startAge') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="incomeStreams-{{ $i }}-endAge" class="text-xs text-gray-600">End age (optional)</label>
                            <input id="incomeStreams-{{ $i }}-endAge" type="number" wire:model="incomeStreams.{{ $i }}.endAge" class="{{ $field }}" @error('incomeStreams.'.$i.'.endAge') aria-invalid="true" aria-describedby="incomeStreams-{{ $i }}-endAge-error" @enderror>
                            @error('incomeStreams.'.$i.'.endAge') <p id="incomeStreams-{{ $i }}-endAge-error" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-1 text-xs text-gray-600"><input type="checkbox" wire:model="incomeStreams.{{ $i }}.taxable" class="rounded border-gray-300"> Taxable</label>
                            <button type="button" wire:click="removeIncome({{ $i }})" class="text-sm text-red-700 underline">Remove</button>
                        </div>
                    </div>
                @endforeach
                <button type="button" wire:click="addIncome" class="mt-4 rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-100">+ Add income</button>
            </fieldset>
        @endif

        {{-- Step 3: Capture your net worth (savings, investments and the home) ------- --}}
        @if ($step === 3)
            <p class="text-sm text-gray-600">Your net worth — what you own that the plan can draw on, less what is owed on the home. Pensions are captured on the previous step.</p>

            <fieldset class="{{ $section }}">
                <legend class="{{ $legend }}">Savings &amp; investments</legend>
                @foreach ($accounts as $i => $account)
                    <div wire:key="account-{{ $i }}" class="mt-4 grid items-end gap-3 sm:grid-cols-5">
                        <div>
                            <label for="accounts-{{ $i }}-ownerId" class="text-xs text-gray-600">Owner</label>
                            <select id="accounts-{{ $i }}-ownerId" wire:model="accounts.{{ $i }}.ownerId" class="{{ $field }}">
                                @foreach ($ownerOptions as $o)<option value="{{ $o['id'] }}">{{ $o['label'] }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="accounts-{{ $i }}-type" class="text-xs text-gray-600">Type</label>
                            <select id="accounts-{{ $i }}-type" wire:model="accounts.{{ $i }}.type" class="{{ $field }}">
                                <option value="isa">ISA</option>
                                <option value="gia">General (GIA)</option>
                                <option value="cash">Cash</option>
                                <option value="premium_bonds">Premium bonds</option>
                            </select>
                        </div>
                        <div>
                            <label for="accounts-{{ $i }}-balance" class="text-xs text-gray-600">Balance (£)</label>
                            <input id="accounts-{{ $i }}-balance" type="text" inputmode="decimal" wire:model="accounts.{{ $i }}.balance" class="{{ $field }}">
                            @error('accounts.'.$i.'.balance') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="accounts-{{ $i }}-yield" class="text-xs text-gray-600">Yield (%/yr)</label>
                            <input id="accounts-{{ $i }}-yield" type="text" inputmode="decimal" wire:model="accounts.{{ $i }}.yield" class="{{ $field }}">
                        </div>
                        <button type="button" wire:click="removeAccount({{ $i }})" class="mb-2 text-sm text-red-700 underline">Remove</button>
                    </div>
                @endforeach
                <button type="button" wire:click="addAccount" class="mt-4 rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-100">+ Add account</button>
            </fieldset>

            <fieldset class="{{ $section }}">
                <legend class="{{ $legend }}">Current home</legend>
                <label class="mt-3 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model.live="hasProperty" class="rounded border-gray-300"> The household owns its home
                </label>
                @if ($hasProperty)
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="property-currentValue" class="{{ $label }}">Current value (£)</label>
                            <input id="property-currentValue" type="text" inputmode="decimal" wire:model="property.currentValue" class="{{ $field }}" @error('property.currentValue') aria-invalid="true" aria-describedby="property-currentValue-error" @enderror>
                            @error('property.currentValue') <p id="property-currentValue-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="property-ownership" class="{{ $label }}">Ownership</label>
                            <select id="property-ownership" wire:model="property.ownership" class="{{ $field }}">
                                <option value="outright">Owned outright</option>
                                <option value="mortgaged">Mortgaged</option>
                            </select>
                        </div>
                        <div>
                            <label for="property-outstandingMortgage" class="{{ $label }}">Outstanding mortgage (£)</label>
                            <input id="property-outstandingMortgage" type="text" inputmode="decimal" wire:model="property.outstandingMortgage" class="{{ $field }}">
                        </div>
                        <div>
                            <label for="property-runningCosts" class="{{ $label }}">Running costs (£/yr)</label>
                            <input id="property-runningCosts" type="text" inputmode="decimal" wire:model="property.runningCosts" class="{{ $field }}">
                        </div>
                        <div>
                            <label for="property-growthAssumptionOverride" class="{{ $label }}">Growth override (%/yr)</label>
                            <input id="property-growthAssumptionOverride" type="text" inputmode="decimal" wire:model="property.growthAssumptionOverride" class="{{ $field }}">
                        </div>
                        <div>
                            <label class="mt-7 flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model="property.everLet" class="rounded border-gray-300"> Ever let (restricts PRR)
                            </label>
                        </div>
                    </div>
                @endif
            </fieldset>
        @endif

        {{-- Step 4: Spending -------------------------------------------------------- --}}
        @if ($step === 4)
            <fieldset class="{{ $section }}">
                <legend class="{{ $legend }}">Spending — your yearly budget</legend>
                <p class="mt-1 text-sm text-gray-600">
                    List what you spend each year as lines, and tag each one: <strong>essential</strong> (needs — the
                    floor used for the "essentials always met" measure), <strong>discretionary</strong> (wants you
                    could trim), or <strong>self-investment</strong> (learning, courses, savings plans). The three
                    tiers are a way to see where your money goes, not a target to hit.
                </p>

                @error('expenseLines') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror

                <div class="mt-4 space-y-2">
                    @foreach ($expenseLines as $i => $line)
                        <div wire:key="expline-{{ $line['id'] ?? $i }}" class="grid items-end gap-2 sm:grid-cols-12">
                            <div class="sm:col-span-5">
                                <label for="expenseLines-{{ $i }}-label" class="text-xs text-gray-600">Description</label>
                                <input id="expenseLines-{{ $i }}-label" type="text" wire:model="expenseLines.{{ $i }}.label" placeholder="e.g. Council tax" class="{{ $field }}">
                            </div>
                            <div class="sm:col-span-3">
                                <label for="expenseLines-{{ $i }}-amount" class="text-xs text-gray-600">£ / year</label>
                                <input id="expenseLines-{{ $i }}-amount" type="text" inputmode="decimal" wire:model="expenseLines.{{ $i }}.amount" class="{{ $field }}" @error('expenseLines.'.$i.'.amount') aria-invalid="true" @enderror>
                                @error('expenseLines.'.$i.'.amount') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-3">
                                <label for="expenseLines-{{ $i }}-category" class="text-xs text-gray-600">Tier</label>
                                <select id="expenseLines-{{ $i }}-category" wire:model="expenseLines.{{ $i }}.category" class="{{ $field }}">
                                    <option value="essential">Essential</option>
                                    <option value="discretionary">Discretionary</option>
                                    <option value="self_investment">Self-investment</option>
                                </select>
                            </div>
                            <button type="button" wire:click="removeExpenseLine({{ $i }})" class="mb-2 text-sm text-red-700 underline sm:col-span-1">Remove</button>

                            @if (($line['category'] ?? '') === 'self_investment')
                                <label class="flex items-center gap-2 text-xs text-gray-700 sm:col-span-12">
                                    <input type="checkbox" wire:model="expenseLines.{{ $i }}.savedAsAsset">
                                    This is <strong>saved</strong> (builds your net worth) rather than spent — counted as a contribution, not as spending.
                                </label>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" wire:click="addExpenseLine('essential')" class="rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">+ Essential</button>
                    <button type="button" wire:click="addExpenseLine('discretionary')" class="rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">+ Discretionary</button>
                    <button type="button" wire:click="addExpenseLine('self_investment')" class="rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">+ Self-investment</button>
                </div>

                <dl class="mt-4 grid gap-3 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-4" aria-live="polite">
                    <div><dt class="text-gray-500">Essential / yr</dt><dd class="font-semibold tabular-nums">£{{ number_format($expenseTotals['essential']) }}</dd></div>
                    <div><dt class="text-gray-500">Discretionary / yr</dt><dd class="font-semibold tabular-nums">£{{ number_format($expenseTotals['discretionary']) }}</dd></div>
                    <div><dt class="text-gray-500">Self-investment saved / yr</dt><dd class="font-semibold tabular-nums">£{{ number_format($expenseTotals['saved']) }}</dd></div>
                    <div><dt class="text-gray-500">Total spend / yr</dt><dd class="font-semibold tabular-nums">£{{ number_format($expenseTotals['total']) }}</dd></div>
                </dl>

                <div class="mt-5 sm:max-w-xs">
                    <label for="expense-survivorFactor" class="{{ $label }}">Survivor spend (% of couple's)</label>
                    <input id="expense-survivorFactor" type="text" inputmode="decimal" wire:model="expense.survivorFactor" class="{{ $field }}">
                </div>

                <p class="{{ $label }} mt-5 mb-2">One-off costs</p>
                @foreach ($oneOffCosts as $i => $cost)
                    <div wire:key="oneoff-{{ $i }}" class="mb-2 grid items-end gap-2 sm:grid-cols-4">
                        <div>
                            <label for="oneOffCosts-{{ $i }}-atAge" class="text-xs text-gray-600">At age</label>
                            <input id="oneOffCosts-{{ $i }}-atAge" type="number" wire:model="oneOffCosts.{{ $i }}.atAge" class="{{ $field }}">
                            @error('oneOffCosts.'.$i.'.atAge') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="oneOffCosts-{{ $i }}-amount" class="text-xs text-gray-600">Amount (£)</label>
                            <input id="oneOffCosts-{{ $i }}-amount" type="text" inputmode="decimal" wire:model="oneOffCosts.{{ $i }}.amount" class="{{ $field }}">
                            @error('oneOffCosts.'.$i.'.amount') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="oneOffCosts-{{ $i }}-label" class="text-xs text-gray-600">Label</label>
                            <input id="oneOffCosts-{{ $i }}-label" type="text" wire:model="oneOffCosts.{{ $i }}.label" class="{{ $field }}">
                        </div>
                        <button type="button" wire:click="removeOneOff({{ $i }})" class="mb-2 text-sm text-red-700 underline">Remove</button>
                    </div>
                @endforeach
                <button type="button" wire:click="addOneOff" class="mt-1 rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">+ Add one-off cost</button>
            </fieldset>
        @endif

        {{-- Step 5: The housing decision to compare --------------------------------- --}}
        @if ($step === 5)
            <fieldset class="{{ $section }}">
                <legend class="{{ $legend }}">The housing decision to compare</legend>
                <p class="mt-1 text-sm text-gray-600">Stay put, buy somewhere cheaper outright, or sell and rent are run on identical seeds.</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="housing-salePrice" class="{{ $label }}">Assumed sale price (£)</label>
                        <input id="housing-salePrice" type="text" inputmode="decimal" wire:model="housing.salePrice" class="{{ $field }}" @error('housing.salePrice') aria-invalid="true" aria-describedby="housing-salePrice-error" @enderror>
                        @error('housing.salePrice') <p id="housing-salePrice-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="housing-buyPrice" class="{{ $label }}">Cheaper home price (£)</label>
                        <input id="housing-buyPrice" type="text" inputmode="decimal" wire:model="housing.buyPrice" class="{{ $field }}">
                    </div>
                    <div>
                        <label for="housing-annualRent" class="{{ $label }}">Annual rent if renting (£)</label>
                        <input id="housing-annualRent" type="text" inputmode="decimal" wire:model="housing.annualRent" class="{{ $field }}">
                    </div>
                    <div>
                        <label for="housing-rentInflationReal" class="{{ $label }}">Rent inflation (real %/yr)</label>
                        <input id="housing-rentInflationReal" type="text" inputmode="decimal" wire:model="housing.rentInflationReal" class="{{ $field }}">
                    </div>
                    <div>
                        <label for="housing-movingCosts" class="{{ $label }}">Moving costs (£)</label>
                        <input id="housing-movingCosts" type="text" inputmode="decimal" wire:model="housing.movingCosts" class="{{ $field }}">
                    </div>
                    <div>
                        <label for="housing-sellingCostRate" class="{{ $label }}">Selling cost (% of sale)</label>
                        <input id="housing-sellingCostRate" type="text" inputmode="decimal" wire:model="housing.sellingCostRate" class="{{ $field }}">
                    </div>
                </div>
                <p class="mt-4 text-sm text-gray-600">When you save, all three options are run and compared on the results page. You can come back and change any step.</p>
            </fieldset>
        @endif
        </div>

        {{-- Wizard controls -------------------------------------------------------- --}}
        <div class="space-y-3">
            <p class="text-xs text-gray-500">Your progress is saved automatically each time you move between steps — you can safely leave and come back.</p>
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    @if ($step > 1)
                        <button type="button" wire:click="prevStep" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-100">Back</button>
                    @endif
                    <button type="button" wire:click="leave" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Save draft &amp; exit</button>
                    <button type="button" wire:click="discardDraft" wire:confirm="Discard this forecast and delete the draft? Anything you have entered will be lost." class="text-sm text-gray-600 underline hover:text-red-700">Discard</button>
                </div>
                <div class="flex items-center gap-3">
                    @if ($step < $lastStep)
                        <button type="button" wire:click="nextStep" class="rounded-md bg-blue-600 px-5 py-2 font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">Next</button>
                    @else
                        <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="rounded-md bg-blue-600 px-5 py-2 font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50">
                            <span wire:loading.remove wire:target="save">Save forecast</span>
                            <span wire:loading wire:target="save">Saving…</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </form>
</div>
