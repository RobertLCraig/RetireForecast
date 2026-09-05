{{-- One scenario's full report body — a COMPLETE print of the results page, section for
     section, in the same document order and the same visual language (cards, stat tiles,
     verdict pills, row tints). Included once per scenario by pdf/results.blade.php; expects
     the variable set produced by ScenarioPdfController::data(), which is built from the same
     ResultPresenter calls the screen uses, so print and screen cannot drift.

     Charts arrive as SVG data URIs from App\Export\ChartSvg (dompdf runs no JavaScript, so the
     ApexCharts canvases are re-drawn server-side from the same option blobs). Every chart keeps
     its accessible table twin beneath it, as on screen. --}}
<h1>{{ $scenario->name }}</h1>
<p class="meta">
    @if ($householdName){{ $householdName }} &middot; @endif
    base tax year {{ $scenario->base_tax_year }} &middot;
    primary option: {{ \App\Forecast\ResultPresenter::variantLabel($scenario->variant) }} &middot;
    generated {{ $generatedAt }}
    @if ($whatIf)<br>A what-if of <strong>{{ $whatIf['baseName'] }}</strong>.@endif
</p>

<div class="callout">
    <strong>Guidance only, not financial advice.</strong>
    This report illustrates the consequences of the figures and assumptions entered. It does not recommend a
    course of action. For free, impartial guidance see MoneyHelper (moneyhelper.org.uk) and Pension Wise, or
    speak to an FCA-regulated adviser.
</div>

{{-- What this what-if changes vs its base, so the report reads as a variation of the base
     rather than an independent plan. --}}
@if ($whatIf)
    <div class="card">
        <h2>What this what-if changes</h2>
        @if ($whatIf['changes'])
            <table>
                <thead><tr><th>Input</th><th>Base</th><th>This what-if</th></tr></thead>
                <tbody>
                    @foreach ($whatIf['changes'] as $change)
                        <tr>
                            <td>{{ $change['label'] }}</td>
                            <td class="muted">{{ $change['from'] }}</td>
                            <td><strong>{{ $change['to'] }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p>This what-if currently matches its base (no inputs changed).</p>
        @endif
        @if ($whatIf['orphans'])
            <p class="note">Some earlier changes no longer apply because the base was edited since:
                {{ implode(', ', $whatIf['orphans']) }}.</p>
        @endif
    </div>
@endif

{{-- Input-sanity + assumed-figure notes. These carry the "no invisible figures" disclosures,
     so they print high, before the figures they affect. --}}
@if ($inputNotes)
    <div class="callout">
        <strong>A note on your inputs</strong>
        <ul>
            @foreach ($inputNotes as $note)
                <li>{{ $note['text'] }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($runDiff)
    <div class="card">
        <h2>Since your last run</h2>
        <table>
            <thead><tr><th>Figure</th><th>Previously</th><th>Now</th></tr></thead>
            <tbody>
                @foreach ($runDiff as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="muted">{{ $row['from'] }}</td>
                        <td><strong>{{ $row['to'] }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($careNotModelled)
    <div class="callout">
        <strong>Later-life care isn't included in this forecast.</strong>
        These projections don't include the cost of residential or nursing care. It's a real risk: around
        1 in 4 people need care in later life, and self-funded fees run to roughly £1,300–£1,600 a week
        (about £65,000–£85,000 a year), which can be a large and prolonged cost. To see how it would affect
        whether your money lasts, turn on "Model the risk of late-life care costs" in the builder.
    </div>
@endif

{{-- Monte Carlo sections. Absent until a run completes; the deterministic report below always
     prints, so the reader is never left with a blank report. --}}
@if ($presented)
    @php($primary = $presented['variants'][$presented['primary']])
    <div class="card">
        <h2>Will the money last?</h2>
        @if ($mcRun)
            <p class="lede">Under this run's assumptions, across {{ number_format($mcRun['paths']) }} simulated
                futures ({{ $mcRun['mode'] }} run, seed {{ $mcRun['seed'] }}@if ($mcRun['date']), run {{ $mcRun['date'] }}@endif).</p>
        @endif
        <p class="verdict verdict-{{ $primary['verdict']['level'] }}">{{ $primary['verdict']['text'] }}</p>

        <h3>{{ $primary['label'] }}</h3>
        <table class="tiles">
            <tr>
                <td><div class="tile"><p class="tile-label">Essentials always met</p><p class="tile-value">{{ $primary['successEssentials'] }}</p></div></td>
                <td><div class="tile"><p class="tile-label">Full spending met every year</p><p class="tile-value">{{ $primary['successFullSpend'] }}</p><p class="tile-note">in {{ $primary['fullSpendMostYearsThreshold'] }}%+ of years: {{ $primary['successFullSpendMostYears'] ?? '—' }}</p></div></td>
                <td><div class="tile"><p class="tile-label">Chance of running out</p><p class="tile-value">{{ $primary['depletionRate'] }}</p><p class="tile-note">if so, typically by {{ $primary['medianDepletionYear'] ?? '—' }}</p></div></td>
                <td><div class="tile"><p class="tile-label">Usable wealth left (excl. home)</p><p class="tile-value">{{ $primary['usableP50'] ?? '—' }}</p></div></td>
                <td><div class="tile"><p class="tile-label">Total wealth left (incl. home)</p><p class="tile-value">{{ $primary['terminalP50'] }}</p></div></td>
            </tr>
        </table>

        <h3>Every option side by side</h3>
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
        <p class="note">"Chance of running out" counts the simulated futures with at least one year your essential
            spending isn't fully covered by income and savings — a shortfall a future may later recover from as
            guaranteed income catches up. "Wealth left" is the median amount at the very end. So an option can leave
            money at the end yet still have run short along the way — and "total wealth left" includes the equity in
            any home you would still own (its value net of any mortgage still owed), which stays high even when the
            usable cash for day-to-day spending has run out.</p>
    </div>

    @if (! empty($presented['longevity']))
        @php($lg = $presented['longevity'])
        <div class="card">
            <h2>How long the money may need to last</h2>
            <p class="lede">From the same joint-life mortality model the simulation runs, framed around the
                <strong>last survivor</strong> (how long the money has to stretch for a couple). A spread of
                possibilities, not a prediction.</p>
            <table class="tiles">
                <tr>
                    <td><div class="tile"><p class="tile-label">Plan to roughly</p><p class="tile-value">{{ $lg['planYearsP90'] }} years</p><p class="tile-note">a prudent horizon (1 in 10 last this long or longer); median is {{ $lg['planYearsP50'] }} years.</p></div></td>
                    <td><div class="tile"><p class="tile-label">Last survivor reaches</p><p class="tile-value">age {{ $lg['ageP50'] }}</p><p class="tile-note">typically; a low-to-high range of {{ $lg['ageP10'] }}–{{ $lg['ageP90'] }}.</p></div></td>
                    <td><div class="tile"><p class="tile-label">Chance one of you reaches</p><p class="tile-value">{{ $lg['reaches95'] }} to 95</p><p class="tile-note">and {{ $lg['reaches100'] }} to 100 — the tail the median hides.</p></div></td>
                </tr>
            </table>
        </div>
    @endif

    @if (! empty($presented['careImpact']))
        @php($care = $presented['careImpact'])
        <div class="card">
            <h2>The risk of late-life care costs</h2>
            <p class="lede">These projections include the chance of needing residential or nursing care in later
                life. Most people pay nothing, but a minority face very large bills — so this is a <strong>fat
                tail</strong>, shown as a risk rather than a single expected figure.</p>
            <table class="tiles">
                <tr>
                    <td><div class="tile"><p class="tile-label">Chance care costs you something</p><p class="tile-value">{{ $care['sharePct'] }}</p><p class="tile-note">of simulated futures included a care spell your household paid towards.</p></div></td>
                    <td><div class="tile"><p class="tile-label">Typical bill, if it happens</p><p class="tile-value">£{{ number_format($care['medianCost']) }}</p><p class="tile-note">median your household bears across those futures (today's money), after any local-authority support.</p></div></td>
                    <td><div class="tile"><p class="tile-label">A high-end bill</p><p class="tile-value">£{{ number_format($care['p90Cost']) }}</p><p class="tile-note">1 in 10 of the with-care futures cost this much or more.</p></div></td>
                </tr>
            </table>
            <p class="note">Sourced from LaingBuisson self-funder fees (~£1,300–£1,600/week), PSSRU length-of-stay,
                and the Dilnot Commission ~1-in-4 lifetime risk. Each care year is means-tested under the DHSC
                charging rules: full fees while the resident's own assets are above £23,250, then a contribution from
                their income (keeping the Personal Expenses Allowance) with a local authority paying the balance. A
                partner still living in the home shields it from the assessment.</p>
        </div>
    @endif

    @unless ($presented['usableFanAvailable'])
        <div class="callout">These results were calculated before the spendable-money (excluding home) view was
            added, so both charts below show total wealth. Run the forecast again to see your spendable money
            over time.</div>
    @endunless

    {{-- Both fan bases. On screen this is one chart with an "Include home value" checkbox; a
         printed page cannot be toggled, so each basis gets its own chart and table. --}}
    @foreach ([
        ['fan' => $presented['fan'], 'img' => $fanChart],
        ['fan' => $presentedTotal['fan'], 'img' => $fanChartTotal],
    ] as $block)
        @php($fan = $block['fan'])
        <div class="card">
            <h2>Projected {{ $fan['usableBasis'] ? 'spendable money' : 'total wealth' }} over time — {{ $fan['label'] }}</h2>
            <p class="lede">The shaded bands are the range across thousands of simulated futures (10th–90th and
                25th–75th percentiles); the solid line is the median, with half of futures above it and half below.
                Figures are in today's money.
                @if ($fan['usableBasis'])
                    This is your <strong>spendable</strong> money — it excludes your home, which can't pay
                    day-to-day bills unless you sell.
                    @if ($fan['dipsNegative'])
                        Where a band drops <strong>below £0</strong> those futures have run out of savings; the line
                        keeps falling to show the <strong>cumulative shortfall</strong> — the extra money that future
                        would need to carry on spending at the planned level.
                    @else
                        Watch the lower edge: where the bottom band trends toward £0, a meaningful share of futures
                        have run short.
                    @endif
                @else
                    This <strong>includes your home's value</strong> — a net-worth view. The home can't cover
                    day-to-day spending unless sold, so the spendable (excl-home) chart above is the honest
                    "will it last" picture.
                @endif
            </p>
            <img class="chart" src="{{ $block['img'] }}" alt="Fan chart of projected {{ $fan['basisLabel'] }} by year. The full figures are in the table below.">
            @include('pdf.partials.chart-key', ['milestones' => $milestones])
            @if ($fan['usableBasis'])
                <p class="note"><strong>Why the line can climb sharply at the far right:</strong> towards the end only
                    a small number of the simulated futures still have someone alive. With so few left, the median
                    rests on a handful of outcomes, so it jumps around — and it can drift up, because a long-lived
                    survivor's guaranteed income often covers their reduced spending, leaving the remaining pot to
                    keep growing. The far tail is indicative, not a precise figure.</p>
            @endif
            <table>
                <thead>
                    <tr><th>Year</th><th>Age(s)</th><th class="num">10th</th><th class="num">25th</th>
                        <th class="num">Median</th><th class="num">75th</th><th class="num">90th</th></tr>
                </thead>
                <tbody>
                    @foreach ($fan['rows'] as $row)
                        <tr>
                            <td>{{ $row['year'] }}</td>
                            <td>{{ $row['ages'] ?? '—' }}</td>
                            <td class="num">{{ $row['p10'] }}</td>
                            <td class="num">{{ $row['p25'] }}</td>
                            <td class="num">{{ $row['p50'] }}</td>
                            <td class="num">{{ $row['p75'] }}</td>
                            <td class="num">{{ $row['p90'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
@else
    <div class="card">
        <p class="lede">No completed Monte Carlo run yet; the deterministic central projection is shown below. Run
            the full simulation on the results page to add the longevity / run-out-of-money summary and its charts.</p>
    </div>
@endif

{{-- Walled-off advice-style interpretation, printed only when the interpret gate allowed it
     into the data set (the wording itself originates in App\Compliance\Interpretation). --}}
@if ($interpretation)
    <div class="card">
        <h2>What this suggests</h2>
        <p class="note">Advice-style interpretation, enabled for your account. This is a directive reading of the
            figures above, not a regulated personal recommendation.</p>
        <ul>
            @foreach ($interpretation as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($shock)
    <div class="card">
        <h2>The pension lump-sum tax shock</h2>
        <p class="lede">
            Your first flexible withdrawal — a {{ $shock['kind'] }} of {{ $shock['gross'] }} by
            {{ $shock['ownerLabel'] }} at age {{ $shock['atAge'] }}, at {{ $shock['taxYear'] }} rates.
            @if ($shock['emergencyApplied'])
                Because it is the first such withdrawal, the provider has to tax it on the emergency (Month-1)
                basis, which over-deducts up front.
            @endif
        </p>
        <table class="tiles">
            <tr>
                <td><div class="tile tile-green"><p class="tile-label">Tax-free (25%)</p><p class="tile-value">{{ $shock['taxFree'] }}</p></div></td>
                <td><div class="tile"><p class="tile-label">Taxable portion</p><p class="tile-value">{{ $shock['taxable'] }}</p></div></td>
                <td><div class="tile tile-amber"><p class="tile-label">Tax taken at source</p><p class="tile-value">{{ $shock['taxAtSource'] }}</p></div></td>
            </tr>
        </table>
        @if ($shock['hasOverDeduction'])
            <p>That is <strong>{{ $shock['overDeduction'] }}</strong> more than the {{ $shock['marginalTax'] }}
                actually due at your marginal rate. The excess can be reclaimed from
                HMRC{{ $shock['reclaimForm'] ? ' using form '.$shock['reclaimForm'] : '' }}, leaving
                {{ $shock['netReceived'] }} in hand until the refund.</p>
        @else
            <p>Tax taken at source matches the {{ $shock['marginalTax'] }} due, leaving {{ $shock['netReceived'] }}
                in hand; there is nothing to reclaim.</p>
        @endif
        <h3>The full breakdown</h3>
        <table>
            <tbody>
                @foreach ($shock['rows'] as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
        @if ($shock['warnings'])
            <ul>
                @foreach ($shock['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
        <p class="note">
            @if ($shock['workingAssumed'])
                Assumes other taxable income that year of {{ $shock['otherIncome'] }} (the owner's current salary,
                as they are still working at this age).
            @else
                Assumes no other employment income that year, as the plan retires the owner by this age. State
                Pension and any defined-benefit income in payment are modelled in the full forecast below, not in
                this first-withdrawal illustration.
            @endif
        </p>
    </div>
@endif

@if ($sensitivity)
    <div class="card">
        <h2>How sensitive is this to the assumptions?</h2>
        <p class="lede">The central best-estimate projection run under each sourced assumption set. The spread shows
            how much the answer depends on the assumptions. These are consequences under different assumptions, not
            a recommendation.</p>
        <table>
            <thead>
                <tr>
                    <th>Assumption set</th>
                    <th>Essentials always met</th>
                    <th>Full spend always met</th>
                    <th>Money runs out</th>
                    <th class="num">Total wealth by {{ $sensitivity[0]['finalYear'] }} (incl. home equity)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sensitivity as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['essentialsMet'] ? 'Yes' : 'No' }}</td>
                        <td>{{ $row['fullSpendMet'] ? 'Yes' : 'No' }}</td>
                        <td>{{ $row['depletionYear'] ?? '—' }}</td>
                        <td class="num">{{ $row['terminalWealth'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- Where the money comes from: the entered income sources and capital pots, and how each
     source turns on and off. The counterpart to the spending plan below. --}}
<div class="card">
    <h2>Where your money comes from</h2>
    <p class="lede">The income and capital driving this forecast, as entered. Figures are in today's money;
        <strong>monthly</strong> is the annual figure divided by twelve.</p>

    <h3>Income</h3>
    <table>
        <thead>
            <tr><th>Source</th><th>Whose</th><th class="num">Monthly</th><th class="num">Annual</th>
                <th>Tax</th><th>Starts</th><th>Ends</th></tr>
        </thead>
        <tbody>
            @foreach ($incomePlan['income'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ $row['who'] }}</td>
                    <td class="num">{{ $row['monthly'] }}</td>
                    <td class="num">{{ $row['annual'] }}</td>
                    <td class="muted">{{ $row['taxable'] ? 'taxable' : 'tax-free' }}</td>
                    <td>{{ $row['from'] }}</td>
                    <td>{{ $row['until'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="note">These are the amounts as entered today. What the plan actually receives each year — after
        inflation, retirement, deaths and drawdown — is the income table and chart further down.</p>

    <h3>Capital you can draw on</h3>
    <table>
        <thead>
            <tr><th>Where it is</th><th>Whose</th><th class="num">Value now</th><th>Paid in</th>
                <th>Available</th><th>How it's taxed on the way out</th></tr>
        </thead>
        <tbody>
            @foreach ($incomePlan['capital'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ $row['who'] }}</td>
                    <td class="num">{{ $row['balance'] }}</td>
                    <td>{{ $row['paidIn'] }}</td>
                    <td>{{ $row['access'] }}</td>
                    <td class="muted">{{ $row['tax'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @unless ($incomePlan['hasSavings'])
        <p class="note">No cash, ISA or investment accounts were entered, so this plan starts with
            <strong>no savings to fall back on</strong>. Any savings shown in later years are surplus income that
            has accumulated as cash.</p>
    @endunless

    <h3>How each source changes over time</h3>
    <p class="lede">When each source starts and stops in the projection, and what it pays at each end. Read from
        the same year-by-year figures as the cashflow table.</p>
    <table>
        <thead>
            <tr><th>Source</th><th>First paid</th><th class="num">Amount then</th><th>Last paid</th>
                <th class="num">Amount then</th><th>Biggest year</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($incomePlan['timeline'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ $row['firstYear'] }}</td>
                    <td class="num">{{ $row['firstAmount'] }}</td>
                    <td>{{ $row['lastYear'] }}@if ($row['endsBeforeTheEnd']) <span class="muted">(stops)</span>@endif</td>
                    <td class="num">{{ $row['lastAmount'] }}</td>
                    <td>{{ $row['peakYear'] }}</td>
                    <td class="num">{{ $row['peakAmount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Your spending plan</h2>
    <p class="lede">The budget driving this forecast, in three tiers. Self-investment you mark as saved builds
        your net worth rather than counting as spending. Figures are in today's money;
        <strong>monthly</strong> is the annual figure divided by twelve.</p>
    <table>
        <thead>
            <tr><th>Tier</th><th>Item</th><th class="num">Monthly</th><th class="num">Annual</th></tr>
        </thead>
        <tbody>
            @foreach ($budget['tiers'] as $tier)
                @foreach ($tier['lines'] as $line)
                    <tr>
                        <td>{{ $tier['label'] }}</td>
                        <td>{{ $line['label'] }}@if ($line['saved']) <span class="muted">(saved)</span>@endif@if ($line['computed'] ?? false) <span class="muted">(worked out from your mortgage terms, not typed in)</span>@endif</td>
                        <td class="num">{{ $line['amountMonthly'] }}</td>
                        <td class="num">{{ $line['amount'] }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td></td>
                    <td>{{ $tier['label'] }} subtotal</td>
                    <td class="num">{{ $tier['subtotalMonthly'] }}</td>
                    <td class="num">{{ $tier['subtotal'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td></td>
                <td>Total spending</td>
                <td class="num">{{ $budget['spendingTotalMonthly'] }}</td>
                <td class="num">{{ $budget['spendingTotal'] }}</td>
            </tr>
            @if ($budget['hasSaving'])
                <tr>
                    <td></td>
                    <td>Saved (builds net worth, not spend)</td>
                    <td class="num">{{ $budget['savingTotalMonthly'] }}</td>
                    <td class="num">{{ $budget['savingTotal'] }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

@if ($plsa)
    <div class="card">
        <h2>How your spending compares — PLSA Retirement Living Standards</h2>
        <p class="lede">
            On the same basis the standards use (excluding rent and mortgage, including everyday home running costs),
            your spending of <strong>{{ $plsa['comparableSpend'] }}</strong> a year for a {{ $plsa['composition'] }}
            @if ($plsa['belowMinimum'])
                is below the Minimum standard.
            @else
                reaches the <strong>{{ $plsa['tierReachedLabel'] }}</strong> standard.
            @endif
            These are a general yardstick, not a recommendation.
        </p>
        <table>
            <thead>
                <tr><th>Standard</th><th class="num">Annual budget</th><th>Your spending reaches it</th></tr>
            </thead>
            <tbody>
                @foreach ($plsa['tiers'] as $tier)
                    <tr>
                        <td>{{ $tier['label'] }}@if ($tier['key'] === $plsa['tierReached']) <span class="badge">your level</span>@endif</td>
                        <td class="num">{{ $tier['amount'] }}</td>
                        <td>{{ $tier['met'] ? 'Yes' : 'No' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($plsa['nextTier'] && $plsa['gapToNext'])
            <p>Spending {{ $plsa['gapToNext'] }} a year more would reach the {{ $plsa['nextTierLabel'] }} standard.</p>
        @endif
        <p class="note">Figures are per year, in today's money, for a {{ $plsa['composition'] }} outside London (the
            standards publish higher figures for London). The standards assume you own your home outright, so they
            exclude rent and mortgage payments{{ $plsa['runningCostsIncluded'] ? ', but include your home running costs, which are added here' : '' }}.
            Source: PLSA Retirement Living Standards, {{ $plsa['edition'] }} ({{ $plsa['source'] }}), figures read
            {{ $plsa['verifiedOn'] }}.</p>
    </div>
@endif

@if ($incomeFloor)
    <div class="card">
        <h2>Essential spending vs secure income</h2>
        <p class="lede">In {{ $incomeFloor['year'] }}, when you would be {{ $incomeFloor['ages'] }}, your secure
            income — guaranteed for life and not dependent on your savings lasting (State Pension, defined-benefit
            pensions, annuities and any tax-free income) — covers <strong>{{ $incomeFloor['coveragePct'] }}%</strong>
            of your essential spending. Figures are per year, in today's money.</p>
        <table class="tiles">
            <tr>
                <td><div class="tile"><p class="tile-label">Essential spending</p><p class="tile-value">{{ $incomeFloor['essentialSpend'] }}</p></div></td>
                <td><div class="tile tile-blue"><p class="tile-label">Secure income</p><p class="tile-value">{{ $incomeFloor['secureIncome'] }}</p></div></td>
                @if ($incomeFloor['fullyCovered'])
                    <td><div class="tile tile-green"><p class="tile-label">Secure surplus over essentials</p><p class="tile-value">{{ $incomeFloor['surplus'] ?? $incomeFloor['secureIncome'] }}</p></div></td>
                @else
                    <td><div class="tile tile-amber"><p class="tile-label">Met from savings / pension</p><p class="tile-value">{{ $incomeFloor['gap'] }}</p></div></td>
                @endif
            </tr>
        </table>
        @if ($incomeFloor['sources'])
            <h3>Secure income by source in {{ $incomeFloor['year'] }}</h3>
            <table>
                <thead><tr><th>Secure income source</th><th class="num">Per year</th></tr></thead>
                <tbody>
                    @foreach ($incomeFloor['sources'] as $source)
                        <tr><td>{{ $source['label'] }}</td><td class="num">{{ $source['amount'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @if ($incomeFloor['survivor'])
            @php($sv = $incomeFloor['survivor'])
            <h3>What happens to this floor at the first death</h3>
            <p class="lede">When one of you dies, a State Pension stops and a defined-benefit pension may drop to
                its survivor rate, while essential spending falls only part-way — so the survivor's secure-income
                coverage of essentials can change sharply. Here it
                @if ($incomeFloor['cliff'] > 0)
                    <strong>falls from {{ $incomeFloor['coveragePct'] }}% to {{ $sv['coveragePct'] }}%</strong>.
                @elseif ($incomeFloor['cliff'] < 0)
                    <strong>rises from {{ $incomeFloor['coveragePct'] }}% to {{ $sv['coveragePct'] }}%</strong>.
                @else
                    <strong>holds at about {{ $sv['coveragePct'] }}%</strong>.
                @endif
            </p>
            <table>
                <thead>
                    <tr><th>Phase</th><th class="num">Secure income</th><th class="num">Essential spending</th><th class="num">Coverage</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>While you are both alive ({{ $incomeFloor['year'] }})</td>
                        <td class="num">{{ $incomeFloor['secureIncome'] }}</td>
                        <td class="num">{{ $incomeFloor['essentialSpend'] }}</td>
                        <td class="num">{{ $incomeFloor['coveragePct'] }}%</td>
                    </tr>
                    <tr>
                        <td>After the first death ({{ $sv['year'] }})</td>
                        <td class="num">{{ $sv['secureIncome'] }}</td>
                        <td class="num">{{ $sv['essentialSpend'] }}</td>
                        <td class="num">{{ $sv['coveragePct'] }}%</td>
                    </tr>
                </tbody>
            </table>
            <p class="note">"After the first death" is read at {{ $sv['year'] }}, the mature survivor year. This
                compares the secure-income floor before and after; it is not a prediction of who dies first, and not
                a recommendation.</p>
        @endif
        @if ($pensionCredit)
            <h3>How to claim your Pension Credit</h3>
            <p>This forecast counts Pension Credit in your secure income above. It's <strong>means-tested, so it has
                to be claimed</strong> — it isn't paid automatically, and it's one of the most under-claimed
                benefits, so it's worth acting on.</p>
            <ul>
                @foreach ($pensionCredit['howToClaim'] as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ul>
            <p>Even a small award is worth claiming because it can passport you to other help:
                {{ implode(', ', $pensionCredit['passports']) }}.</p>
            <p class="note">{{ $pensionCredit['source'] }} · checked {{ $pensionCredit['verifiedOn'] }}. The exact
                amount is means-tested — only the DWP can confirm what you'd get.</p>
        @endif
    </div>
@endif

{{-- The protection gap: the survivor cliff above, priced. Same source as the screen panel. --}}
@if ($protection)
    <div class="card">
        <h2>If one of you died</h2>
        <p class="lede">A plan for two people quietly assumes you both live roughly as long as the tables say. The
            first death is the sharpest single change in the whole forecast: one State Pension stops, a work pension
            may drop to a survivor's rate or stop altogether, and any salary ends — while the spending falls by much
            less. Below is what a death in <strong>{{ $protection['deathYear'] }}</strong> would do to whoever is
            left, and how much money would put the plan back where it is now. All figures are in today's money.</p>
        <table>
            <thead>
                <tr>
                    <th>If this person died in {{ $protection['deathYear'] }}</th>
                    <th>What happens to the survivor</th>
                    <th class="num">Employer cover</th>
                    <th class="num">Further cover needed</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($protection['people'] as $p)
                    <tr>
                        <td>{{ $p['name'] }}</td>
                        <td>
                            @if (! $p['worseThanBaseline'])
                                {{ $p['survivorName'] }} would be no worse off than this plan already is.
                            @elseif ($p['depletionYear'])
                                {{ $p['survivorName'] }} would run short in {{ $p['depletionYear'] }}{{ $protection['baselineDepletionYear'] ? ', rather than '.$protection['baselineDepletionYear'] : '' }}.
                            @else
                                {{ $p['survivorName'] }}'s money would not last as long as it does in this plan.
                            @endif
                        </td>
                        <td class="num">{{ $p['coverInForce']->isPositive() ? $p['coverInForce']->format().($p['coverDescription'] ? ' ('.$p['coverDescription'].')' : '') : '—' }}</td>
                        <td class="num">{{ $p['gap']->isZero() ? '—' : $p['gap']->format().($p['gapCeilingHit'] ? ' or more' : '') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @foreach ($protection['people'] as $p)
            @if ($p['coverInForce']->isPositive() && $p['needWithoutCover']->pence > $p['gap']->pence)
                <p class="note">Without {{ $p['name'] }}'s employer cover the figure would be about
                    {{ $p['needWithoutCover']->format() }} — so the policy they already have is doing most of the work.</p>
            @endif
            @if ($p['gapAfterCoverCeases'] && $p['coverCeasesInYear'])
                <p class="note"><strong>{{ $p['name'] }}'s cover stops when they retire in
                    {{ $p['coverCeasesInYear'] }}.</strong> Death-in-service cover only pays while you are still
                    employed. The same death in {{ $p['deathYearAfterRetirement'] }}, a year after retiring, would
                    leave a gap of about {{ $p['gapAfterCoverCeases']->format() }} with nothing to meet it.</p>
            @endif
        @endforeach
        <p class="note">These figures come from the expected path, not an unlucky one, and each is the smallest lump
            sum that restores the plan to where it stands today — rounded up to the nearest £1,000, because a
            protection figure rounded down would not quite do the job. It says how large a hole a death would leave;
            it does not price a policy or say which one to buy, and life cover on someone older or in poor health can
            be expensive or simply unavailable. A lump sum is only one way to close the hole: less borrowing, more
            savings or a larger survivor's pension close the same gap. Cover written in trust normally falls outside
            the estate for Inheritance Tax, and money paid to a survivor counts as capital for means-tested benefits,
            which can affect Pension Credit.</p>
    </div>
@endif

{{-- Capacity for loss: how far wealth could fall before the essential floor breaks. Same source
     and same strategy as the screen panel. --}}
@if ($capacityForLoss)
    <div class="card">
        <h2>How much could you afford to lose?</h2>
        <p class="lede">This is what advisers call <strong>capacity for loss</strong>. It is not how much risk you
            would be comfortable taking. It is how far everything you own could fall in value before it stops paying
            for the things you cannot go without.</p>
        @if ($capacityForLoss['alreadyBreached'])
            <p><strong>There is no room to lose anything: this plan is already short.</strong> As it stands, there is
                at least one year in which this plan cannot pay for the essentials, so the question of how much it
                could afford to lose does not arise yet. Everything you own today comes to
                {{ $capacityForLoss['wealth']->format() }} after the mortgage.</p>
        @elseif ($capacityForLoss['survivesTotalLoss'])
            <p><strong>Even losing everything would leave your essential spending covered.</strong> Your guaranteed
                income on its own pays for the essentials in every year of this plan, so there is no fall in the value
                of your savings, pensions or home that would breach that floor. Everything you own today comes to
                {{ $capacityForLoss['wealth']->format() }} after the mortgage.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th class="num">The most your wealth could fall</th>
                        <th class="num">Which is about</th>
                        <th class="num">Out of everything you own today</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="num">{{ $capacityForLoss['percent'] }}%</td>
                        <td class="num">{{ $capacityForLoss['cash']->format() }}</td>
                        <td class="num">{{ $capacityForLoss['wealth']->format() }}</td>
                    </tr>
                </tbody>
            </table>
            <p>Lose up to {{ $capacityForLoss['percent'] }}% of that and your essential spending is still paid in
                every year of this plan. Lose more and it is not. The total is your savings, pensions and the home
                after the mortgage, worked out from your figures rather than entered.</p>
        @endif
        <p class="note">How this is worked out: everything you own (savings, pensions and the home) is marked down by
            the same amount on day one, while the mortgage stays exactly where it is, and the plan carries on spending
            what you entered. Only the essential floor has to hold; the extras would already have gone. It is not a
            prediction of a crash, and it does not model which of your assets would really fall or by how much: it
            measures how much room this plan has, and marking everything down together is the cautious way to measure
            it. The figure comes from the expected path rather than an unlucky one, so a bad run of returns after a
            fall would use that room up faster. It is rounded down to a whole percent, so it is a level this forecast
            was actually run at and survived, and it applies to the plan in this report. A different housing choice
            has a different answer.</p>
    </div>
@endif

{{-- What paying for advice would cost. Same two runs as on screen. --}}
@if ($adviceCost)
    <div class="card">
        <h2>What paying for advice would cost</h2>
        <p class="lede">Your plan already carries <strong>{{ number_format($adviceCost['diyChargePct'], 2) }}% a
            year</strong> in platform and fund charges. If you also paid an adviser
            {{ $adviceCost['isCustomFee'] ? 'the' : 'the benchmark average' }}
            <strong>{{ number_format($adviceCost['adviceFeePct'], 2) }}% a year</strong>, the money would carry
            <strong>{{ number_format($adviceCost['advisedChargePct'], 2) }}% a year</strong> instead. All figures in
            today's money.</p>
        <table class="tiles">
            <tr>
                <td><div class="tile"><p class="tile-label">Charges over the whole plan, as you are now</p><p class="tile-value">{{ $adviceCost['diy']['lifetimeCharges']->format() }}</p></div></td>
                <td><div class="tile"><p class="tile-label">Charges if you were advised</p><p class="tile-value">{{ $adviceCost['advised']['lifetimeCharges']->format() }}</p></div></td>
                <td><div class="tile tile-amber"><p class="tile-label">The advice itself, over a lifetime</p><p class="tile-value">{{ $adviceCost['extraLifetimeCost']->format() }}</p></div></td>
            </tr>
        </table>
        <p>It would leave <strong>{{ $adviceCost['terminalWealthLost']->format() }}</strong> less at the end of the
            plan.
            @if ($adviceCost['diy']['depletionYear'] === null && $adviceCost['advised']['depletionYear'] !== null)
                And where the money currently lasts, it would instead run short in
                <strong>{{ $adviceCost['advised']['depletionYear'] }}</strong>.
            @elseif ($adviceCost['yearsOfMoneyLost'] > 0)
                The money would run short in <strong>{{ $adviceCost['advised']['depletionYear'] }}</strong> rather
                than {{ $adviceCost['diy']['depletionYear'] }} — {{ $adviceCost['yearsOfMoneyLost'] }}
                {{ \Illuminate\Support\Str::plural('year', $adviceCost['yearsOfMoneyLost']) }} earlier.
            @elseif ($adviceCost['diy']['depletionYear'] !== null)
                The year the money runs short ({{ $adviceCost['diy']['depletionYear'] }}) would not move.
            @else
                The money would still last for life.
            @endif
        </p>
        <p><strong>This is a cost, not a verdict.</strong> Advice costing
            {{ number_format($adviceCost['adviceFeePct'], 2) }}% a year has to add more than that much value a year to
            be worth paying for — and whether it does is a question this tool cannot answer. The most-cited part of an
            adviser's value is behavioural (talking someone out of selling in a crash), and none of it is modelled
            here. Neither is the cost of getting something wrong without one.</p>
        <p class="note">The advised figure is your own charges plus the ongoing fee, and nothing else: the fee is the
            one figure that can be benchmarked, while how much dearer an advised fund choice is varies too much
            between firms to assume. A one-off piece of advice (commonly £1,500–£4,000, or a percentage of the amount
            invested) is charged on top and is not modelled. Fee source: {{ $adviceCost['sourceNote'] }}
            {{ $adviceCost['source'] }} · checked {{ $adviceCost['verifiedOn'] }}.</p>
    </div>
@endif

@if ($iht)
    <div class="card">
        <h2>Inheritance tax on your estate</h2>
        <p class="lede">
            @if ($iht['relationship'] === 'married')
                You're modelled as <strong>married or in a civil partnership</strong>: on the first death everything
                passes to the survivor free of Inheritance Tax, and both allowances apply on the second death.
            @elseif ($iht['relationship'] === 'cohabiting')
                You're modelled as <strong>cohabiting</strong>: there is no spouse exemption and you cannot share
                allowances, so the same estate is taxed more heavily than a married couple's.
            @else
                Inheritance Tax on a single estate: one set of allowances applies.
            @endif
            All figures are in today's money; headline allowances only (not gifts, trusts or reliefs).
        </p>
        <table class="tiles">
            <tr>
                <td><div class="tile"><p class="tile-label">Estate at the final death</p><p class="tile-value">£{{ number_format($iht['secondDeath']['estate']) }}</p><p class="tile-note">everything you're modelled to leave (savings, investments, pensions and home).</p></div></td>
                <td><div class="tile"><p class="tile-label">Sheltered by allowances</p><p class="tile-value">£{{ number_format($iht['secondDeath']['nrb'] + $iht['secondDeath']['rnrb']) }}</p><p class="tile-note">nil-rate band £{{ number_format($iht['secondDeath']['nrb']) }}{{ $iht['secondDeath']['rnrb'] > 0 ? ' + residence band £'.number_format($iht['secondDeath']['rnrb']) : '' }}.</p></div></td>
                <td><div class="tile {{ $iht['anyTaxDue'] ? '' : 'tile-green' }}"><p class="tile-label">Inheritance Tax due</p><p class="tile-value">£{{ number_format($iht['total']) }}</p><p class="tile-note">@if ($iht['anyTaxDue'])40% on the £{{ number_format($iht['secondDeath']['taxable']) }} above your allowances.@else your estate is within the allowances, so no Inheritance Tax is modelled.@endif</p></div></td>
            </tr>
        </table>
        <table>
            <tbody>
                <tr><td>Taxable estate</td><td class="num">£{{ number_format($iht['secondDeath']['taxable']) }}</td></tr>
                @if ($iht['firstDeath'])
                    <tr><td>Estate at the first death{{ $iht['firstDeath']['spouseExempt'] ? ' (passes to the survivor, spouse exempt)' : '' }}</td><td class="num">£{{ number_format($iht['firstDeath']['estate']) }}</td></tr>
                    <tr><td>Inheritance Tax on the first death</td><td class="num">£{{ number_format($iht['firstDeath']['tax']) }}</td></tr>
                @endif
                <tr class="total"><td>Inheritance Tax due in total</td><td class="num">£{{ number_format($iht['total']) }}</td></tr>
            </tbody>
        </table>
        @if ($iht['pensionsIncluded'])
            <p class="note">Unused pension pots are counted as part of the estate — the rule due from
                <strong>April 2027</strong> (Finance Act 2026). Before then they sat outside it, so this raises the
                taxable estate.</p>
        @endif
        @if (! empty($presented['ihtDistribution'] ?? null))
            @php($ihtDist = $presented['ihtDistribution'])
            <h3>How this varies across your simulated futures</h3>
            <p class="note">The figures above assume a single representative lifespan. Across the full simulation,
                how much Inheritance Tax you leave depends on how long you live and how your investments fare.</p>
            <table class="tiles">
                <tr>
                    <td><div class="tile"><p class="tile-label">Futures leaving any IHT</p><p class="tile-value">{{ $ihtDist['sharePct'] }}</p></div></td>
                    <td><div class="tile"><p class="tile-label">Typical (median) bill</p><p class="tile-value">£{{ number_format($ihtDist['median']) }}</p></div></td>
                    <td><div class="tile"><p class="tile-label">High end (1 in 10)</p><p class="tile-value">£{{ number_format($ihtDist['p90']) }}</p></div></td>
                </tr>
            </table>
        @endif
        <p class="note">This shows the <strong>headline allowances</strong> only (nil-rate band £325,000 and residence
            nil-rate band up to £175,000 per person, tapered away above a £2m estate), not a full estate calculation:
            lifetime gifts and the 7-year rule, trusts, business or agricultural relief, and the reduced charity rate
            are not modelled. Estate planning is specialised: consider a solicitor or a STEP-qualified adviser
            (step.org/public), and see gov.uk/inheritance-tax.</p>
    </div>
@endif

@if ($withdrawal)
    <div class="card">
        <h2>How you draw your money down</h2>
        <p class="lede">The order you take money from your pension, ISA and other savings changes the tax you pay
            over your whole plan.</p>
        <table class="tiles">
            <tr>
                <td><div class="tile"><p class="tile-label">{{ ucfirst($withdrawal['baselineLabel']) }} (your current order)</p><p class="tile-value">{{ $withdrawal['baseline'] }}</p><p class="tile-note">tax paid across the plan</p></div></td>
                <td><div class="tile"><p class="tile-label">{{ ucfirst($withdrawal['alternativeLabel']) }}</p><p class="tile-value">{{ $withdrawal['fillBands'] }}</p><p class="tile-note">tax paid across the plan</p></div></td>
            </tr>
        </table>
        <p>
            @if ($withdrawal['differs'])
                That is <strong>{{ $withdrawal['difference'] }}</strong>
                {{ $withdrawal['fillBandsSaves'] ? 'less tax over the plan by '.$withdrawal['alternativeLabel'] : 'less tax over the plan by keeping your current order' }}.
            @else
                On these figures the two orders pay the same tax over the plan.
            @endif
        </p>
        <p>
            @if ($withdrawal['optimiserSaves'])
                Of the {{ $withdrawal['candidateCount'] }} draw orders we tried, the cheapest is
                <strong>{{ $withdrawal['cheapestLabel'] }}</strong>: it pays
                <strong>{{ $withdrawal['optimiserSaving'] }}</strong> less tax across the plan than your current order.
            @else
                We tried {{ $withdrawal['candidateCount'] }} draw orders in all. None of them pays less tax across the
                plan than your current order.
            @endif
        </p>
        <p class="note">
            @if ($withdrawal['includesIht'])
                Every figure here counts the tax paid year by year <em>and</em> the Inheritance Tax your estate pays at
                the end, because the order you draw in changes both: paying less tax now leaves a bigger estate to be
                taxed later.
            @else
                Every figure here counts the tax paid year by year. Inheritance Tax is not part of it, because this
                plan does not model it.
            @endif
        </p>
        <p class="note">A central projection on your current assumptions. "{{ ucfirst($withdrawal['alternativeLabel']) }}"
            draws pension within your personal allowance and realises gains within your capital-gains allowance before
            taxed income; each pension draw is taken so a quarter of it is tax-free cash while your lump sum
            allowance lasts. If you receive Pension Credit it draws your savings first, so pension income does not
            reduce the credit.</p>
        @if (! empty($withdrawal['steer']))
            <ul>
                @foreach ($withdrawal['steer'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

@if ($stressTest)
    <div class="card">
        <h2>Stress test: how it would have handled past crises</h2>
        <p class="lede">We replayed this plan through every year from {{ $stressTest['fromYear'] }} to
            {{ $stressTest['toYear'] }} as if you had started then, using the <strong>actual</strong> returns and
            inflation that followed. This is <strong>sequence-of-returns risk</strong>: a bad first decade while you
            are drawing an income does far more damage than the same slump later.</p>
        <table class="tiles">
            <tr>
                <td><div class="tile"><p class="tile-label">Historical starts survived</p><p class="tile-value">{{ $stressTest['survivalPct'] }}%</p><p class="tile-note">essentials met every year in {{ $stressTest['survivedCount'] }} of {{ $stressTest['tested'] }} historical starting years.</p></div></td>
                @if ($stressTest['worst'])
                    <td><div class="tile"><p class="tile-label">Worst start ({{ $stressTest['worst']['startYear'] }})</p>
                        @if ($stressTest['worst']['ranOut'])
                            <p class="tile-value">ran short after {{ $stressTest['worst']['yearsLasted'] }} yrs</p>
                            <p class="tile-note">the harshest sequence on record for this plan.</p>
                        @else
                            <p class="tile-value">£{{ number_format($stressTest['worst']['terminalUsable']) }} left</p>
                            <p class="tile-note">the least left at the end across every historical start — it still lasted.</p>
                        @endif
                    </div></td>
                @endif
            </tr>
        </table>
        @if ($stressTest['crises'])
            <table>
                <thead><tr><th>Retiring into…</th><th>Outcome</th></tr></thead>
                <tbody>
                    @foreach ($stressTest['crises'] as $crisis)
                        <tr>
                            <td>{{ $crisis['label'] }}</td>
                            <td>
                                @if ($crisis['ranOut'])
                                    Ran short after {{ $crisis['yearsLasted'] }} years
                                @else
                                    Lasted — £{{ number_format($crisis['terminalUsable']) }} spendable left at the end
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <p class="note">Real UK asset returns and inflation, 1871–2020, from the Jordà–Schularick–Taylor Macrohistory
            database (<em>The Rate of Return on Everything</em>). A plan that survives the 1970s and 2008 starts is
            robust to sequence risk; past performance is not a guarantee of the future.</p>
    </div>
@endif

<div class="card">
    {{-- The badge is a separate expression, never glued to the preceding word: a Blade control
         directive touching a word character (`figures@if`) silently fails to compile. --}}
    <h2>The assumptions behind these figures {!! $assumptions['customised'] ? '<span class="badge">customised</span>' : '' !!}</h2>
    <p class="lede">Every figure in this report rests on these assumptions. Returns and growth are
        <strong>real</strong> — they are above inflation, so amounts stay in today's money.
        @if ($assumptions['customised'])
            The figures marked <strong>your figure</strong> are ones you set yourself; the rest come from the
            assumption set.
        @endif
    </p>
    <table>
        <thead><tr><th>Assumption</th><th class="num">Value</th><th>Basis</th></tr></thead>
        <tbody>
            @foreach ($assumptions['economic'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['value'] }}</td>
                    <td class="muted">{{ $row['edited'] ? 'your figure · ' : '' }}{{ $row['note'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="note">Investment growth blends {{ $assumptions['mix'] }}. Assumption set:
        <strong>{{ $assumptions['setName'] }}{{ $assumptions['customised'] ? ' (customised)' : '' }}</strong>.
        {{ $assumptions['sourceNote'] }}</p>
    {{-- Selling costs, moving costs, the buy price and the rent are all sale inputs, so they are
         only shown for a plan that sells. A stay-put plan uses none of them. --}}
    @if ($salePlanned && $assumptions['housing'])
        <h3>Housing-decision inputs</h3>
        <table>
            <tbody>
                @foreach ($assumptions['housing'] as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

@if ($saleExplainer)
    @php($se = $saleExplainer)
    <div class="card">
        <h2>If you sell: where the money comes from and goes</h2>
        <p class="lede">Selling the current home is assumed at {{ $se['proceeds']['salePrice'] }}. After the costs of
            selling, this is what is left to invest — and, if buying a cheaper home, what is left over after that
            purchase. Figures in today's money.</p>
        <table>
            <tbody>
                <tr><td>Sale price</td><td class="num">{{ $se['proceeds']['salePrice'] }}</td></tr>
                @if ($se['proceeds']['hasMortgage'])
                    <tr><td>less outstanding mortgage</td><td class="num">−{{ $se['proceeds']['mortgage'] }}</td></tr>
                @endif
                <tr><td>less selling costs{{ $se['sellingCostsAssumed'] ? ' (assumed)' : '' }}</td><td class="num">−{{ $se['proceeds']['sellingCosts'] }}</td></tr>
                {{-- Shown whenever there is more than one line, so the 60-day capital-gains return
                     the engine adds to an assumed total is on the page too (print matches screen). --}}
                @if (! $se['sellingCostsAssumed'] || count($se['sellingCostBreakdown']) > 1)
                    @foreach ($se['sellingCostBreakdown'] as $line)
                        <tr><td class="muted">&nbsp;&nbsp;{{ $line['label'] }}@if ($line['detail']) ({{ $line['detail'] }})@endif</td><td class="num muted">−{{ $line['value'] }}</td></tr>
                    @endforeach
                @endif
                <tr><td>less capital gains tax{{ $se['proceeds']['cgtCharged'] ? '' : ' (main home, fully relieved)' }}</td><td class="num">−{{ $se['proceeds']['cgt'] }}</td></tr>
                @if ($se['cgtDetail'])
                    <tr><td class="muted" colspan="2">Gain {{ $se['cgtDetail']['gain'] }}, less {{ $se['cgtDetail']['relievedGain'] }} private-residence relief = {{ $se['cgtDetail']['chargeableGain'] }} chargeable; less {{ $se['cgtDetail']['allowanceUsed'] }} allowance = {{ $se['cgtDetail']['taxableGain'] }} taxed at {{ $se['cgtDetail']['ratePct'] }}.</td></tr>
                @endif
                <tr class="total"><td>Net proceeds</td><td class="num">{{ $se['proceeds']['netProceeds'] }}</td></tr>
            </tbody>
        </table>
        @unless ($se['proceeds']['clearsCosts'])
            <p class="note">On these figures the sale does not cover the mortgage and selling costs, so there are no
                net proceeds to invest.</p>
        @endunless

        <h3>If you sell &amp; rent</h3>
        <p>All {{ $se['rent']['invested'] }} of the net proceeds is
            invested.@if ($se['rent']['annualRent']) The rent for a home to rent instead —
            {{ $se['rent']['annualRent'] }} a year, in today's money — is then paid from income (a projected future
            cost, not one you pay now).@endif</p>

        @if ($se['buy'])
            <h3>If you sell &amp; buy</h3>
            <table>
                <tbody>
                    <tr><td>Net proceeds</td><td class="num">{{ $se['buy']['netProceeds'] }}</td></tr>
                    <tr><td>less the home bought</td><td class="num">−{{ $se['buy']['buyPrice'] }}</td></tr>
                    <tr><td>less stamp duty</td><td class="num">−{{ $se['buy']['sdlt'] }}</td></tr>
                    <tr><td>less moving costs</td><td class="num">−{{ $se['buy']['movingCosts'] }}</td></tr>
                    @if ($se['buy']['fundedFromReceipts'])
                        <tr><td>plus the capital receipt arriving that year</td><td class="num">+{{ $se['buy']['fundedFromReceipts'] }}</td></tr>
                    @endif
                    @if ($se['buy']['fundedFromSavings'])
                        <tr><td>plus from your savings (cash &rarr; GIA &rarr; ISA)</td><td class="num">+{{ $se['buy']['fundedFromSavings'] }}</td></tr>
                    @endif
                    @if ($se['buy']['mortgage'])
                        <tr><td>plus interest-only mortgage{{ $se['buy']['mortgageInterest'] ? ' (~'.$se['buy']['mortgageInterest'].'/yr interest)' : '' }}</td><td class="num">+{{ $se['buy']['mortgage'] }}</td></tr>
                    @endif
                    @if ($se['buy']['unfundedGap'])
                        <tr><td><strong>Unfunded gap</strong></td><td class="num"><strong>{{ $se['buy']['unfundedGap'] }}</strong></td></tr>
                    @endif
                    <tr class="total"><td>Surplus invested</td><td class="num">{{ $se['buy']['surplus'] }}</td></tr>
                </tbody>
            </table>
            @if ($se['buy']['unfundedGap'])
                <p class="verdict verdict-high">{{ $se['buy']['unfundedGap'] }} of this purchase has no funding
                    source. The sale proceeds{{ $se['buy']['fundedFromSavings'] ? ' and all your savings' : '' }}
                    don't cover it and no mortgage is configured, so the forecast charges the gap as an unmet cost in
                    year one — the plan fails until that money is documented (a mortgage, a receipt, or a cheaper
                    home).</p>
            @elseif ($se['buy']['fundedFromSavings'])
                <p class="note">{{ $se['buy']['fundedFromSavings'] }} of this purchase is funded from savings, drawn
                    cash &rarr; GIA &rarr; ISA (never pensions). That money leaves the plan on day
                    one{{ $se['buy']['mortgage'] ? ', and the rest of the gap is borrowed' : '' }}.</p>
            @endif
            @if ($se['buy']['fundedFromReceipts'])
                <p class="note">{{ $se['buy']['fundedFromReceipts'] }} of this purchase is paid for by the capital
                    receipt that arrives in the same year, before any savings are drawn and before anything is
                    borrowed. That part of the receipt goes into the home, so the forecast no longer shows it as
                    income that year; anything left over still arrives as normal.</p>
            @endif
        @endif

        <p class="note">Invested money is not left idle: it grows at the blended real return of
            {{ $se['blendedReturnPct'] }} a year (above inflation); about {{ $se['incomeYieldPct'] }} of the value is
            paid out each year as taxable income, the rest is capital growth. The cashflow below shows how it is
            drawn on.</p>
    </div>
@endif

@if ($milestones)
    <div class="card">
        <h2>When the big events happen</h2>
        <p class="lede">The major life events in this forecast, in order. These drive the step changes in the
            year-by-year cashflow below. The <strong>#</strong> column is the numbered marker on every chart in this
            report. Ages are each person's age in that year.</p>
        <table>
            <thead><tr><th>#</th><th>Year</th><th>Event</th><th>Age</th></tr></thead>
            <tbody>
                @foreach ($milestones as $milestone)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $milestone['year'] }}</td>
                        <td>{{ $milestone['label'] }}</td>
                        <td>{{ $milestone['age'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- Money over time: the three hero charts, each with its full table twin, exactly as the
     screen pairs them. --}}
@if (! empty($timeSeries['income']['rows']))
    <div class="card">
        <h2>Money over time <span class="badge">{{ $ladderSelectedLabel }}</span></h2>
        <p class="lede">The same year-by-year figures as the cashflow table below, drawn as pictures: where your
            income comes from, where your wealth sits, and how your spending changes as you age. All figures are in
            today's money, and follow the central best-estimate projection (one illustrative path, not a
            probability). The dashed verticals mark the life events that drive each step change.</p>

        <h3>Where your income comes from</h3>
        <p class="lede">Each band is one source of income, stacked to the year's total. Watch the handover as
            earnings stop and pensions, the State Pension and any drawdown take over.</p>
        <img class="chart" src="{{ $timeSeriesCharts['income'] }}" alt="Stacked area chart of income by source over time. The full figures are in the table below.">
        @include('pdf.partials.chart-key', ['milestones' => $milestones])
        @if ($timeSeries['income']['folded'])
            <p class="note">The chart folds the smallest sources into a single "Other income" band; the table below
                keeps every source separate.</p>
        @endif
        <table>
            <thead>
                <tr>
                    <th>Year</th><th>Age(s)</th>
                    @foreach ($timeSeries['income']['sources'] as $source)
                        <th class="num">{{ $timeSeries['income']['sourceLabels'][$source] }}</th>
                    @endforeach
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($timeSeries['income']['rows'] as $row)
                    <tr>
                        <td>{{ $row['year'] }}</td>
                        <td>{{ $row['ages'] }}</td>
                        @foreach ($timeSeries['income']['sources'] as $source)
                            <td class="num">{{ $row['income'][$source] }}</td>
                        @endforeach
                        <td class="num">{{ $row['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h3>Where your wealth is</h3>
        <p class="lede">Your net worth split into its three parts, stacked to the total: pensions, savings &amp;
            investments, and the equity in your home (net of any mortgage). Shows how the balance shifts as pots are
            drawn on and the home is kept or sold.</p>
        <img class="chart" src="{{ $timeSeriesCharts['wealth'] }}" alt="Stacked area chart of wealth by pension, savings and home equity over time. The full figures are in the table below.">
        @include('pdf.partials.chart-key', ['milestones' => $milestones])
        <table>
            <thead>
                <tr><th>Year</th><th>Age(s)</th><th class="num">Pensions</th><th class="num">Savings &amp; investments</th>
                    <th class="num">Home equity</th><th class="num">Net worth</th></tr>
            </thead>
            <tbody>
                @foreach ($timeSeries['wealth']['rows'] as $row)
                    <tr>
                        <td>{{ $row['year'] }}</td>
                        <td>{{ $row['ages'] }}</td>
                        <td class="num">{{ $row['pension'] }}</td>
                        <td class="num">{{ $row['liquid'] }}</td>
                        <td class="num">{{ $row['homeEquity'] }}</td>
                        <td class="num">{{ $row['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h3>What you spend, and how it changes</h3>
        <p class="lede">Your target spending each year, split into the essential floor and the discretionary extra on
            top. The shape reflects the age-varying spending "smile" (more active early, easing in the middle
            years).</p>
        <img class="chart" src="{{ $timeSeriesCharts['costs'] }}" alt="Stacked area chart of essential and discretionary spending over time. The full figures are in the table below.">
        @include('pdf.partials.chart-key', ['milestones' => $milestones])
        <table>
            <thead>
                <tr><th>Year</th><th>Age(s)</th><th class="num">Essential</th><th class="num">Discretionary</th>
                    <th class="num">Total spend</th></tr>
            </thead>
            <tbody>
                @foreach ($timeSeries['costs']['rows'] as $row)
                    <tr>
                        <td>{{ $row['year'] }}</td>
                        <td>{{ $row['ages'] }}</td>
                        <td class="num">{{ $row['essential'] }}</td>
                        <td class="num">{{ $row['discretionary'] }}</td>
                        <td class="num">{{ $row['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- The year-by-year ladder. On screen this is ONE table the reader scrolls sideways; paper
     cannot scroll, and printing all ~24 columns as one table pushed the final wealth columns
     off the page edge. It is therefore split into two tables sharing the Year / Age(s) key —
     income by source, then what happens to it — so every column stays readable and nothing is
     dropped. --}}
<div class="card">
    <h2>Year-by-year cashflow <span class="badge">{{ $ladderSelectedLabel }}</span></h2>
    <p class="lede">The central best-estimate projection, year by year: where income comes from, the tax on it, the
        spend it has to meet (split into its essential floor and discretionary remainder), and the usable (excl.
        home) and total (incl. home equity, net of any mortgage owed) wealth carried forward. Real terms, to
        {{ $ladder['finalYear'] }}. This is one illustrative path, not a probability.</p>
    <p class="lede"><strong>To spend / month</strong> is what this plan can actually <em>fund</em> that year, divided
        by twelve — not what it targets, so in a year that falls short it shows the smaller, real figure.
        <strong>free</strong> is the part left after essentials. <strong>Available capital</strong> is cash and
        investments only — money you could spend now without a tax bill to get at it; pension capital is listed
        separately because drawing it is taxable, and <strong>your home is excluded</strong>: you can't spend it
        while you live in it.</p>
    @if ($ladder['depletionYear'])
        <p class="verdict verdict-high">On this strategy, usable money runs out in {{ $ladder['depletionYear'] }}.</p>
    @elseif ($ladder['floorBreachYear'])
        <p class="verdict verdict-medium">Usable money dips below your safety buffer ({{ $ladder['bufferMonths'] }}
            {{ \Illuminate\Support\Str::plural('month', $ladder['bufferMonths']) }}' essentials) in
            {{ $ladder['floorBreachYear'] }}, though it does not run out entirely.</p>
    @elseif ($ladder['bufferMonths'] > 0)
        <p class="verdict verdict-none">Usable money stays above your safety buffer ({{ $ladder['bufferMonths'] }}
            {{ \Illuminate\Support\Str::plural('month', $ladder['bufferMonths']) }}' essentials) every year.</p>
    @else
        <p class="verdict verdict-none">Usable money never runs out on this strategy.</p>
    @endif
    {{-- Would a landlord grant this tenancy, and what does moving in cost on day one? Printed
         beside the money-lasts verdict, so print cannot drift from the screen (card 0031). --}}
    @if ($ladder['rentReferencing'])
        <p class="verdict verdict-medium">Renting has to be agreed as well as afforded. From
            {{ $ladder['rentReferencing']['firstYear'] }}, and in {{ $ladder['rentReferencing']['years'] }}
            {{ \Illuminate\Support\Str::plural('year', $ladder['rentReferencing']['years']) }} of this plan, the
            income would not pass a letting agent's standard reference.
            {{ $ladder['rentReferencing']['message'] }}</p>
    @endif
    @if ($ladder['tenancyUpFront'])
        <p class="note"><strong>Moving in.</strong> {{ $ladder['tenancyUpFront'] }}</p>
    @endif
    <p class="note">Rows are tinted: green where income covers the spend, plain where savings are being drawn on,
        amber where the spend is not fully met, red where usable money sits below your safety buffer.</p>

    <h3>Where the money comes from each year</h3>
    <table class="dense">
        <thead>
            <tr>
                <th>Year</th>
                <th>Age(s)</th>
                @foreach ($ladder['sources'] as $source)
                    <th class="num">{{ $ladder['sourceLabels'][$source] }}</th>
                @endforeach
                <th class="num">Tax</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ladder['rows'] as $row)
                <tr @class([
                    'below-floor' => $row['belowFloor'],
                    'shortfall' => $row['shortfall'] && ! $row['belowFloor'],
                    'surplus' => $row['status'] === 'surplus' && ! $row['belowFloor'] && ! $row['shortfall'],
                ])>
                    <td>{{ $row['year'] }}</td>
                    <td>{{ $row['ages'] }}</td>
                    @foreach ($ladder['sources'] as $source)
                        <td class="num">{{ $row['income'][$source] }}</td>
                    @endforeach
                    <td class="num">{{ $row['tax'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>What it has to cover, and what is left</h3>
    <table class="dense">
        <thead>
            <tr>
                <th>Year</th>
                <th>Age(s)</th>
                <th class="num">Spend</th>
                <th class="num">of which essential</th>
                <th class="num">of which discretionary</th>
                <th class="num">Unmet spend</th>
                <th class="num">To spend / month</th>
                <th class="num">of which essential</th>
                <th class="num">of which free</th>
                @if ($ladder['showGrowth'])
                    <th class="num">Investment growth</th>
                @endif
                <th class="num">Available capital</th>
                <th class="num">Pension capital (taxable)</th>
                <th class="num">Usable (excl. home)</th>
                <th class="num">Total (incl. home equity)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ladder['rows'] as $row)
                <tr @class([
                    'below-floor' => $row['belowFloor'],
                    'shortfall' => $row['shortfall'] && ! $row['belowFloor'],
                    'surplus' => $row['status'] === 'surplus' && ! $row['belowFloor'] && ! $row['shortfall'],
                ])>
                    <td>{{ $row['year'] }}</td>
                    <td>{{ $row['ages'] }}</td>
                    <td class="num">{{ $row['spend'] }}</td>
                    <td class="num">{{ $row['essentialSpend'] }}</td>
                    <td class="num">{{ $row['discretionarySpend'] }}</td>
                    <td class="num">{{ $row['shortfall'] ?? '—' }}</td>
                    <td class="num">{{ $row['monthlyAllowance'] }}</td>
                    <td class="num">{{ $row['monthlyEssential'] }}</td>
                    <td class="num">{{ $row['monthlyFree'] }}</td>
                    @if ($ladder['showGrowth'])
                        <td class="num">{{ $row['investmentGrowth'] }}</td>
                    @endif
                    <td class="num">{{ $row['availableCapital'] }}</td>
                    <td class="num">{{ $row['pensionCapital'] }}</td>
                    <td class="num">{{ $row['usableWealth'] }}@if ($row['belowFloor']) *@endif</td>
                    <td class="num">{{ $row['totalWealth'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($ladder['showGrowth'])
        <p class="note">Your investments earn in two ways: <strong>Investment income</strong> (interest on cash and
            dividends from funds) is paid out and taxed each year, so it's in the income table above;
            <strong>Investment growth</strong> is the rise in the value of your funds/shares — it stays invested,
            which is why wealth can grow even in a year you're drawing down. A row marked * is a year usable money
            sits below your safety buffer.</p>
    @endif
    @if ($ladder['showCharges'])
        <p class="note"><strong>Charges:</strong> the investment growth shown is <em>before</em> charges. Platform and
            fund fees take <strong>{{ $ladder['chargesTotal'] }}</strong> out of the pensions, ISAs and investments over
            the whole plan (today's money); cash deposits pay none. The yearly rate is in the assumptions table.</p>
    @endif
</div>

{{-- "Check these figures & get help" — the same signposting the screen's sources-and-contacts
     component carries, including its contextual mortgage / CGT columns and their caveats. --}}
<div class="card">
    <h2>Check these figures &amp; get help</h2>
    <p class="lede">This forecast rests on your assumptions and on public rules. Here is where to verify them and
        find free, impartial help. These are pointers, not advice; figures such as a mortgage rate or capital gains
        tax are estimates until a professional confirms them for your circumstances.</p>
    <h3>Pensions &amp; money</h3>
    <ul>
        <li><strong>Pension Wise</strong> — free government pension guidance. 0800 138 3944
            (moneyhelper.org.uk/en/pensions-and-retirement/pension-wise).</li>
        <li><strong>MoneyHelper</strong> — free, government-backed money guidance. 0800 138 7777 (moneyhelper.org.uk).</li>
        <li>Find or check a regulated adviser: fca.org.uk/consumers/finding-adviser · unbiased.co.uk.</li>
    </ul>
    @if ($sourcesShowMortgage)
        <h3>Later-life mortgages</h3>
        <p class="note">For the mortgage in this plan (e.g. a retirement interest-only rate). What you could borrow
            in retirement is set by a lender's affordability check, not by this tool.</p>
        <ul>
            <li><strong>Equity Release Council directory</strong> (equityreleasecouncil.com/members-directory) — find
                a later-life mortgage adviser; a free Decision in Principle shows what is actually obtainable.</li>
            <li>MoneyHelper — retirement interest-only mortgages
                (moneyhelper.org.uk/en/homes/buying-a-home/retirement-interest-only-mortgages).</li>
        </ul>
    @endif
    @if ($sourcesShowCgt)
        <h3>Capital gains tax</h3>
        <p class="note">For the CGT on selling this home — it was let, or not always your main residence, so only
            part of the gain is relieved.</p>
        <ul>
            <li><strong>GOV.UK — Capital Gains Tax</strong> (gov.uk/capital-gains-tax/what-you-pay-it-on); a UK
                property sale must be reported and paid within 60 days.</li>
            <li>HMRC CGT enquiries: 0300 200 3300 (Mon–Fri, 8am–6pm).</li>
            <li>Free tax help on a low income: TaxAid (taxaid.org.uk) 0345 120 3779; or a Chartered Tax Adviser
                (tax.org.uk).</li>
        </ul>
    @endif
</div>

<div class="callout">
    <strong>Guidance only, not financial advice.</strong>
    These figures illustrate the consequences of the numbers and assumptions you entered. They are not a personal
    recommendation and do not tell you what to do. Outcomes depend on assumptions that may not hold.
    Pension and housing decisions are significant and hard to reverse. Free, impartial guidance is available from
    Pension Wise (pension options at 50+) and MoneyHelper (moneyhelper.org.uk), or an FCA-regulated adviser.
    RetireForecast is an educational illustration only.
</div>
