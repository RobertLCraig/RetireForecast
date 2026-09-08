{{--
    The annuity sub-form, shared by a DC pot and by a non-pension account (board card 0060). One
    copy, because the two differ only in where the money comes from and how the income is taxed,
    and two copies would drift the first time a field was added to one of them.

    $list  the Livewire list this row lives in: 'pensions' or 'accounts'
    $i     the row index
    $row   the row itself, for the two live toggles
    $field the shared input class string
--}}
<div class="mt-3 grid items-end gap-2 sm:grid-cols-2 lg:grid-cols-3">
    <div>
        <label for="{{ $list }}-{{ $i }}-annuityAmount" class="text-xs text-gray-600">Amount to put in (£)</label>
        <input id="{{ $list }}-{{ $i }}-annuityAmount" type="text" inputmode="decimal" wire:model="{{ $list }}.{{ $i }}.annuityAmount" class="{{ $field }}">
        @error($list.'.'.$i.'.annuityAmount') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
        @if ($list === 'pensions')
            <p class="mt-1 text-xs text-gray-500">Pension money, so a <strong>quarter of this comes back to you tax free</strong> and the other three quarters buy the income. Put in £100,000 and you get £25,000 in cash plus an annuity bought with £75,000.</p>
        @endif
    </div>
    <div>
        <label for="{{ $list }}-{{ $i }}-annuityAtAge" class="text-xs text-gray-600">Buy it at age</label>
        <input id="{{ $list }}-{{ $i }}-annuityAtAge" type="number" wire:model.live.debounce.500ms="{{ $list }}.{{ $i }}.annuityAtAge" class="{{ $field }}">
        @error($list.'.'.$i.'.annuityAtAge') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="{{ $list }}-{{ $i }}-annuityIncomeFromAge" class="text-xs text-gray-600">Income starts at age (optional)</label>
        <input id="{{ $list }}-{{ $i }}-annuityIncomeFromAge" type="number" wire:model.live.debounce.500ms="{{ $list }}.{{ $i }}.annuityIncomeFromAge" class="{{ $field }}">
        @error($list.'.'.$i.'.annuityIncomeFromAge') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
        <p class="mt-1 text-xs text-gray-500">Leave blank for income from the day you buy it. A later age is a <strong>deferred</strong> annuity: you hand the money over now and the income starts then, so enter the rate you were quoted for that wait. Nothing is paid if you die first.</p>
    </div>
    <div>
        <label for="{{ $list }}-{{ $i }}-annuityRate" class="text-xs text-gray-600">Annuity rate (%/yr)</label>
        <input id="{{ $list }}-{{ $i }}-annuityRate" type="text" inputmode="decimal" wire:model="{{ $list }}.{{ $i }}.annuityRate" class="{{ $field }}">
        @error($list.'.'.$i.'.annuityRate') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
        <p class="mt-1 text-xs text-gray-500"><strong>We work this out for you</strong> from the age, the increases and the joint-life setting on this form, using published market rates. Change any of those three and this figure changes with them. It is a guide, not a quote: your health, your postcode and the day you buy all move it, so <strong>type your own figure over it if you have a real quote</strong>.</p>
    </div>
    <div>
        <label for="{{ $list }}-{{ $i }}-annuityEscalation" class="text-xs text-gray-600">Increases</label>
        <select id="{{ $list }}-{{ $i }}-annuityEscalation" wire:model.live="{{ $list }}.{{ $i }}.annuityEscalation" class="{{ $field }}">
            <option value="none">Level (flat £, buys less over time)</option>
            <option value="rpi">Rises with inflation (RPI)</option>
            <option value="cpi">Rises with inflation (CPI)</option>
        </select>
    </div>
    <div>
        <label class="flex items-center gap-2 text-xs text-gray-600">
            <input type="checkbox" wire:model="{{ $list }}.{{ $i }}.annuityEnhanced" class="rounded border-gray-300">
            Enhanced (health or lifestyle shortens life expectancy)
        </label>
        <p class="mt-1 text-xs text-gray-500">Insurers pay more to someone in poorer health. With no quote of your own we add {{ $this->enhancedAnnuityUplift() }} to the rate above, which is the cautious end of what the market pays; a serious condition can be worth far more, so get a real quote.</p>
    </div>
    <div class="lg:col-span-3">
        <label class="flex items-center gap-2 text-xs text-gray-600">
            <input type="checkbox" wire:model.live="{{ $list }}.{{ $i }}.annuityJoint" class="rounded border-gray-300">
            Joint life (keeps paying your partner after you die)
        </label>
        @if ($row['annuityJoint'] ?? false)
            <div class="mt-1 max-w-xs">
                <label for="{{ $list }}-{{ $i }}-annuitySurvivorFraction" class="text-xs text-gray-600">Partner keeps (% of the income)</label>
                <input id="{{ $list }}-{{ $i }}-annuitySurvivorFraction" type="text" inputmode="decimal" wire:model.live.debounce.500ms="{{ $list }}.{{ $i }}.annuitySurvivorFraction" class="{{ $field }}" placeholder="50">
                @error($list.'.'.$i.'.annuitySurvivorFraction') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>
</div>
