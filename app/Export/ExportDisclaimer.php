<?php

declare(strict_types=1);

namespace App\Export;

/**
 * The guidance-only disclaimer prepended to every CSV export, so a downloaded figure never
 * travels without its framing (guidance, not a personal recommendation, plus where to get
 * free impartial help). One home for the wording — the results-page fan/ladder exports and
 * the decision-support threshold export all read these same lines, so they cannot drift.
 */
final class ExportDisclaimer
{
    /** @var list<string> */
    public const LINES = [
        'RetireForecast — guidance only, not financial advice.',
        'These figures illustrate the consequences of the inputs and assumptions you entered; they are not a personal recommendation.',
        'Free, impartial guidance: Pension Wise and MoneyHelper (moneyhelper.org.uk), or an FCA-regulated adviser.',
    ];
}
