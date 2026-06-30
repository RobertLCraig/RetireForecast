<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Finance\FigureFreshness;
use DateTimeImmutable;
use Illuminate\Console\Command;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Reports how long ago each supported tax year's statutory figures were last verified
 * against gov.uk, and flags any verified more than --months ago (default 12). Extends the
 * gov.uk verification pass into an ongoing guardrail: it returns a non-zero exit code when
 * something is stale, so CI (or a periodic run) catches aging figures rather than letting a
 * silently out-of-date band reach a forecast. The clock lives here (the engine stays
 * clock-free); the date arithmetic is in the pure {@see FigureFreshness}.
 */
class CheckFigureFreshness extends Command
{
    protected $signature = 'figures:freshness {--months=12 : Flag figures verified more than this many months ago}';

    protected $description = 'Report each tax year\'s gov.uk verification date and flag stale figures.';

    public function handle(): int
    {
        $threshold = (int) $this->option('months');
        $asOf = new DateTimeImmutable('today');
        $anyStale = false;
        $rows = [];

        foreach (TaxYearRegistry::SUPPORTED_TAX_YEARS as $taxYear) {
            $config = TaxYearRegistry::for($taxYear);
            $months = FigureFreshness::monthsOld($config->verifiedOn, $asOf);
            $stale = $months > $threshold;
            $anyStale = $anyStale || $stale;
            $rows[] = [$taxYear, $config->verifiedOn, "{$months} mo", $stale ? 'STALE' : 'fresh'];
        }

        $this->table(['Tax year', 'Verified on', 'Age', 'Status'], $rows);

        if ($anyStale) {
            $this->warn("Some figures were verified more than {$threshold} months ago. Re-run the gov.uk verification pass and re-stamp verified_on before relying on the forecast.");

            return self::FAILURE;
        }

        $this->info("All supported tax-year figures were verified within the last {$threshold} months.");

        return self::SUCCESS;
    }
}
