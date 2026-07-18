{{-- One scenario's full report body. Included once per scenario by pdf/results.blade.php;
     expects the variable set produced by ScenarioPdfController::data(). --}}
<h1>RetireForecast summary</h1>
<p class="meta">
    {{ $scenario->name }} &middot; {{ \App\Forecast\ResultPresenter::variantLabel($scenario->variant) }}
    &middot; Tax year {{ $scenario->base_tax_year }} &middot; Generated {{ $generatedAt }}
</p>

<div class="disclaimer">
    <strong>Guidance only, not financial advice.</strong>
    This summary illustrates the consequences of the figures and assumptions entered. It does not recommend a
    course of action. For free, impartial guidance see MoneyHelper (moneyhelper.org.uk) and Pension Wise, or
    speak to an FCA-regulated adviser.
</div>

@if ($presented)
    <h2>Will the money last? (Monte Carlo, per option)</h2>
    @if ($mcRun)
        <p class="muted">{{ ucfirst($mcRun['mode']) }} run &middot; {{ number_format($mcRun['paths']) }} simulated
            futures &middot; seed {{ $mcRun['seed'] }}@if ($mcRun['date']) &middot; run {{ $mcRun['date'] }}@endif.</p>
    @endif
    <table>
        <thead>
            <tr>
                <th>Option</th>
                <th class="num">Essentials always met</th>
                <th class="num">Full spend met</th>
                <th class="num">Ran out at some point</th>
                <th class="num">If so, typically by</th>
                <th class="num">Median usable (excl. home)</th>
                <th class="num">Median total (incl. home equity)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($presented['comparison']['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['successEssentials'] }}</td>
                    <td class="num">{{ $row['successFullSpend'] }}</td>
                    <td class="num">{{ $row['depletionRate'] }}</td>
                    <td class="num">{{ $row['medianDepletionYear'] ?? '—' }}</td>
                    <td class="num">{{ $row['medianUsable'] ?? '—' }}</td>
                    <td class="num">{{ $row['medianTerminal'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="note">No completed Monte Carlo run yet; the deterministic central projection is shown below. Run the
        full simulation on the results page to add the longevity / run-out-of-money summary.</p>
@endif

@if ($shock)
    <h2>Pension lump-sum tax shock</h2>
    <p class="muted">
        First flexible withdrawal: {{ $shock['kind'] }} by {{ $shock['ownerLabel'] }} at age {{ $shock['atAge'] }}.
        @if ($shock['workingAssumed']) Other income assumed: {{ $shock['otherIncome'] }} (still working). @endif
    </p>
    <table>
        <tbody>
            @foreach ($shock['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['value'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($shock['hasOverDeduction'] && $shock['reclaimForm'])
        <p class="note">Emergency tax over-deducted now is reclaimable using HMRC form {{ $shock['reclaimForm'] }}.</p>
    @endif
@endif

@if ($incomeFloor)
    <h2>Essential spending vs secure income</h2>
    <p class="muted">At {{ $incomeFloor['year'] }} (age {{ $incomeFloor['ages'] }}), the mature point when every
        guaranteed income source is in payment.</p>
    <table>
        <tbody>
            <tr><td>Essential spending</td><td class="num">{{ $incomeFloor['essentialSpend'] }}</td></tr>
            <tr><td>Secure income (guaranteed for life)</td><td class="num">{{ $incomeFloor['secureIncome'] }}</td></tr>
            <tr><td>Coverage</td><td class="num">{{ $incomeFloor['coveragePct'] }}%</td></tr>
            @if ($incomeFloor['surplus'])
                <tr><td>Surplus of secure income over essentials</td><td class="num">{{ $incomeFloor['surplus'] }}</td></tr>
            @elseif ($incomeFloor['gap'])
                <tr><td>Shortfall of secure income against essentials</td><td class="num">{{ $incomeFloor['gap'] }}</td></tr>
            @endif
        </tbody>
    </table>
    @if ($incomeFloor['survivor'])
        <p class="muted">At the first death (read at {{ $incomeFloor['survivor']['year'] }}, the mature survivor year), secure income
            covers {{ $incomeFloor['survivor']['coveragePct'] }}% of essentials
            ({{ $incomeFloor['survivor']['secureIncome'] }} of {{ $incomeFloor['survivor']['essentialSpend'] }}) — a State Pension
            stops and a defined-benefit pension may drop to its survivor rate while essentials fall only part-way.</p>
    @endif
@endif

@if ($iht)
    <h2>Inheritance tax on your estate</h2>
    <p class="muted">
        @if ($iht['relationship'] === 'married')
            Modelled as married / civil partnership: the first death passes to the survivor free of Inheritance Tax, and both allowances apply on the second death.
        @elseif ($iht['relationship'] === 'cohabiting')
            Modelled as cohabiting: no spouse exemption and no shared allowances, so the estate is taxed more heavily.
        @endif
        Figures in today's money; headline allowances only (not gifts, trusts or reliefs).
    </p>
    <table>
        <tbody>
            <tr><td>Estate at the final death</td><td class="num">£{{ number_format($iht['secondDeath']['estate']) }}</td></tr>
            <tr><td>Nil-rate band applied</td><td class="num">£{{ number_format($iht['secondDeath']['nrb']) }}</td></tr>
            @if ($iht['secondDeath']['rnrb'] > 0)
                <tr><td>Residence nil-rate band applied</td><td class="num">£{{ number_format($iht['secondDeath']['rnrb']) }}</td></tr>
            @endif
            <tr><td>Taxable estate</td><td class="num">£{{ number_format($iht['secondDeath']['taxable']) }}</td></tr>
            @if ($iht['firstDeath'] && $iht['firstDeath']['tax'] > 0)
                <tr><td>Inheritance Tax on the first death</td><td class="num">£{{ number_format($iht['firstDeath']['tax']) }}</td></tr>
            @endif
            <tr><td><strong>Inheritance Tax due</strong></td><td class="num"><strong>£{{ number_format($iht['total']) }}</strong></td></tr>
        </tbody>
    </table>
    @if ($iht['pensionsIncluded'])
        <p class="muted">Unused pension pots are counted in the estate (the April 2027 rule).</p>
    @endif
@endif

<h2>Spending budget</h2>
<table>
    <thead>
        <tr><th>Category</th><th>Item</th><th class="num">Annual amount</th></tr>
    </thead>
    <tbody>
        @foreach ($budget['tiers'] as $tier)
            @foreach ($tier['lines'] as $line)
                <tr>
                    <td>{{ $tier['label'] }}</td>
                    <td>{{ $line['label'] }}@if ($line['saved']) <span class="muted">(saved)</span>@endif</td>
                    <td class="num">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
            <tr>
                <td></td>
                <td><strong>{{ $tier['label'] }} subtotal</strong></td>
                <td class="num"><strong>{{ $tier['subtotal'] }}</strong></td>
            </tr>
        @endforeach
        <tr>
            <td></td>
            <td><strong>Total spending</strong></td>
            <td class="num"><strong>{{ $budget['spendingTotal'] }}</strong></td>
        </tr>
        @if ($budget['hasSaving'])
            <tr>
                <td></td>
                <td>Saved (builds net worth, not spend)</td>
                <td class="num">{{ $budget['savingTotal'] }}</td>
            </tr>
        @endif
    </tbody>
</table>

@if ($plsa)
    <h2>Retirement Living Standards benchmark</h2>
    <p class="muted">
        Comparable annual spending of {{ $plsa['comparableSpend'] }} for a {{ $plsa['composition'] }},
        on the PLSA basis (excludes rent and mortgage, includes home running costs).
        @if ($plsa['tierReachedLabel'])
            Reaches the {{ $plsa['tierReachedLabel'] }} standard.
        @else
            Below the Minimum standard.
        @endif
    </p>
    <table>
        <thead>
            <tr><th>Standard</th><th class="num">Yardstick (annual)</th><th>Reached</th></tr>
        </thead>
        <tbody>
            @foreach ($plsa['tiers'] as $tier)
                <tr>
                    <td>{{ $tier['label'] }}</td>
                    <td class="num">{{ $tier['amount'] }}</td>
                    <td>{{ $tier['met'] ? 'Yes' : 'No' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="note">Source: {{ $plsa['source'] }}, {{ $plsa['edition'] }} (verified {{ $plsa['verifiedOn'] }}).
        Outside-London figures; London is higher. A general yardstick, not a recommendation.</p>
@endif

@if ($saleExplainer)
    @php($se = $saleExplainer)
    <h2>If you sell: where the money comes from and goes</h2>
    <p class="muted">Selling the current home is assumed at {{ $se['proceeds']['salePrice'] }}. After the costs of
        selling, this is what is left to invest — and, if buying a cheaper home, what is left over after that
        purchase. Figures in today's money.</p>
    <table>
        <tbody>
            <tr><td>Sale price</td><td class="num">{{ $se['proceeds']['salePrice'] }}</td></tr>
            @if ($se['proceeds']['hasMortgage'])
                <tr><td>less outstanding mortgage</td><td class="num">−{{ $se['proceeds']['mortgage'] }}</td></tr>
            @endif
            <tr><td>less selling costs{{ $se['sellingCostsAssumed'] ? ' (assumed)' : '' }}</td><td class="num">−{{ $se['proceeds']['sellingCosts'] }}</td></tr>
            @unless ($se['sellingCostsAssumed'])
                @foreach ($se['sellingCostBreakdown'] as $line)
                    <tr><td class="muted">&nbsp;&nbsp;{{ $line['label'] }}@if ($line['detail']) ({{ $line['detail'] }})@endif</td><td class="num">−{{ $line['value'] }}</td></tr>
                @endforeach
            @endunless
            <tr><td>less capital gains tax{{ $se['proceeds']['cgtCharged'] ? '' : ' (main home, fully relieved)' }}</td><td class="num">−{{ $se['proceeds']['cgt'] }}</td></tr>
            @if ($se['cgtDetail'])
                <tr><td class="muted" colspan="2">Gain {{ $se['cgtDetail']['gain'] }}, less {{ $se['cgtDetail']['relievedGain'] }} private-residence relief = {{ $se['cgtDetail']['chargeableGain'] }} chargeable; less {{ $se['cgtDetail']['allowanceUsed'] }} allowance = {{ $se['cgtDetail']['taxableGain'] }} taxed at {{ $se['cgtDetail']['ratePct'] }}.</td></tr>
            @endif
            <tr><td><strong>Net proceeds</strong></td><td class="num"><strong>{{ $se['proceeds']['netProceeds'] }}</strong></td></tr>
        </tbody>
    </table>
    @unless ($se['proceeds']['clearsCosts'])
        <p class="note">On these figures the sale does not cover the mortgage and selling costs, so there are no net proceeds to invest.</p>
    @endunless

    <p class="muted"><strong>If you sell &amp; rent:</strong> all {{ $se['rent']['invested'] }} of the net proceeds is
        invested.@if ($se['rent']['annualRent']) The rent for a home to rent instead — {{ $se['rent']['annualRent'] }} a
        year, in today's money — is then paid from income (a projected future cost, not one you pay now).@endif</p>

    @if ($se['buy'])
        <h3>If you sell &amp; buy</h3>
        <table>
            <tbody>
                <tr><td>Net proceeds</td><td class="num">{{ $se['buy']['netProceeds'] }}</td></tr>
                <tr><td>less the home bought</td><td class="num">−{{ $se['buy']['buyPrice'] }}</td></tr>
                <tr><td>less stamp duty</td><td class="num">−{{ $se['buy']['sdlt'] }}</td></tr>
                <tr><td>less moving costs</td><td class="num">−{{ $se['buy']['movingCosts'] }}</td></tr>
                @if ($se['buy']['fundedFromSavings'])
                    <tr><td>plus from your savings (cash &rarr; GIA &rarr; ISA)</td><td class="num">+{{ $se['buy']['fundedFromSavings'] }}</td></tr>
                @endif
                @if ($se['buy']['mortgage'])
                    <tr><td>plus interest-only mortgage{{ $se['buy']['mortgageInterest'] ? ' (~'.$se['buy']['mortgageInterest'].'/yr interest)' : '' }}</td><td class="num">+{{ $se['buy']['mortgage'] }}</td></tr>
                @endif
                @if ($se['buy']['unfundedGap'])
                    <tr><td><strong>Unfunded gap</strong></td><td class="num"><strong>{{ $se['buy']['unfundedGap'] }}</strong></td></tr>
                @endif
                <tr><td><strong>Surplus invested</strong></td><td class="num"><strong>{{ $se['buy']['surplus'] }}</strong></td></tr>
            </tbody>
        </table>
        @if ($se['buy']['unfundedGap'])
            <p class="note"><strong>{{ $se['buy']['unfundedGap'] }} of this purchase has no funding source.</strong> The
                sale proceeds{{ $se['buy']['fundedFromSavings'] ? ' and all your savings' : '' }} don't cover it and no
                mortgage is configured, so the forecast charges the gap as an unmet cost in year one — the plan fails
                until that money is documented (a mortgage, a receipt, or a cheaper home).</p>
        @elseif ($se['buy']['fundedFromSavings'])
            <p class="note">{{ $se['buy']['fundedFromSavings'] }} of this purchase is funded from savings, drawn
                cash &rarr; GIA &rarr; ISA (never pensions). That money leaves the plan on day
                one{{ $se['buy']['mortgage'] ? ', and the rest of the gap is borrowed' : '' }}.</p>
        @endif
    @endif

    <p class="muted">Invested money is not left idle: it grows at the blended real return of
        {{ $se['blendedReturnPct'] }} a year (above inflation); about {{ $se['incomeYieldPct'] }} of the value is paid
        out each year as taxable income, the rest is capital growth. The cashflow below shows how it is drawn on.</p>
@endif

<h2>Cashflow projection (central estimate)</h2>
<p class="muted">Real terms, to {{ $ladder['finalYear'] }}. The full income-by-source breakdown is in the CSV
    export on the results page.</p>
<table>
    <thead>
        <tr>
            <th>Year</th>
            <th>Age(s)</th>
            <th class="num">Tax</th>
            <th class="num">Spend</th>
            <th class="num">Unmet spend</th>
            <th class="num">Usable (excl. home)</th>
            <th class="num">Total (incl. home equity)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($ladder['rows'] as $row)
            <tr>
                <td>{{ $row['year'] }}</td>
                <td>{{ $row['ages'] }}</td>
                <td class="num">{{ $row['tax'] }}</td>
                <td class="num">{{ $row['spend'] }}</td>
                <td class="num">{{ $row['shortfall'] ?? '—' }}</td>
                <td class="num">{{ $row['usableWealth'] }}</td>
                <td class="num">{{ $row['totalWealth'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="disclaimer">
    <strong>Check these figures &amp; get help.</strong> Pointers, not advice; a mortgage rate or capital gains tax
    is an estimate until confirmed for your circumstances.<br>
    Pensions &amp; money: Pension Wise 0800 138 3944 · MoneyHelper 0800 138 7777 (moneyhelper.org.uk) · find/check an
    adviser at fca.org.uk.
    @if ($sourcesShowMortgage)
        <br>Later-life mortgages: a broker via the Equity Release Council directory (equityreleasecouncil.com) — a free
        Decision in Principle shows what is actually obtainable.
    @endif
    @if ($sourcesShowCgt)
        <br>Capital gains tax: gov.uk/capital-gains-tax (report &amp; pay within 60 days of a property sale) · HMRC 0300 200 3300 ·
        low-income help from TaxAid 0345 120 3779.
    @endif
</div>

<div class="disclaimer">
    Free, impartial guidance: Pension Wise (pension options at 50+) and MoneyHelper (moneyhelper.org.uk), or an
    FCA-regulated adviser. RetireForecast is an educational illustration only.
</div>
