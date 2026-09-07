{{-- Where to verify the figures and get impartial help. Signposting only — it points to
     authoritative public sources and to free / independent services, and never advises a course
     of action (so it stays on the guidance side of the banned-phrasing lint). Surfaced on the
     results and compare pages with real contact details. The mortgage and capital-gains columns
     are contextual (shown only when a plan involves a mortgage / sells a let home); the pensions
     & money column always shows; the benefits & debt column shows where a plan runs short or
     carries a mortgage (board card 0052 — a household whose plan fails was pointed at the
     investment world and nowhere else). Neutral wording only. --}}
@props(['showMortgage' => true, 'showCgt' => true, 'showBenefitsDebt' => false])
@php($cols = 1 + ($showMortgage ? 1 : 0) + ($showCgt ? 1 : 0) + ($showBenefitsDebt ? 1 : 0))
<section {{ $attributes->merge(['class' => 'rounded-lg border border-gray-200 bg-white p-5']) }}
    aria-labelledby="sources-heading">
    <h2 id="sources-heading" class="text-xl font-semibold text-gray-900">Check these figures &amp; get help</h2>
    <p class="mt-1 text-sm text-gray-600">
        This forecast rests on your assumptions and on public rules. Here is where to verify them and find free,
        impartial help. These are pointers, not advice; figures such as a mortgage rate or capital gains tax are
        estimates until a professional confirms them for your circumstances.
    </p>

    <div class="mt-4 grid gap-4 @if ($cols === 2) sm:grid-cols-2 @elseif ($cols === 3) sm:grid-cols-3 @elseif ($cols >= 4) sm:grid-cols-2 lg:grid-cols-4 @endif">
        <div class="rounded-md bg-gray-50 p-4">
            <h3 class="text-sm font-semibold text-gray-900">Pensions &amp; money</h3>
            <ul class="mt-2 space-y-2 text-sm text-gray-700">
                <li><a class="font-medium text-blue-700 underline" href="https://www.moneyhelper.org.uk/en/pensions-and-retirement/pension-wise" rel="noopener">Pension Wise</a> — free government pension guidance. <span class="whitespace-nowrap">0800 138 3944</span>.</li>
                <li><a class="font-medium text-blue-700 underline" href="https://www.moneyhelper.org.uk/" rel="noopener">MoneyHelper</a> — free, government-backed money guidance. <span class="whitespace-nowrap">0800 138 7777</span>.</li>
                <li>Find or check a regulated adviser: <a class="text-blue-700 underline" href="https://www.fca.org.uk/consumers/finding-adviser" rel="noopener">FCA</a> · <a class="text-blue-700 underline" href="https://www.unbiased.co.uk/" rel="noopener">Unbiased</a>.</li>
            </ul>
        </div>

        @if ($showMortgage)
            <div class="rounded-md bg-gray-50 p-4">
                <h3 class="text-sm font-semibold text-gray-900">Later-life mortgages</h3>
                <p class="mt-1 text-xs text-gray-500">For the mortgage in this plan (e.g. a retirement interest-only rate). What you could borrow in retirement is set by a lender's affordability check, not by this tool.</p>
                <ul class="mt-2 space-y-2 text-sm text-gray-700">
                    <li><a class="font-medium text-blue-700 underline" href="https://www.equityreleasecouncil.com/members-directory/" rel="noopener">Equity Release Council directory</a> — find a later-life mortgage adviser; a free Decision in Principle shows what is actually obtainable.</li>
                    <li><a class="text-blue-700 underline" href="https://www.moneyhelper.org.uk/en/homes/buying-a-home/retirement-interest-only-mortgages" rel="noopener">MoneyHelper — retirement interest-only mortgages</a>.</li>
                </ul>
            </div>
        @endif

        @if ($showBenefitsDebt)
            <div class="rounded-md bg-gray-50 p-4">
                <h3 class="text-sm font-semibold text-gray-900">Benefits &amp; debt</h3>
                <p class="mt-1 text-xs text-gray-500">For a plan that runs short, or that carries a mortgage. All of these are free and independent, and a benefits check is the usual first step.</p>
                <ul class="mt-2 space-y-2 text-sm text-gray-700">
                    <li><a class="font-medium text-blue-700 underline" href="https://www.citizensadvice.org.uk/debt-and-money/" rel="noopener">Citizens Advice</a> — free help with benefits, debt and priority bills. Adviceline <span class="whitespace-nowrap">0800 144 8848</span> (England), <span class="whitespace-nowrap">0800 702 2020</span> (Wales).</li>
                    <li><a class="font-medium text-blue-700 underline" href="https://nationaldebtline.org/" rel="noopener">National Debtline</a> <span class="whitespace-nowrap">0808 808 4000</span> · <a class="text-blue-700 underline" href="https://www.stepchange.org/" rel="noopener">StepChange</a> <span class="whitespace-nowrap">0800 138 1111</span> — free debt advice charities.</li>
                    <li>Check what you could claim: <a class="text-blue-700 underline" href="https://www.gov.uk/check-benefits-financial-support" rel="noopener">GOV.UK</a> or the <a class="text-blue-700 underline" href="https://benefits-calculator.turn2us.org.uk/" rel="noopener">Turn2us calculator</a> <span class="whitespace-nowrap">0808 802 2000</span>.</li>
                    <li>Mortgage arrears or possession: <a class="text-blue-700 underline" href="https://england.shelter.org.uk/housing_advice" rel="noopener">Shelter</a> <span class="whitespace-nowrap">0808 800 4444</span>; council tax arrears: <a class="text-blue-700 underline" href="https://www.gov.uk/council-tax-arrears" rel="noopener">GOV.UK</a>.</li>
                </ul>
            </div>
        @endif

        @if ($showCgt)
            <div class="rounded-md bg-gray-50 p-4">
                <h3 class="text-sm font-semibold text-gray-900">Capital gains tax</h3>
                <p class="mt-1 text-xs text-gray-500">For the CGT on selling this home — it was let, or not always your main residence, so only part of the gain is relieved.</p>
                <ul class="mt-2 space-y-2 text-sm text-gray-700">
                    <li><a class="font-medium text-blue-700 underline" href="https://www.gov.uk/capital-gains-tax/what-you-pay-it-on" rel="noopener">GOV.UK — Capital Gains Tax</a>; a UK property sale must be reported and paid within 60 days.</li>
                    <li>HMRC CGT enquiries: <span class="whitespace-nowrap">0300 200 3300</span> (Mon–Fri, 8am–6pm).</li>
                    <li>Free tax help on a low income: <a class="text-blue-700 underline" href="https://taxaid.org.uk/" rel="noopener">TaxAid</a> <span class="whitespace-nowrap">0345 120 3779</span>; or a <a class="text-blue-700 underline" href="https://www.tax.org.uk/" rel="noopener">Chartered Tax Adviser</a>.</li>
                </ul>
            </div>
        @endif
    </div>
</section>
