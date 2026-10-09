<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Finance\FigureFreshness;
use DateTimeImmutable;
use Illuminate\Console\Command;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Reports how long ago each figure was last verified against its source, and flags any verified
 * more than --months ago (default 12). Extends the verification pass into an ongoing guardrail: it
 * returns a non-zero exit code when something is stale, so CI (or a periodic run) catches aging
 * figures rather than letting a silently out-of-date band reach a forecast. The clock lives here
 * (the engine stays clock-free); the date arithmetic is in the pure {@see FigureFreshness}.
 *
 * It sweeps TWO sets of figures. The statutory ones (the tax-year configs, verified against
 * gov.uk) it has always covered. The ECONOMIC assumptions on every shipped
 * {@see AssumptionSetLibrary} set joined it with board card 0065: they move the answer far more
 * than the statutory figures do, and until then they sat behind one prose note per set with no
 * date any command could read, so nothing could tell whether they had been looked at this year or
 * four years ago. That includes each asset class's expected return and volatility, which are dated
 * on the class itself rather than in the set's economic sourcing.
 */
class CheckFigureFreshness extends Command
{
    protected $signature = 'figures:freshness {--months=12 : Flag figures verified more than this many months ago}';

    protected $description = 'Report every statutory and economic figure\'s verification date and flag stale ones.';

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

        $economicRows = [];
        foreach (AssumptionSetLibrary::all() as $set) {
            // The asset-class return and volatility carry their own dates on AssetClassAssumption,
            // not in economicSourcing(), and they are the figures that move the answer most.
            $dated = array_map(fn ($source) => [$source->label, $source->verifiedOn], $set->economicSourcing());
            foreach ($set->assetClasses as $class) {
                $dated[] = ["{$class->name}: expected real return", $class->returnVerifiedOn];
                $dated[] = ["{$class->name}: volatility", $class->volatilityVerifiedOn];
            }

            foreach ($dated as [$label, $verifiedOn]) {
                // A figure with no date has never been verified, which is stale by definition.
                $months = $verifiedOn === null ? null : FigureFreshness::monthsOld($verifiedOn, $asOf);
                $stale = $months === null || $months > $threshold;
                $anyStale = $anyStale || $stale;
                $economicRows[] = [$set->name, $label, $verifiedOn ?? 'never', $months === null ? '-' : "{$months} mo", $stale ? 'STALE' : 'fresh'];
            }
        }

        $this->newLine();
        $this->table(['Assumption set', 'Figure', 'Verified on', 'Age', 'Status'], $economicRows);

        if ($anyStale) {
            $this->warn("Some figures were verified more than {$threshold} months ago. Re-check them against the sources listed beside them and re-stamp verified_on before relying on the forecast.");

            return self::FAILURE;
        }

        $this->info("Every statutory and economic figure was verified within the last {$threshold} months.");

        return self::SUCCESS;
    }
}
