<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Finance\FigureFreshness;
use App\Finance\MortalityDataset;
use DateTimeImmutable;
use Illuminate\Console\Command;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Mortality\OnsPeriodMortalityData;

/**
 * The ONS mortality-data guardrail + refresh helper. It (1) verifies the embedded, generated
 * {@see OnsPeriodMortalityData} still matches its JSON source home cell for cell — the mortality
 * counterpart of the gov.uk figure-verification pass; (2) reports how long ago the ONS data was
 * verified and flags it if older than --months (ONS updates ~biennially); and (3) with --against
 * <newRelease.json>, diffs a freshly downloaded ONS release against what we embed and shows the
 * cohort-life-expectancy impact, so a refresh's effect is visible before it is adopted.
 *
 * Returns a non-zero exit code when the class has drifted from its JSON or the data is stale, so
 * CI or a periodic run catches it. The clock lives here (the engine stays clock-free); the date
 * arithmetic reuses the pure {@see FigureFreshness}, and the grid work is in {@see MortalityDataset}.
 *
 * To adopt a new ONS release: convert its "mortality rates qx (principal projection)" table to the
 * JSON resource shape (source_url is in the file), replace the resource, regenerate
 * OnsPeriodMortalityData from it, then run this command to confirm sync + see the impact.
 */
class RefreshMortalityData extends Command
{
    protected $signature = 'mortality:refresh
        {--against= : Path to a newer ONS mortality JSON to diff against the embedded data}
        {--months=24 : Flag data verified more than this many months ago (ONS updates biennially)}';

    protected $description = 'Verify the embedded ONS mortality data matches its source, flag staleness, and diff a new release.';

    public function handle(): int
    {
        $path = base_path(MortalityDataset::RESOURCE);
        $json = MortalityDataset::load($path);
        $jsonGrid = MortalityDataset::grid($json);
        $embedded = OnsPeriodMortalityData::periodQx();

        $this->line("<info>Source:</info> {$json['source']}");
        $this->line('<info>Coverage:</info> ages '.OnsPeriodMortalityData::FIRST_AGE.'-'.OnsPeriodMortalityData::LAST_AGE
            .', years '.OnsPeriodMortalityData::FIRST_YEAR.'-'.OnsPeriodMortalityData::LAST_YEAR.', male + female');
        $this->line('<info>Cohort life expectancy at 65 (embedded):</info> '.$this->lifeExpectancyLine($embedded));

        $failed = false;

        // (1) Integrity: the generated class must still match its JSON home, cell for cell.
        $integrity = MortalityDataset::diff($embedded, $jsonGrid);
        if ($integrity['changed'] > 0) {
            $failed = true;
            $this->error("Embedded OnsPeriodMortalityData has DRIFTED from its JSON source: {$integrity['changed']} of {$integrity['compared']} cells differ (max Δq(x) {$integrity['maxAbsDelta']}).");
            $this->warn('Regenerate OnsPeriodMortalityData from the JSON resource so the one source of truth is honoured.');
            $this->sampleTable($integrity['samples']);
        } else {
            $this->info("Integrity: the embedded data matches its JSON source exactly ({$integrity['compared']} cells).");
        }

        // (2) Freshness: prompt a refresh when the ONS verification ages.
        $threshold = (int) $this->option('months');
        $months = FigureFreshness::monthsOld($json['verified_on'], new DateTimeImmutable('today'));
        if (FigureFreshness::isStale($json['verified_on'], new DateTimeImmutable('today'), $threshold)) {
            $failed = true;
            $this->error("Mortality data was verified {$months} months ago ({$json['verified_on']}), over the {$threshold}-month threshold.");
            $this->warn("Check ONS for a newer release: {$json['source_url']}");
        } else {
            $this->info("Freshness: verified {$months} months ago ({$json['verified_on']}), within {$threshold} months.");
        }

        // (3) Optional: diff a freshly downloaded ONS release against what we embed.
        if ($this->option('against') !== null) {
            $this->diffAgainstNewRelease((string) $this->option('against'), $embedded);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{male: array<int, array<int, float>>, female: array<int, array<int, float>>}  $embedded
     */
    private function diffAgainstNewRelease(string $againstPath, array $embedded): void
    {
        $incoming = MortalityDataset::grid(MortalityDataset::load($againstPath));
        $diff = MortalityDataset::diff($embedded, $incoming);

        $this->newLine();
        $this->line("<info>Against new release:</info> {$againstPath}");
        if ($diff['changed'] === 0) {
            $this->info("No change: the new release matches the embedded data across {$diff['compared']} cells.");

            return;
        }

        $this->line("{$diff['changed']} of {$diff['compared']} cells changed (max Δq(x) ".round($diff['maxAbsDelta'], 6).').');
        $this->line('<info>Cohort life expectancy at 65 — embedded → new release:</info>');
        $this->line('  '.$this->lifeExpectancyShift($embedded, $incoming));
        $this->sampleTable($diff['samples']);
        $this->warn('Review the impact above, then adopt the new release by replacing the JSON resource and regenerating OnsPeriodMortalityData.');
    }

    /** @param  array{male: array<int, array<int, float>>, female: array<int, array<int, float>>}  $grid */
    private function lifeExpectancyLine(array $grid): string
    {
        $table = new CohortLifeTable($grid);

        return 'male '.round($table->lifeExpectancy(Sex::Male, 65, OnsPeriodMortalityData::FIRST_YEAR + 1), 1)
            .', female '.round($table->lifeExpectancy(Sex::Female, 65, OnsPeriodMortalityData::FIRST_YEAR + 1), 1).' more years';
    }

    /**
     * @param  array{male: array<int, array<int, float>>, female: array<int, array<int, float>>}  $before
     * @param  array{male: array<int, array<int, float>>, female: array<int, array<int, float>>}  $after
     */
    private function lifeExpectancyShift(array $before, array $after): string
    {
        $b = new CohortLifeTable($before);
        $a = new CohortLifeTable($after);
        $year = OnsPeriodMortalityData::FIRST_YEAR + 1;
        $line = fn (Sex $s): string => round($b->lifeExpectancy($s, 65, $year), 1).' → '.round($a->lifeExpectancy($s, 65, $year), 1);

        return 'male '.$line(Sex::Male).', female '.$line(Sex::Female);
    }

    /**
     * @param  list<array{sex: string, age: int, year: int, from: float, to: float}>  $samples
     */
    private function sampleTable(array $samples): void
    {
        if ($samples === []) {
            return;
        }
        $this->table(
            ['Sex', 'Age', 'Year', 'From q(x)', 'To q(x)'],
            array_map(fn (array $s): array => [$s['sex'], $s['age'], $s['year'], $s['from'], $s['to']], $samples),
        );
    }
}
