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
        {{-- One expression, not @if/@elseif/@else: the directive form had `forecast@else` glued to a
             word character, which Blade does not compile, so the whole heading rendered EMPTY on a new
             forecast (found by the axe sweep as an empty-heading violation). --}}
        <h1 class="text-2xl font-semibold text-gray-900">
            {{ $childMode
                ? ($editing ? 'Edit what-if' : 'Create a what-if')
                : ($editing ? 'Edit forecast' : 'New forecast') }}
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
                    {{-- gray-600, not gray-500: this sits on the blue-50 tint, where gray-500 is 4.44:1 (under AA). --}}
                    <p class="mt-1 text-xs text-gray-600">{{ collect($importProfiles)->firstWhere('key', $importProfile)['description'] ?? '' }}</p>
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

    @if ($childMode)
        {{-- A what-if: fields whose value differs from the base plan are ringed in amber, so
             the changes are obvious while editing (matched to inputs by wire:model, client-side). --}}
        <p class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
            You are building a <strong>what-if</strong>. Fields you change from the base plan are <span class="rounded bg-amber-100 px-1 font-medium">highlighted</span>.
        </p>
    @endif

    {{-- Live preview: a cheap one-path deterministic readout that updates on each round-trip,
         so editing any input shows its effect before running the full forecast (the
         ProjectionLab pattern). Server-rendered, no JS; sticky so it stays in view while you
         scroll the form. Null while the inputs are too incomplete to forecast. --}}
    <div aria-live="polite"
        class="z-10 rounded-lg border p-4 shadow-sm lg:sticky lg:top-4
            {{ ! $livePreview ? 'border-gray-200 bg-gray-50' : ($livePreview['level'] === 'good' ? 'border-green-300 bg-green-50' : 'border-amber-300 bg-amber-50') }}">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Live preview</p>
        @if ($livePreview)
            <div class="mt-1 flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-medium {{ $livePreview['level'] === 'good' ? 'text-green-900' : 'text-amber-900' }}">
                        {{ $livePreview['lasts'] ? '✓' : '⚠' }} {{ $livePreview['verdict'] }}
                    </p>
                    <p class="mt-0.5 text-sm text-gray-700">{{ $livePreview['spendNote'] }}</p>
                </div>
                <dl class="shrink-0 text-right text-sm">
                    <dt class="text-xs text-gray-500">Spendable at end (excl. home)</dt>
                    <dd class="font-semibold text-gray-900">{{ $livePreview['usable'] }}</dd>
                    <dt class="mt-1 text-xs text-gray-500">Total wealth at end</dt>
                    <dd class="font-semibold text-gray-900">{{ $livePreview['total'] }}</dd>
                </dl>
            </div>
            <p class="mt-2 text-xs text-gray-500">A single best-estimate projection in today's money, for a quick check as you edit. Save and run for the full range of futures and the run-out risk. This illustrates consequences; it does not recommend a course of action.</p>
        @else
            <p class="mt-1 text-sm text-gray-600">Fill in the required fields (the people, the spending and the housing figures) to see a live forecast here.</p>
        @endif
    </div>

    <form wire:submit="save" class="space-y-6"
        @if ($childMode) data-builder-diff data-changes="{{ json_encode($changedFromBase) }}" @endif>
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
                    @if (count($people) > 1)
                    <div>
                        <label for="relationshipStatus" class="{{ $label }}">Relationship</label>
                        <select id="relationshipStatus" wire:model.live="relationshipStatus" class="{{ $field }}">
                            <option value="">Please choose</option>
                            <option value="married_or_civil_partnership">Married / civil partnership</option>
                            <option value="cohabiting">Cohabiting (not married)</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">There is no default for this, because being wrong about it changes almost everything: a married couple pass their estate to each other free of Inheritance Tax, share both nil-rate bands, can inherit State Pension from each other, and are the only people most work pensions pay a survivor's pension to. A cohabiting couple get none of that.</p>
                        @error('relationshipStatus') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    @if ($relationshipStatus === 'married_or_civil_partnership')
                    <div>
                        <label for="marriageDate" class="{{ $label }}">Date of the marriage / civil partnership</label>
                        <input type="date" id="marriageDate" wire:model="marriageDate" class="{{ $field }}">
                        <p class="mt-1 text-xs text-gray-500">Optional. Which State Pension a survivor can inherit depends on whether you were married before 6 April 2016, so it is worth recording. Nothing in the forecast moves with it yet.</p>
                        @error('marriageDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    @endif
                    @endif
                    <div class="sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="ihtModelled" class="rounded border-gray-300">
                            Model inheritance tax (estate &amp; legacy)
                        </label>
                        @if ($ihtModelled)
                        <label class="mt-2 flex items-center gap-2 pl-6 text-sm text-gray-700">
                            <input type="checkbox" wire:model="homeToDescendants" class="rounded border-gray-300">
                            Leaving your home to your children / direct descendants
                        </label>
                        <p class="mt-1 pl-6 text-xs text-gray-500">Unlocks the £175,000 residence nil-rate band per person (only when the home passes to direct descendants). Untick if it will not.</p>
                        <div class="mt-3 pl-6">
                            <label for="beneficiaryTaxRate" class="{{ $label }}">Tax rate of whoever inherits your pension</label>
                            <select id="beneficiaryTaxRate" wire:model="beneficiaryTaxRate" class="{{ $field }}">
                                <option value="">Assume the higher rate (40%)</option>
                                <option value="0">No tax (they have no other income)</option>
                                <option value="20">Basic rate (20%)</option>
                                <option value="40">Higher rate (40%)</option>
                                <option value="45">Additional rate (45%)</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">If you die at or after 75, an unused pension pot is taxed twice: Inheritance Tax on your estate, and then the person who inherits it pays their own income tax on every pound they take out of what is left. Together that can be around two thirds of the pot. We assume the higher rate unless you say otherwise, because a working-age child drawing a pot on top of their salary usually lands there.</p>
                            @error('beneficiaryTaxRate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        @endif
                    </div>
                    <div class="sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="modelCareCost" class="rounded border-gray-300">
                            Model the risk of late-life care costs
                        </label>
                        <p class="mt-1 text-xs text-gray-500">Adds the chance of residential/nursing care fees to the simulated futures (around a 1-in-4 risk; self-funder fees ~£1,300–£1,600/week, means-tested so only what your household would bear is charged). Lowers the success rate to reflect this real tail risk.</p>
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

                {{-- How long the State Pension triple lock is assumed to hold (board card 0038).
                     It is a policy guess, not a rate, so it is a choice rather than a box. Blank
                     is the engine's default, and that default is the OPTIMISTIC branch, which is
                     said here rather than left for the reader to work out. --}}
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <label for="statePensionUprating" class="{{ $label }}">Assume the State Pension triple lock</label>
                    <select id="statePensionUprating" wire:model.live="assumptionOverrides.statePensionUprating"
                        class="{{ $field }} sm:max-w-md"
                        @error('assumptionOverrides.statePensionUprating') aria-invalid="true" aria-describedby="statePensionUprating-error" @enderror>
                        @foreach ($statePensionUpratingOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">
                        The triple lock raises the State Pension by the highest of earnings, prices and a fixed floor. The floor is government policy, not law, and no government has promised it beyond the current Parliament, so assuming it survives a whole retirement is the <strong>cheerful</strong> choice. It also lifts the Pension Credit guarantee, which is uprated by the same figure. We do not model the earnings part of the lock, so in a year when wages beat both this runs on the cautious side.
                    </p>
                    @error('assumptionOverrides.statePensionUprating') <p id="statePensionUprating-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror

                    @if (($assumptionOverrides['statePensionUprating'] ?? '') === 'triple_lock_until')
                        <div class="mt-3">
                            <label for="statePensionUpratingUntilYear" class="{{ $label }}">Last year the lock applies</label>
                            <input id="statePensionUpratingUntilYear" type="text" inputmode="numeric"
                                wire:model="assumptionOverrides.statePensionUpratingUntilYear"
                                class="{{ $field }} sm:max-w-xs"
                                @error('assumptionOverrides.statePensionUpratingUntilYear') aria-invalid="true" aria-describedby="statePensionUpratingUntilYear-error" @enderror>
                            <p class="mt-1 text-xs text-gray-500">The lock lifts your pension up to and including this year; after it the State Pension rises with prices alone. Leave it blank and we use prices alone throughout, rather than quietly giving you the lock back.</p>
                            @error('assumptionOverrides.statePensionUpratingUntilYear') <p id="statePensionUpratingUntilYear-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                {{-- The adviser's ongoing fee is NOT an economic assumption: the forecast never
                     charges it. It is the parameter of the results page's "what paying for advice
                     would cost" comparison, so it sits below the assumptions with that said out loud. --}}
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <label for="adviceFeePct" class="{{ $label }}">If you paid for advice, the ongoing fee (% a year)</label>
                    <input id="adviceFeePct" type="text" inputmode="decimal" wire:model.blur="adviceFeePct"
                        placeholder="{{ number_format(config('advice.ongoing_fee_bp') / 100, 2) }}"
                        class="{{ $field }} sm:max-w-xs"
                        @error('adviceFeePct') aria-invalid="true" aria-describedby="adviceFeePct-error" @enderror>
                    <p class="mt-1 text-xs text-gray-500">
                        <strong>This is never charged to your forecast.</strong> It only prices the "what paying for advice would cost" comparison on your results, which re-runs the same plan with this fee added on top of the charges it already bears. Leave it blank to use the benchmark average of {{ number_format(config('advice.ongoing_fee_bp') / 100, 2) }}% a year (NextWealth, checked {{ config('advice.verified_on') }}); if you have a real quote, use that instead.
                    </p>
                    @error('adviceFeePct') <p id="adviceFeePct-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
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
                                <select id="people-{{ $i }}-employmentStatus" wire:model.live="people.{{ $i }}.employmentStatus" class="{{ $field }}">
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
                                <label for="people-{{ $i }}-salaryGrowth" class="{{ $label }}">Salary growth (%/yr, real)</label>
                                <input id="people-{{ $i }}-salaryGrowth" type="text" inputmode="decimal" wire:model="people.{{ $i }}.salaryGrowth" class="{{ $field }}">
                                <p class="mt-1 text-xs text-gray-500">Above inflation, for this person only. Leave blank to use the economy-wide salary-growth assumption.</p>
                            </div>
                            <div>
                                <label for="people-{{ $i }}-plannedRetirementAge" class="{{ $label }}">Planned retirement age</label>
                                <input id="people-{{ $i }}-plannedRetirementAge" type="number" wire:model="people.{{ $i }}.plannedRetirementAge" class="{{ $field }}" @error('people.'.$i.'.plannedRetirementAge') aria-invalid="true" aria-describedby="people-{{ $i }}-plannedRetirementAge-error" @enderror>
                                @error('people.'.$i.'.plannedRetirementAge') <p id="people-{{ $i }}-plannedRetirementAge-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>
                            {{-- National Insurance is charged only on an employment salary, so this shows
                                 only for an employed person. It automatically stops at State Pension age
                                 and never touches pension income, so those cases aren't set by hand — the
                                 only genuine overrides are the two reduced/deferred employee rates. --}}
                            @if (($person['employmentStatus'] ?? '') === 'employed')
                                <div>
                                    <label for="people-{{ $i }}-niCategory" class="{{ $label }}">National Insurance rate</label>
                                    <select id="people-{{ $i }}-niCategory" wire:model="people.{{ $i }}.niCategory" class="{{ $field }}">
                                        <option value="">Standard (most employees)</option>
                                        <option value="B">Reduced rate — married woman's / widow's pre-1977 election</option>
                                        <option value="J">Deferred — pays maximum NI through another job</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">Applies to this salary during working years. National Insurance stops automatically at State Pension age and never applies to pension income — you don't set those here. Almost everyone is the standard rate.</p>
                                </div>
                            @endif
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
                                    <input id="people-{{ $i }}-longevityValue" type="number" wire:model.blur="people.{{ $i }}.longevityValue" class="{{ $field }}" @error('people.'.$i.'.longevityValue') aria-invalid="true" aria-describedby="people-{{ $i }}-longevityValue-error" @enderror>
                                    @error('people.'.$i.'.longevityValue') <p id="people-{{ $i }}-longevityValue-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                            @endif
                            <div class="col-span-full">
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model.live="people.{{ $i }}.receivesDisabilityBenefit" class="rounded border-gray-300">
                                    Receives a disability benefit (DLA / Attendance Allowance / PIP)
                                </label>
                                <p class="mt-1 text-xs text-gray-500">Enter the benefit itself as a tax-free income stream below. This flag lets the forecast include the Pension Credit severe-disability top-up while they are alive.</p>
                            </div>
                            {{-- When the benefit starts. Health declines late, and Attendance Allowance
                                 is the commonest thing claimed in a long survivor period, so the flag
                                 on its own (on or off for life) could not model the usual case. --}}
                            @if ($person['receivesDisabilityBenefit'] ?? false)
                                <div>
                                    <label for="people-{{ $i }}-disabilityBenefitFromAge" class="{{ $label }}">Claimed from age</label>
                                    <input id="people-{{ $i }}-disabilityBenefitFromAge" type="number" wire:model.blur="people.{{ $i }}.disabilityBenefitFromAge" class="{{ $field }}" @error('people.'.$i.'.disabilityBenefitFromAge') aria-invalid="true" aria-describedby="people-{{ $i }}-disabilityBenefitFromAge-error" @enderror>
                                    @error('people.'.$i.'.disabilityBenefitFromAge') <p id="people-{{ $i }}-disabilityBenefitFromAge-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                    <p class="mt-1 text-xs text-gray-500">Leave blank if they get it already. Put an age here to model a claim made later, when their health declines. The Pension Credit top-up then starts in that year and not before.</p>
                                </div>
                                {{-- Which part of the award, and at what rate. The Pension Credit
                                     severe-disability and carer top-ups ride the CARE side only, so
                                     the bare flag above was paying them to people with a
                                     mobility-only or lowest-rate award who are not entitled. --}}
                                <div>
                                    <label for="people-{{ $i }}-disabilityAwardRate" class="{{ $label }}">Which part of the award</label>
                                    <select id="people-{{ $i }}-disabilityAwardRate" wire:model.live="people.{{ $i }}.disabilityAwardRate" class="{{ $field }}">
                                        <option value="">Care at the middle or highest rate, Attendance Allowance, or PIP daily living</option>
                                        <option value="lowest_rate_care">DLA lowest rate care component only</option>
                                        <option value="mobility_only">Mobility component only</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">The Pension Credit severe-disability and carer top-ups only count the care side of an award. A mobility-only award, or the lowest rate care component, pays money but does not qualify for either.</p>
                                </div>
                            @endif
                            @if (count($people) > 1)
                                <div class="col-span-full">
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" wire:model="people.{{ $i }}.caresForPartner" class="rounded border-gray-300">
                                        Cares for their partner (35+ hours a week)
                                    </label>
                                    <p class="mt-1 text-xs text-gray-500">Tick this if they give their partner at least 35 hours of care a week and that partner gets a disability benefit. It adds the Pension Credit carer top-up. Carer's Allowance also has a weekly earnings limit, so pay above it stops this counting while they are still working.</p>
                                </div>
                            @endif
                            {{-- Estate paperwork. No will is the DEFAULT here on purpose: nobody was
                                 ever asked, and without a will an estate passes under the intestacy
                                 rules, where a husband, wife or civil partner does NOT take
                                 everything. See board card 0054. --}}
                            <div class="col-span-full">
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model="people.{{ $i }}.hasWill" class="rounded border-gray-300">
                                    They have a current will
                                </label>
                                <p class="mt-1 text-xs text-gray-500">Left unticked, we model them as having no will. Without one the estate passes under the intestacy rules: a husband, wife or civil partner takes the personal belongings, the first {{ $this->statutoryLegacy() }} and half of what is left, and the children take the other half. That half is taxed and it uses up part of the allowance that would otherwise have passed to the survivor.</p>
                            </div>
                            <div>
                                <label for="people-{{ $i }}-ukLongTermResident" class="{{ $label }}">UK long-term resident for Inheritance Tax</label>
                                <select id="people-{{ $i }}-ukLongTermResident" wire:model="people.{{ $i }}.ukLongTermResident" class="{{ $field }}">
                                    <option value="">Not said (we assume yes)</option>
                                    <option value="yes">Yes</option>
                                    <option value="no">No</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500">If the one who survives is not a UK long-term resident, the amount that can pass to them free of Inheritance Tax stops at the nil-rate band instead of being unlimited.</p>
                            </div>
                            {{-- Employer death-in-service (group life) cover. Most employed people have
                                 it and most forget; it is also the one protection that VANISHES at
                                 retirement, which is what the results page's protection panel surfaces. --}}
                            @if (in_array($person['employmentStatus'] ?? '', ['employed', 'self_employed'], true))
                                <div>
                                    <label for="people-{{ $i }}-deathInServiceMode" class="{{ $label }}">Death-in-service cover from their employer</label>
                                    <select id="people-{{ $i }}-deathInServiceMode" wire:model.live="people.{{ $i }}.deathInServiceMode" class="{{ $field }}">
                                        <option value="">None / don't know</option>
                                        <option value="multiple">A multiple of salary</option>
                                        <option value="fixed">A fixed sum</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">A lump sum an employer's group life scheme pays if you die while still working there. Commonly 2&ndash;4 times salary. It stops when you leave or retire.</p>
                                </div>
                                @if (($person['deathInServiceMode'] ?? '') === 'multiple')
                                    <div>
                                        <label for="people-{{ $i }}-deathInServiceMultiple" class="{{ $label }}">Times salary</label>
                                        <input id="people-{{ $i }}-deathInServiceMultiple" type="number" step="0.5" min="0" wire:model.blur="people.{{ $i }}.deathInServiceMultiple" class="{{ $field }}" @error('people.'.$i.'.deathInServiceMultiple') aria-invalid="true" aria-describedby="people-{{ $i }}-deathInServiceMultiple-error" @enderror>
                                        @error('people.'.$i.'.deathInServiceMultiple') <p id="people-{{ $i }}-deathInServiceMultiple-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                    </div>
                                @elseif (($person['deathInServiceMode'] ?? '') === 'fixed')
                                    <div>
                                        <label for="people-{{ $i }}-deathInServiceSum" class="{{ $label }}">Sum assured (£)</label>
                                        <input id="people-{{ $i }}-deathInServiceSum" type="number" min="0" wire:model.blur="people.{{ $i }}.deathInServiceSum" class="{{ $field }}" @error('people.'.$i.'.deathInServiceSum') aria-invalid="true" aria-describedby="people-{{ $i }}-deathInServiceSum-error" @enderror>
                                        @error('people.'.$i.'.deathInServiceSum') <p id="people-{{ $i }}-deathInServiceSum-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                        <p class="mt-1 text-xs text-gray-500">A stated sum does not rise with pay or prices, so it is worth less the further away the death is. A multiple of salary keeps pace.</p>
                                    </div>
                                @endif
                            @endif
                            {{-- What the lifespan lever resolves to: the modelled age/year of death from the
                                 same deterministic forecast as the live preview, so the setting is concrete. --}}
                            @if (! empty($modelledDeaths[$person['id']]))
                                <p class="col-span-full text-sm text-gray-600" aria-live="polite">
                                    On the current lifespan setting, {{ $personName($person, $i) }} is modelled to live to
                                    <strong>age {{ $modelledDeaths[$person['id']]['age'] }}</strong> (year {{ $modelledDeaths[$person['id']]['year'] }}).
                                </p>
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
                                    <label for="pensions-{{ $i }}-reliefMethod" class="{{ $label }}">Tax relief on your contribution</label>
                                    <select id="pensions-{{ $i }}-reliefMethod" wire:model="pensions.{{ $i }}.reliefMethod" class="{{ $field }}">
                                        <option value="">Not sure — don't model relief</option>
                                        <option value="net_pay">Taken from my pay before tax ("net pay")</option>
                                        <option value="non_earner">I'm not earning, so I pay in from savings</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">Most workplace schemes use net pay: the contribution comes out of your gross pay, so you get relief at your own tax rate straight away. If you're not earning you can still pay in up to £2,880 a year and the provider adds £720, making £3,600. Pick the second option and enter what leaves your bank, not what lands in the pot. Relief stops at 75. Leave it unset and we won't model relief at all, and we'll say so on your results.</p>
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-nominatedBeneficiary" class="{{ $label }}">Nominated to (expression of wish)</label>
                                    <select id="pensions-{{ $i }}-nominatedBeneficiary" wire:model="pensions.{{ $i }}.nominatedBeneficiary" class="{{ $field }}">
                                        <option value="">Not sure yet</option>
                                        <option value="spouse_or_civil_partner">My husband, wife or civil partner</option>
                                        <option value="someone_else">Somebody else (a child, for example)</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">A pension is not covered by your will. The scheme pays whoever your expression of wish form names, at its own discretion, so a pot can go to a child even where everything else goes to your partner. From April 2027 that matters for Inheritance Tax: a pot left to your partner is exempt on the first death, one left to anybody else is not. Leave it unset and we assume the more expensive answer, and say so on your results.</p>
                                    @error('pensions.'.$i.'.nominatedBeneficiary') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
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
                                <div class="sm:col-span-2 lg:col-span-3 rounded-md border border-gray-200 p-3">
                                    <label class="flex items-center gap-2 text-sm font-medium text-gray-800">
                                        <input type="checkbox" wire:model.live="pensions.{{ $i }}.annuitise" class="rounded border-gray-300">
                                        Buy an annuity with part of this pot
                                    </label>
                                    <p class="mt-1 text-xs text-gray-500">Swap part of the pot for a guaranteed income for life, from a chosen age. The pot falls by the amount used; the annuity then pays that amount × the rate each year (and is taxed as income).</p>
                                    @if ($pension['annuitise'] ?? false)
                                        <div class="mt-3 grid items-end gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                            <div>
                                                <label for="pensions-{{ $i }}-annuityAmount" class="text-xs text-gray-600">Amount to annuitise (£)</label>
                                                <input id="pensions-{{ $i }}-annuityAmount" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.annuityAmount" class="{{ $field }}">
                                                @error('pensions.'.$i.'.annuityAmount') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                            </div>
                                            <div>
                                                <label for="pensions-{{ $i }}-annuityAtAge" class="text-xs text-gray-600">At age</label>
                                                <input id="pensions-{{ $i }}-annuityAtAge" type="number" wire:model="pensions.{{ $i }}.annuityAtAge" class="{{ $field }}">
                                                @error('pensions.'.$i.'.annuityAtAge') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                            </div>
                                            <div>
                                                <label for="pensions-{{ $i }}-annuityRate" class="text-xs text-gray-600">Annuity rate (%/yr)</label>
                                                <input id="pensions-{{ $i }}-annuityRate" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.annuityRate" class="{{ $field }}" placeholder="7.2">
                                                @error('pensions.'.$i.'.annuityRate') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                                <p class="mt-1 text-xs text-gray-500">Use a real quote for your age/health; ~7.2% is a rough level joint-life-at-65 guide.</p>
                                            </div>
                                            <div>
                                                <label for="pensions-{{ $i }}-annuityEscalation" class="text-xs text-gray-600">Increases</label>
                                                <select id="pensions-{{ $i }}-annuityEscalation" wire:model="pensions.{{ $i }}.annuityEscalation" class="{{ $field }}">
                                                    <option value="none">Level (flat £, buys less over time)</option>
                                                    <option value="rpi">Rises with inflation (RPI)</option>
                                                    <option value="cpi">Rises with inflation (CPI)</option>
                                                </select>
                                            </div>
                                            <div class="lg:col-span-2">
                                                <label class="flex items-center gap-2 text-xs text-gray-600">
                                                    <input type="checkbox" wire:model.live="pensions.{{ $i }}.annuityJoint" class="rounded border-gray-300">
                                                    Joint life (keeps paying your partner after you die)
                                                </label>
                                                @if ($pension['annuityJoint'] ?? false)
                                                    <div class="mt-1 max-w-xs">
                                                        <label for="pensions-{{ $i }}-annuitySurvivorFraction" class="text-xs text-gray-600">Partner keeps (% of the income)</label>
                                                        <input id="pensions-{{ $i }}-annuitySurvivorFraction" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.annuitySurvivorFraction" class="{{ $field }}" placeholder="50">
                                                        @error('pensions.'.$i.'.annuitySurvivorFraction') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
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
                                    <select id="pensions-{{ $i }}-revaluationBasis" wire:model.live="pensions.{{ $i }}.revaluationBasis" class="{{ $field }}">
                                        @foreach (\App\Livewire\ScenarioBuilder::escalationBases() as $basis)
                                            <option value="{{ $basis->value }}">{{ $basis->label() }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">How the pension grows between now and the day it starts being paid.</p>
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-escalationInPayment" class="{{ $label }}">Escalation (in payment)</label>
                                    <select id="pensions-{{ $i }}-escalationInPayment" wire:model.live="pensions.{{ $i }}.escalationInPayment" class="{{ $field }}">
                                        @foreach (\App\Livewire\ScenarioBuilder::escalationBases() as $basis)
                                            <option value="{{ $basis->value }}">{{ $basis->label() }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">How it grows once it is being paid. Pre-1997 service often has no increases at all, and that is a different rule from the one above.</p>
                                </div>
                                @if (($pension['revaluationBasis'] ?? '') === 'fixed' || ($pension['escalationInPayment'] ?? '') === 'fixed')
                                    <div>
                                        <label for="pensions-{{ $i }}-fixedEscalationRate" class="{{ $label }}">Fixed increase (%/yr)</label>
                                        <input id="pensions-{{ $i }}-fixedEscalationRate" type="text" inputmode="decimal" placeholder="{{ rtrim(rtrim(number_format(\RetireForecast\FinanceEngine\Dto\DbPension::DEFAULT_FIXED_ESCALATION_BPS / 100, 2), '0'), '.') }}" wire:model="pensions.{{ $i }}.fixedEscalationRate" class="{{ $field }}">
                                        @error('pensions.'.$i.'.fixedEscalationRate') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                        <p class="mt-1 text-xs text-gray-500">The rate your scheme rules grant. Leave blank and we use {{ rtrim(rtrim(number_format(\RetireForecast\FinanceEngine\Dto\DbPension::DEFAULT_FIXED_ESCALATION_BPS / 100, 2), '0'), '.') }}%, which we tell you about in your results.</p>
                                    </div>
                                @endif
                                <div>
                                    <label for="pensions-{{ $i }}-spousePensionFraction" class="{{ $label }}">Survivor fraction (%)</label>
                                    <input id="pensions-{{ $i }}-spousePensionFraction" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.spousePensionFraction" class="{{ $field }}">
                                    <p class="mt-1 text-xs text-gray-500">The % of this pension your partner keeps for life if you die first (schemes are commonly 50%). Leave blank if it stops on your death.</p>
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-commutationLumpSum" class="{{ $label }}">Commutation lump sum (£, optional)</label>
                                    <input id="pensions-{{ $i }}-commutationLumpSum" type="text" inputmode="decimal" wire:model="pensions.{{ $i }}.commutationLumpSum" class="{{ $field }}">
                                    <p class="mt-1 text-xs text-gray-500">A tax-free lump sum taken at retirement in exchange for a permanently lower pension.</p>
                                </div>
                                <div>
                                    <label for="pensions-{{ $i }}-commutationFactor" class="{{ $label }}">Commutation factor (optional)</label>
                                    <input id="pensions-{{ $i }}-commutationFactor" type="text" inputmode="decimal" placeholder="12" wire:model="pensions.{{ $i }}.commutationFactor" class="{{ $field }}">
                                    <p class="mt-1 text-xs text-gray-500">£ of lump sum given per £1/yr of pension you give up (schemes are commonly 12). Only used if you take a lump sum.</p>
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
                <p class="mt-1 text-sm text-gray-600">Rent, annuities, disability benefits or anything else not already captured as a pension. Pick <strong>Disability benefit</strong> for DLA / AA / PIP — it's always treated as tax-free and left out of the Pension Credit income test, so it can't be mis-taxed.</p>
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
                                <option value="disability_benefit">Disability benefit &mdash; care / daily living (tax-free)</option>
                                <option value="disability_benefit_mobility">Disability benefit &mdash; mobility (tax-free)</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label for="incomeStreams-{{ $i }}-grossAnnual" class="text-xs text-gray-600">Amount</label>
                            <div class="flex gap-1">
                                <input id="incomeStreams-{{ $i }}-grossAnnual" type="text" inputmode="decimal" wire:model="incomeStreams.{{ $i }}.grossAnnual" class="{{ $field }}">
                                <select aria-label="Pay frequency for this income" wire:model="incomeStreams.{{ $i }}.frequency" class="{{ $field }} w-20">
                                    <option value="annual">/yr</option>
                                    <option value="monthly">/mo</option>
                                    <option value="four_weekly">/4wk</option>
                                    <option value="weekly">/wk</option>
                                </select>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">DWP pays State Pension and DLA / AA every <strong>4 weeks</strong>.</p>
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
                        <div class="sm:col-span-6">
                            <label for="incomeStreams-{{ $i }}-note" class="text-xs text-gray-600">Note (optional)</label>
                            <input id="incomeStreams-{{ $i }}-note" type="text" maxlength="120" wire:model="incomeStreams.{{ $i }}.note" class="{{ $field }}" placeholder="What is it and where it's from? e.g. Aviva annuity, or the buy-to-let flat in Leeds">
                            <p class="mt-1 text-xs text-gray-500">Just a label for your own reference. It doesn't affect the forecast.</p>
                            @error('incomeStreams.'.$i.'.note') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
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
                <legend class="{{ $legend }}">One-off capital receipts</legend>
                <p class="mt-1 text-sm text-gray-600">A documented lump you expect to receive — a family gift, an inheritance, the sale of something outside this plan. It is added to your cash savings in that year. Money from outside the plan is entered here, never assumed.</p>
                @foreach ($capitalReceipts as $i => $receipt)
                    <div wire:key="capital-receipt-{{ $i }}" class="mt-4 grid items-end gap-3 sm:grid-cols-5">
                        <div>
                            <label for="capitalReceipts-{{ $i }}-ownerId" class="text-xs text-gray-600">Received by</label>
                            <select id="capitalReceipts-{{ $i }}-ownerId" wire:model="capitalReceipts.{{ $i }}.ownerId" class="{{ $field }}">
                                @foreach ($ownerOptions as $o)<option value="{{ $o['id'] }}">{{ $o['label'] }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="capitalReceipts-{{ $i }}-year" class="text-xs text-gray-600">Year</label>
                            <input id="capitalReceipts-{{ $i }}-year" type="text" inputmode="numeric" wire:model="capitalReceipts.{{ $i }}.year" class="{{ $field }}" @error('capitalReceipts.'.$i.'.year') aria-invalid="true" @enderror>
                            @error('capitalReceipts.'.$i.'.year') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="capitalReceipts-{{ $i }}-amount" class="text-xs text-gray-600">Amount (£, today's money)</label>
                            <input id="capitalReceipts-{{ $i }}-amount" type="text" inputmode="decimal" wire:model="capitalReceipts.{{ $i }}.amount" class="{{ $field }}" @error('capitalReceipts.'.$i.'.amount') aria-invalid="true" @enderror>
                            @error('capitalReceipts.'.$i.'.amount') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="capitalReceipts-{{ $i }}-label" class="text-xs text-gray-600">What / from whom</label>
                            <input id="capitalReceipts-{{ $i }}-label" type="text" wire:model="capitalReceipts.{{ $i }}.label" class="{{ $field }}" placeholder="e.g. family gift">
                        </div>
                        <button type="button" wire:click="removeCapitalReceipt({{ $i }})" class="mb-2 text-sm text-red-700 underline">Remove</button>
                    </div>
                @endforeach
                <button type="button" wire:click="addCapitalReceipt" class="mt-4 rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-100">+ Add receipt</button>
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
                            <p class="mt-1 text-xs text-gray-500">Maintenance and insurance. Put council tax in the box below instead, not here.</p>
                        </div>
                        <div>
                            <label for="property-councilTax" class="{{ $label }}">Council tax (£/yr)</label>
                            <input id="property-councilTax" type="text" inputmode="decimal" wire:model="property.councilTax" class="{{ $field }}">
                            <p class="mt-1 text-xs text-gray-500">Your annual bill, before any discount. Entered here it can fall: 25% off automatically once only one of you is left, and Council Tax Reduction if your income and savings qualify. Left blank, whatever is inside your running costs above is charged in full for the whole forecast.</p>
                        </div>
                        <div>
                            <label for="property-councilTaxDisabledBand" class="{{ $label }}">Disabled band reduction (optional)</label>
                            <select id="property-councilTaxDisabledBand" wire:model="property.councilTaxDisabledBand" class="{{ $field }}">
                                <option value="">Not claiming it</option>
                                @foreach (\RetireForecast\FinanceEngine\Dto\CouncilTaxBand::cases() as $ctBand)
                                    <option value="{{ $ctBand->value }}">Band {{ $ctBand->label() }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Pick your home's band only if a disabled person lives there and the home has a qualifying feature: a second bathroom or kitchen, a room used mainly for their needs, or enough space to use a wheelchair indoors. The bill is then charged at the band below. It is not means-tested, and you can claim it now.</p>
                        </div>
                        <div>
                            <label for="property-growthAssumptionOverride" class="{{ $label }}">Growth override (%/yr)</label>
                            <input id="property-growthAssumptionOverride" type="text" inputmode="decimal" wire:model="property.growthAssumptionOverride" class="{{ $field }}">
                        </div>
                        <div>
                            <label for="property-ownershipShare" class="{{ $label }}">Your ownership share (%, optional)</label>
                            <input id="property-ownershipShare" type="text" inputmode="decimal" placeholder="100" wire:model="property.ownershipShare" class="{{ $field }}">
                            <p class="mt-1 text-xs text-gray-500">If you own only part of the home (e.g. a tenancy in common). Enter the whole property's value, mortgage and costs above; we apply your share to your wealth, running costs, and sale proceeds/CGT. Blank = 100%.</p>
                        </div>
                        <div>
                            <label class="mt-7 flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model.live="property.everLet" class="rounded border-gray-300"> Let out / not always my main home (affects CGT)
                            </label>
                        </div>
                        <div>
                            <label class="mt-7 flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model.live="property.isLet" class="rounded border-gray-300"> I let this home out and live somewhere else
                            </label>
                        </div>
                    </div>

                    {{-- Who else lives here. The care means test MUST disregard the home while one
                         of these people occupies it, and that changes whether care fees can be
                         charged against the bricks at all. See Dto\Property, board card 0055. --}}
                    <div class="mt-4">
                        <label class="flex items-start gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model.live="property.occupiedByQualifyingRelative" class="mt-1 rounded border-gray-300">
                            <span>Someone else lives in this home who would keep it out of a care means test</span>
                        </label>
                        <p class="mt-1 text-xs text-gray-500">
                            Tick this if the home is lived in by a relative aged 60 or over, a relative of any age who is
                            incapacitated, or a child of yours under 18. A council must ignore the home while one of them
                            lives there, so care fees cannot be charged against it. Your husband, wife or civil partner is
                            already covered while they are alive, so you do not need to tick this for them. A carer who
                            gave up their own home to move in may also qualify, but that one is the council's discretion
                            and we do not assume it.
                        </p>
                    </div>

                    {{-- What letting costs. Gross rent is the one figure a landlord never receives:
                         an agent takes a fee, the place stands empty between tenants, and the
                         repairs and safety certificates are the landlord's. Each rate is blank by
                         default and takes the engine's disclosed figure, which the results page
                         states; an explicit 0 is the reader's own. See Dto\Property. --}}
                    @if ($property['isLet'] ?? false)
                        <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-4">
                            <h3 class="font-medium text-gray-900">What letting it costs</h3>
                            <p class="mt-1 text-xs text-gray-600">
                                Each is a share of the rent. Leave one blank and we'll use a typical figure and tell you
                                on the results page what we used. Enter 0 if it genuinely costs you nothing (for example
                                you manage the let yourself).
                            </p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label for="property-lettingManagementRate" class="{{ $label }}">Letting agent (% of rent)</label>
                                    <input id="property-lettingManagementRate" type="text" inputmode="decimal" placeholder="12" wire:model="property.lettingManagementRate" class="{{ $field }}" @error('property.lettingManagementRate') aria-invalid="true" aria-describedby="property-lettingManagementRate-error" @enderror>
                                    @error('property.lettingManagementRate') <p id="property-lettingManagementRate-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                    <p class="mt-1 text-xs text-gray-500">A fully managed service, VAT included.</p>
                                </div>
                                <div>
                                    <label for="property-lettingVoidRate" class="{{ $label }}">Empty periods (% of rent)</label>
                                    <input id="property-lettingVoidRate" type="text" inputmode="decimal" placeholder="8" wire:model="property.lettingVoidRate" class="{{ $field }}" @error('property.lettingVoidRate') aria-invalid="true" aria-describedby="property-lettingVoidRate-error" @enderror>
                                    @error('property.lettingVoidRate') <p id="property-lettingVoidRate-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                    <p class="mt-1 text-xs text-gray-500">Rent you don't get between tenants. 8% is about a month a year.</p>
                                </div>
                                <div>
                                    <label for="property-lettingMaintenanceRate" class="{{ $label }}">Repairs and checks (% of rent)</label>
                                    <input id="property-lettingMaintenanceRate" type="text" inputmode="decimal" placeholder="5" wire:model="property.lettingMaintenanceRate" class="{{ $field }}" @error('property.lettingMaintenanceRate') aria-invalid="true" aria-describedby="property-lettingMaintenanceRate-error" @enderror>
                                    @error('property.lettingMaintenanceRate') <p id="property-lettingMaintenanceRate-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                    <p class="mt-1 text-xs text-gray-500">Repairs, inventory, gas safety and electrical checks.</p>
                                </div>
                            </div>
                            <p class="mt-3 text-xs text-gray-600">
                                Your service charge and ground rent are handled separately: enter them as spending lines,
                                and we treat them as a letting expense while the home is let, so they aren't taxed as profit.
                            </p>
                        </div>
                    @endif

                    {{-- When the mortgage term ends: an interest-only or fixed-term mortgage that can't
                         simply roll on forces a decision. Modelling it — rather than assuming the home is
                         kept for ever — is the difference between refinancing, repaying from savings, or a
                         forced sale, each a distinct plan the user can build and compare. --}}
                    <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-4">
                        <h3 class="font-medium text-gray-900">When the mortgage term ends</h3>
                        <p class="mt-1 text-xs text-gray-600">
                            If the mortgage is interest-only or has a fixed term that ends within the plan, it can't
                            just roll on. Tell us the year it ends and what you'd do. Leave the year blank if it runs
                            the whole plan. Try each choice as a separate what-if to compare them.
                        </p>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="property-mortgageRedemptionYear" class="{{ $label }}">Year the mortgage term ends (optional)</label>
                                <input id="property-mortgageRedemptionYear" type="text" inputmode="numeric" placeholder="e.g. 2030" wire:model="property.mortgageRedemptionYear" class="{{ $field }}" @error('property.mortgageRedemptionYear') aria-invalid="true" aria-describedby="property-mortgageRedemptionYear-error" @enderror>
                                @error('property.mortgageRedemptionYear') <p id="property-mortgageRedemptionYear-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="property-mortgageMaturityAction" class="{{ $label }}">What you'd do then</label>
                                <select id="property-mortgageMaturityAction" wire:model="property.mortgageMaturityAction" class="{{ $field }}">
                                    <option value="refinance">Refinance (new mortgage; payments continue)</option>
                                    <option value="repay_from_capital">Repay it from savings that year</option>
                                    <option value="forced_sale">Sell the home (can't refinance)</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500">Only applies if you set a year. "Sell the home" models a forced sale that year: the equity is freed into your investments and you rent from then on.</p>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label for="property-mortgageRollUpRate" class="{{ $label }}">Lifetime-mortgage roll-up rate (optional, % a year)</label>
                            <input id="property-mortgageRollUpRate" type="text" inputmode="decimal" placeholder="e.g. 6.5" wire:model="property.mortgageRollUpRate" class="{{ $field }} sm:max-w-xs" @error('property.mortgageRollUpRate') aria-invalid="true" aria-describedby="property-mortgageRollUpRate-error" @enderror>
                            @error('property.mortgageRollUpRate') <p id="property-mortgageRollUpRate-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-gray-500">
                                For an <span class="font-medium">equity-release lifetime mortgage with no monthly payments</span>: the
                                outstanding balance above rolls up (compounds) at this fixed rate and is repaid from your estate when
                                the home is eventually sold — capped at the home's value (the No-Negative-Equity Guarantee). Leave blank
                                for a repayment or interest-serviced mortgage (where the balance stays level and any interest is a spending
                                line). Try it with and without payments as separate what-ifs to see the effect on what you leave behind.
                            </p>
                        </div>

                        {{-- A capital-and-interest ("repayment") mortgage. Entering a term switches
                             amortisation on: the balance above falls to zero over the term and the
                             instalment is worked out and charged for you, replacing any "Mortgage"
                             spending line. Blank term = the interest-only behaviour above. --}}
                        <div class="mt-4 border-t border-gray-200 pt-4">
                            <h4 class="font-medium text-gray-900">Repayment (capital &amp; interest) mortgage</h4>
                            <p class="mt-1 text-xs text-gray-600">
                                Fill this in from a lender's illustration (an ESIS / "key facts" sheet) to model an
                                <span class="font-medium">ordinary repayment mortgage</span>: we work out the monthly
                                instalment, charge it as an essential cost, and reduce the balance above to zero over the
                                term — after which you own the home outright and the payment stops. Leave the term blank
                                for an interest-only or lifetime mortgage. <span class="font-medium">Don't also add a
                                "Mortgage" spending line</span> — the instalment replaces it, so it can't be double-counted.
                            </p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <label for="property-mortgageRepaymentTermMonths" class="{{ $label }}">Term (months)</label>
                                    <input id="property-mortgageRepaymentTermMonths" type="text" inputmode="numeric" placeholder="e.g. 192 (16 years)" wire:model.blur="property.mortgageRepaymentTermMonths" class="{{ $field }}" @error('property.mortgageRepaymentTermMonths') aria-invalid="true" aria-describedby="property-mortgageRepaymentTermMonths-error" @enderror>
                                    @error('property.mortgageRepaymentTermMonths') <p id="property-mortgageRepaymentTermMonths-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="property-mortgageRepaymentStartYear" class="{{ $label }}">First payment year</label>
                                    <input id="property-mortgageRepaymentStartYear" type="text" inputmode="numeric" placeholder="e.g. 2026" wire:model.blur="property.mortgageRepaymentStartYear" class="{{ $field }}" @error('property.mortgageRepaymentStartYear') aria-invalid="true" aria-describedby="property-mortgageRepaymentStartYear-error" @enderror>
                                    @error('property.mortgageRepaymentStartYear') <p id="property-mortgageRepaymentStartYear-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="property-mortgageRepaymentStartMonth" class="{{ $label }}">First payment month (1&ndash;12)</label>
                                    <input id="property-mortgageRepaymentStartMonth" type="text" inputmode="numeric" placeholder="e.g. 9 (September)" wire:model.blur="property.mortgageRepaymentStartMonth" class="{{ $field }}" @error('property.mortgageRepaymentStartMonth') aria-invalid="true" aria-describedby="property-mortgageRepaymentStartMonth-error" @enderror>
                                    @error('property.mortgageRepaymentStartMonth') <p id="property-mortgageRepaymentStartMonth-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="property-mortgageRepaymentRate" class="{{ $label }}">Interest rate (% a year)</label>
                                    <input id="property-mortgageRepaymentRate" type="text" inputmode="decimal" placeholder="e.g. 6.23" wire:model.blur="property.mortgageRepaymentRate" class="{{ $field }}" @error('property.mortgageRepaymentRate') aria-invalid="true" aria-describedby="property-mortgageRepaymentRate-error" @enderror>
                                    @error('property.mortgageRepaymentRate') <p id="property-mortgageRepaymentRate-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="property-mortgageRepaymentInitialMonths" class="{{ $label }}">Deal length (months, optional)</label>
                                    <input id="property-mortgageRepaymentInitialMonths" type="text" inputmode="numeric" placeholder="e.g. 60 (5-year fix)" wire:model.blur="property.mortgageRepaymentInitialMonths" class="{{ $field }}" @error('property.mortgageRepaymentInitialMonths') aria-invalid="true" aria-describedby="property-mortgageRepaymentInitialMonths-error" @enderror>
                                    @error('property.mortgageRepaymentInitialMonths') <p id="property-mortgageRepaymentInitialMonths-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="property-mortgageRepaymentRevertRate" class="{{ $label }}">Rate after the deal (% a year, optional)</label>
                                    <input id="property-mortgageRepaymentRevertRate" type="text" inputmode="decimal" placeholder="e.g. 7.24" wire:model.blur="property.mortgageRepaymentRevertRate" class="{{ $field }}" @error('property.mortgageRepaymentRevertRate') aria-invalid="true" aria-describedby="property-mortgageRepaymentRevertRate-error" @enderror>
                                    @error('property.mortgageRepaymentRevertRate') <p id="property-mortgageRepaymentRevertRate-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">
                                Leave the deal length and reversion rate blank if one rate runs for the whole term. If you
                                set both, the payment is recalculated when the deal ends &mdash; exactly as a lender's
                                illustration shows it stepping up.
                            </p>
                        </div>
                    </div>

                    {{-- Capital gains on sale: only part of the gain is relieved when the home was let
                         or not always the main residence. Occupation drives the relief, not the mortgage
                         type (gov.uk HS283), so this captures the lived-in vs let timeline. --}}
                    @if ($property['everLet'] ?? false)
                        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4">
                            <h3 class="font-medium text-gray-900">Capital gains on sale</h3>
                            <p class="mt-1 text-xs text-gray-600">
                                Because this home was let or wasn't always your main residence, only part of the gain is
                                relieved (Private Residence Relief). Enter when you bought it, what it cost, and the periods
                                you lived in it as your main home vs let it out. What matters is whether you
                                <strong>lived in it as your home</strong>, not the mortgage type.
                                <a href="https://www.gov.uk/tax-sell-home" target="_blank" rel="noopener" class="underline">gov.uk: Private Residence Relief</a>.
                            </p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <label for="cgt-purchasePrice" class="{{ $label }}">Purchase price (£)</label>
                                    <input id="cgt-purchasePrice" type="text" inputmode="decimal" wire:model.blur="property.cgtHistory.purchasePrice" class="{{ $field }}">
                                </div>
                                <div>
                                    <label for="cgt-acquisitionYear" class="{{ $label }}">Year bought</label>
                                    <input id="cgt-acquisitionYear" type="number" wire:model.blur="property.cgtHistory.acquisitionYear" class="{{ $field }}" placeholder="e.g. 2005">
                                </div>
                                <div>
                                    <label for="cgt-improvementCosts" class="{{ $label }}">Buying &amp; improvement costs (£)</label>
                                    <input id="cgt-improvementCosts" type="text" inputmode="decimal" wire:model.blur="property.cgtHistory.improvementCosts" class="{{ $field }}">
                                    <p class="mt-1 text-xs text-gray-500">Legal, stamp duty, extensions — they reduce the gain.</p>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-4">
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model.live="property.cgtHistory.jointlyOwned" class="rounded border-gray-300"> Owned jointly (you + partner) — two allowances, split gain
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model.live="property.cgtHistory.higherRateOnSale" class="rounded border-gray-300"> Higher-rate taxpayer in the sale year (24% not 18%)
                                </label>
                            </div>

                            <div class="mt-4">
                                <p class="{{ $label }}">When it was your main home, let out, or you were away</p>
                                <p class="mt-1 text-xs text-gray-500">Add a row each time its use changed, in order from when you bought it; each runs until the next (the last until you sell). The final 9 months of ownership are always relieved. Use an "away" row for a spell when you kept the home but did not live in it: the allowed part of that still counts as living there.</p>
                                <div class="mt-2 space-y-2">
                                    @foreach ($property['cgtHistory']['periods'] ?? [] as $i => $period)
                                        <div wire:key="cgtperiod-{{ $period['id'] ?? $i }}" class="grid items-end gap-2 sm:grid-cols-12">
                                            <div class="sm:col-span-4">
                                                <label for="cgt-period-{{ $i }}-from" class="text-xs text-gray-600">From year</label>
                                                <input id="cgt-period-{{ $i }}-from" type="number" wire:model.live="property.cgtHistory.periods.{{ $i }}.fromYear" class="{{ $field }}" placeholder="e.g. 2012">
                                            </div>
                                            <div class="sm:col-span-6">
                                                <label for="cgt-period-{{ $i }}-use" class="text-xs text-gray-600">Used as</label>
                                                <select id="cgt-period-{{ $i }}-use" wire:model.live="property.cgtHistory.periods.{{ $i }}.use" class="{{ $field }}">
                                                    <option value="main_home">Lived in as main home</option>
                                                    <option value="let">Let out / not main home</option>
                                                    <option value="absence_any">Away, any reason (up to 3 years counts)</option>
                                                    <option value="absence_uk_work">Away, job elsewhere in the UK (up to 4 years counts)</option>
                                                    <option value="absence_abroad">Away, working abroad (no limit)</option>
                                                </select>
                                            </div>
                                            <button type="button" wire:click="removeCgtPeriod({{ $i }})" class="mb-2 text-sm text-red-700 underline sm:col-span-2">Remove</button>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button type="button" wire:click="addCgtPeriod('main_home')" class="rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">+ Main-home period</button>
                                    <button type="button" wire:click="addCgtPeriod('let')" class="rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">+ Let period</button>
                                    <button type="button" wire:click="addCgtPeriod('absence_any')" class="rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">+ Away period</button>
                                </div>
                            </div>

                            @if ($cgtPreview)
                                <dl class="mt-4 grid gap-3 rounded-md bg-white p-3 text-sm sm:grid-cols-5" aria-live="polite">
                                    <div><dt class="text-gray-500">Owned</dt><dd class="font-semibold">{{ $cgtPreview['ownedYears'] }} yrs</dd></div>
                                    <div><dt class="text-gray-500">Lived in / let</dt><dd class="font-semibold">{{ $cgtPreview['livedInYears'] }} / {{ $cgtPreview['letYears'] }} yrs</dd></div>
                                    <div><dt class="text-gray-500">Away, still counted</dt><dd class="font-semibold">{{ $cgtPreview['awayYears'] }} yrs</dd></div>
                                    <div><dt class="text-gray-500">Gain relieved</dt><dd class="font-semibold">{{ $cgtPreview['reliefPercent'] }}%</dd></div>
                                    <div><dt class="text-gray-500">Estimated CGT{{ $cgtPreview['owners'] > 1 ? ' (2 owners)' : '' }}</dt><dd class="font-semibold tabular-nums">{{ $cgtPreview['estimatedCgt'] }}</dd></div>
                                </dl>
                                <p class="mt-1 text-xs text-gray-500">Indicative, on the home's current value (the forecast uses the sale price less selling costs). "Away, still counted" is the part of your away time that Private Residence Relief allows after its limits: 3 years in total for any reason, 4 years in total for a job elsewhere in the UK, no limit working abroad. An away period only counts if you lived there before it, and came back after it (a work absence does not need the coming back). <a href="https://www.gov.uk/tax-sell-home/absence-from-home" target="_blank" rel="noopener" class="underline">gov.uk: living away</a>.</p>
                            @endif
                        </div>
                    @endif
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
                        <div wire:key="expline-{{ $line['id'] ?? $i }}" class="grid items-end gap-2 sm:grid-cols-12 {{ ($line['included'] ?? true) === false ? 'opacity-60' : '' }}">
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

                            {{-- Include/exclude toggle: switch a cost off to see the effect (the live
                                 preview moves) without losing it — it stays here, but counts £0 until
                                 switched back on. Live so the preview recomputes on toggle. --}}
                            <label class="flex items-center gap-2 text-xs text-gray-700 sm:col-span-12">
                                <input type="checkbox" wire:model.live="expenseLines.{{ $i }}.included">
                                Include this cost in the forecast
                                @if (($line['included'] ?? true) === false)
                                    <span class="rounded bg-gray-200 px-1 text-gray-600">excluded — kept but not counted</span>
                                @endif
                            </label>

                            @if (($line['category'] ?? '') === 'self_investment')
                                <label class="flex items-center gap-2 text-xs text-gray-700 sm:col-span-12">
                                    <input type="checkbox" wire:model="expenseLines.{{ $i }}.savedAsAsset">
                                    This is <strong>saved</strong> (builds your net worth) rather than spent — counted as a contribution, not as spending.
                                </label>
                            @endif

                            {{-- Per-line cost condition: a contingent cost (a mortgage that ends with the
                                 home, a commute that ends at retirement) shouldn't be charged in every
                                 housing variant or for life. Auto-classified by label, overridable here.
                                 Not shown for saved self-investment (never a contingent cost). --}}
                            @unless (($line['category'] ?? '') === 'self_investment' && ($line['savedAsAsset'] ?? false))
                                <div class="sm:col-span-12">
                                    <label for="expenseLines-{{ $i }}-condition" class="text-xs text-gray-600">Applies</label>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <select id="expenseLines-{{ $i }}-condition" wire:model.live="expenseLines.{{ $i }}.condition" class="{{ $field }} sm:max-w-xs">
                                            <option value="">Auto (by description)</option>
                                            <option value="always">Always</option>
                                            <option value="while_owning_home">Only while you own this home</option>
                                            <option value="while_mortgaged">Only while the mortgage runs</option>
                                            <option value="while_working">Only while you are working</option>
                                        </select>
                                        @if (($line['condition'] ?? '') === '')
                                            <span class="text-xs text-gray-500">{{ $conditionHints[$i] ?? '' }}</span>
                                        @endif
                                    </div>
                                </div>

                                {{-- A service charge often BUYS the water and the electricity. The charge
                                     stops when the flat is sold; the utilities do not, because a house or
                                     a park home still has to be heated. Shown only on a cost that dies
                                     with the home, which is the only place the figure means anything. --}}
                                @if (($spendKeepsUtilities[$i] ?? false))
                                    <div class="sm:col-span-12 rounded-md bg-gray-50 p-2">
                                        <label for="expenseLines-{{ $i }}-utilities" class="text-xs text-gray-600">
                                            Of that, how much buys water, gas or electricity? (£ / year, leave blank for none)
                                        </label>
                                        <input id="expenseLines-{{ $i }}-utilities" type="text" inputmode="decimal" wire:model="expenseLines.{{ $i }}.utilities" class="{{ $field }} sm:max-w-xs" @error('expenseLines.'.$i.'.utilities') aria-invalid="true" @enderror>
                                        <p class="mt-1 text-xs text-gray-500">
                                            Selling the home stops the rest of this cost. This part carries on, because you
                                            still have to heat and plumb wherever you live next.
                                        </p>
                                        @error('expenseLines.'.$i.'.utilities') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            @endunless

                            {{-- Spending changes with age (the "smile"): the £/year above holds from the
                                 start, and each change takes effect from that age (the first person's age).
                                 Shown only for an always-charged spend line — a contingent cost is flat and
                                 stops by its condition, so the assembler ignores bands on it. --}}
                            @if ($spendBandable[$i] ?? false)
                                <div class="sm:col-span-12 rounded-md bg-gray-50 p-2">
                                    @if (count($line['bands'] ?? []) > 0)
                                        <p class="text-xs text-gray-600">Spending changes with age (today's money). Each change applies from that age onward.</p>
                                        <div class="mt-1 space-y-1">
                                            @foreach ($line['bands'] ?? [] as $j => $band)
                                                <div wire:key="band-{{ $line['id'] ?? $i }}-{{ $j }}" class="flex flex-wrap items-center gap-2">
                                                    <span class="text-xs text-gray-600">From age</span>
                                                    <input type="text" inputmode="numeric" wire:model="expenseLines.{{ $i }}.bands.{{ $j }}.fromAge" class="{{ $field }} w-20" @error('expenseLines.'.$i.'.bands.'.$j.'.fromAge') aria-invalid="true" @enderror>
                                                    <span class="text-xs text-gray-600">spend £</span>
                                                    <input type="text" inputmode="decimal" wire:model="expenseLines.{{ $i }}.bands.{{ $j }}.amount" class="{{ $field }} w-28" @error('expenseLines.'.$i.'.bands.'.$j.'.amount') aria-invalid="true" @enderror>
                                                    <span class="text-xs text-gray-600">/ year</span>
                                                    <button type="button" wire:click="removeSpendBand({{ $i }}, {{ $j }})" class="text-xs text-red-700 underline">Remove</button>
                                                    @error('expenseLines.'.$i.'.bands.'.$j.'.fromAge') <span class="w-full text-xs text-red-700">{{ $message }}</span> @enderror
                                                    @error('expenseLines.'.$i.'.bands.'.$j.'.amount') <span class="w-full text-xs text-red-700">{{ $message }}</span> @enderror
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    <button type="button" wire:click="addSpendBand({{ $i }})" class="mt-1 text-xs text-indigo-700 underline">
                                        {{ count($line['bands'] ?? []) > 0 ? '+ Add another age change' : '+ Does this change as you age?' }}
                                    </button>
                                </div>
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

                <div class="mt-5 grid gap-4 sm:grid-cols-2 sm:max-w-2xl">
                    <div>
                        <label for="expense-survivorFactor" class="{{ $label }}">Survivor spend (% of couple's)</label>
                        <input id="expense-survivorFactor" type="text" inputmode="decimal" wire:model="expense.survivorFactor" class="{{ $field }}">
                    </div>
                    <div>
                        <label for="expense-safetyBufferMonths" class="{{ $label }}">Safety buffer (months of essentials)</label>
                        <input id="expense-safetyBufferMonths" type="number" wire:model="expense.safetyBufferMonths" class="{{ $field }}" @error('expense.safetyBufferMonths') aria-invalid="true" @enderror>
                        <p class="mt-1 text-xs text-gray-500">The cashflow ladder flags any year usable money falls below this many months of essential spending. 0 = only flag running out entirely.</p>
                        @error('expense.safetyBufferMonths') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="expense-propertyCostsGrowthPct" class="{{ $label }}">Home-ownership costs rise above inflation by (% a year)</label>
                        <input id="expense-propertyCostsGrowthPct" type="text" inputmode="decimal" wire:model="expense.propertyCostsGrowthPct" class="{{ $field }}" placeholder="{{ \App\Livewire\ScenarioBuilder::propertyCostsGrowthDefaultPct() }}" @error('expense.propertyCostsGrowthPct') aria-invalid="true" @enderror>
                        <p class="mt-1 text-xs text-gray-500">Applies to the spend lines charged while you own the home (service charge, ground rent, levies). Insurance, building-safety work and communal energy have all risen faster than prices since 2019, so these do not track inflation.</p>
                        <p class="mt-1 text-xs text-gray-500">
                            <strong>Leave it blank and we assume {{ \App\Livewire\ScenarioBuilder::propertyCostsGrowthDefaultPct() }}% a year above inflation</strong>, the cautious figure from the expert property review of 2026-08-19. Its optimistic case is <strong>1.5%</strong>. Enter <strong>0</strong> if you want them to rise with inflation like everything else. Whichever you pick is shown on your results as an assumption you can challenge. The mortgage payment is contractual and is never escalated.
                        </p>
                        @error('expense.propertyCostsGrowthPct') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
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
                        <div class="sm:col-span-4">
                            <label for="oneOffCosts-{{ $i }}-condition" class="text-xs text-gray-600">Applies</label>
                            <select id="oneOffCosts-{{ $i }}-condition" wire:model="oneOffCosts.{{ $i }}.condition" class="{{ $field }} sm:max-w-md">
                                <option value="">Always, whatever happens to your home</option>
                                <option value="while_owning_home">Only while you own this home (major works on the block)</option>
                            </select>
                            @error('oneOffCosts.'.$i.'.condition') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endforeach
                <button type="button" wire:click="addOneOff" class="mt-1 rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">+ Add one-off cost</button>
                <p class="mt-1 text-xs text-gray-500">A one-off cost is charged in full in the year the first person reaches that age. A major-works bill on a block (a "Section 20" demand) goes here: mark it <em>only while you own this home</em> and it stops if a plan sells the home, because the bill then belongs to the buyer.</p>
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
                        <label for="housing-buyMortgageRate" class="{{ $label }}">Buy mortgage rate (%/yr, optional)</label>
                        <input id="housing-buyMortgageRate" type="text" inputmode="decimal" placeholder="e.g. 6" wire:model="housing.buyMortgageRate" class="{{ $field }}">
                        <p class="mt-1 text-xs text-gray-500">If the new home costs more than the sale frees, the gap is funded first from your savings (cash → GIA → ISA, never pensions), then by an interest-only (retirement interest-only) mortgage at this rate. Blank = no mortgage; any gap your savings can't cover is flagged as unfunded and fails the plan's first year.</p>
                    </div>
                    {{-- The bought home's own costs and growth. Needed for anything that is not an
                         ordinary appreciating freehold — a park home has a flat pitch fee and LOSES
                         value, which the defaults (1% of value, house-price growth) get backwards. --}}
                    <div>
                        <label for="housing-buyRunningCosts" class="{{ $label }}">New home's running costs (£/yr, optional)</label>
                        <input id="housing-buyRunningCosts" type="text" inputmode="decimal" placeholder="e.g. 3000" wire:model.blur="housing.buyRunningCosts" class="{{ $field }}" @error('housing.buyRunningCosts') aria-invalid="true" @enderror>
                        @error('housing.buyRunningCosts') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-gray-500">Maintenance, insurance, council tax — or a <strong>park home's pitch fee</strong>. Blank = we scale your current home's running costs by price, or assume 1% of value a year for upkeep.</p>
                    </div>
                    <div>
                        <label for="housing-buyGrowthReal" class="{{ $label }}">New home's growth (real %/yr, optional)</label>
                        <input id="housing-buyGrowthReal" type="text" inputmode="decimal" placeholder="e.g. -8" wire:model.blur="housing.buyGrowthReal" class="{{ $field }}" @error('housing.buyGrowthReal') aria-invalid="true" @enderror>
                        @error('housing.buyGrowthReal') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-gray-500">
                            Above inflation. Blank = the same house-price growth as everything else.
                            <strong>Enter a negative number for a home that loses value</strong> — a
                            <strong>park home</strong> typically does, because the build standard is revised every
                            8–10 years and the site owner takes up to 10% of the sale price. That erodes what you'd
                            leave behind, so it must be modelled, not assumed away.
                        </p>
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
                </div>

                {{-- Selling costs: a breakdown, each line entered on the basis its quote uses —
                     a % of the sale price (how agents quote) or a flat £ (how conveyancing quotes).
                     The sum is netted off the sale proceeds (shown on the results page). --}}
                <div class="mt-6">
                    <p class="{{ $label }}">Selling costs</p>
                    <p class="mt-1 text-xs text-gray-500">Enter each on the basis it is quoted — a % of the sale price, or a flat fee in £. The total is taken off your sale proceeds.</p>
                    <p class="mt-1 text-xs text-gray-500">The starting figures are for selling a <strong>leasehold</strong> flat. <strong>If your home is freehold, clear the two leasehold lines</strong> (management pack, and licence to assign / notices), because a blank line costs nothing; a freehold conveyancing quote is usually a few hundred pounds less too. If a sale owes capital gains tax, we add the cost of the 60-day return to this total for you and show it on the results page.</p>
                    <div class="mt-2 space-y-3">
                        @foreach ($housing['sellingCosts'] ?? [] as $key => $cost)
                            <div wire:key="sellcost-{{ $key }}" class="grid items-end gap-2 sm:grid-cols-12">
                                <div class="sm:col-span-7">
                                    <label for="sellcost-{{ $key }}-value" class="{{ $label }}">{{ $cost['label'] ?? 'Selling cost' }} {{ ($cost['basis'] ?? 'percent') === 'fixed' ? '(£)' : '(% of sale)' }}</label>
                                    <input id="sellcost-{{ $key }}-value" type="text" inputmode="decimal" wire:model="housing.sellingCosts.{{ $key }}.value" class="{{ $field }}" @error('housing.sellingCosts.'.$key.'.value') aria-invalid="true" @enderror>
                                    @error('housing.sellingCosts.'.$key.'.value') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-5">
                                    <label for="sellcost-{{ $key }}-basis" class="{{ $label }}">Basis</label>
                                    <select id="sellcost-{{ $key }}-basis" wire:model.live="housing.sellingCosts.{{ $key }}.basis" class="{{ $field }}">
                                        <option value="percent">% of sale price</option>
                                        <option value="fixed">Flat fee (£)</option>
                                    </select>
                                </div>
                            </div>
                        @endforeach
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
