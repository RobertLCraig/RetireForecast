<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>RetireForecast summary</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 11px; line-height: 1.4; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        h2 { font-size: 14px; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        .meta { color: #6b7280; font-size: 10px; }
        .disclaimer { border: 1px solid #d1d5db; background: #f9fafb; padding: 8px 10px; margin: 12px 0; font-size: 10px; }
        .disclaimer strong { color: #374151; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #e5e7eb; padding: 3px 6px; text-align: left; }
        th { background: #f3f4f6; font-size: 10px; }
        td.num, th.num { text-align: right; }
        .muted { color: #6b7280; }
        .note { color: #6b7280; font-size: 10px; margin-top: 4px; }
        ul { margin: 4px 0; padding-left: 16px; }
    </style>
</head>
<body>
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
        <table>
            <thead>
                <tr>
                    <th>Option</th>
                    <th class="num">Essentials always met</th>
                    <th class="num">Full spend met</th>
                    <th class="num">Ran out at some point</th>
                    <th class="num">Median usable (excl. home)</th>
                    <th class="num">Median total (incl. home)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($presented['comparison']['rows'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="num">{{ $row['successEssentials'] }}</td>
                        <td class="num">{{ $row['successFullSpend'] }}</td>
                        <td class="num">{{ $row['depletionRate'] }}</td>
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
                <th class="num">Total (incl. home)</th>
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
        Free, impartial guidance: Pension Wise (pension options at 50+) and MoneyHelper (moneyhelper.org.uk), or an
        FCA-regulated adviser. RetireForecast is an educational illustration only.
    </div>
</body>
</html>
