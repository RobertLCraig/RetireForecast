<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Compliance\Interpretation;
use App\Compliance\NeutralZoneScanner;
use Illuminate\Console\Command;

/**
 * Lists every place in the neutral zone that reads as advice rather than guidance — i.e. every
 * file outside the walled-off {@see Interpretation} layer that contains directive
 * recommendation phrasing.
 *
 * This is the standing "flag it for later" tool for the personal-use posture (DECISIONS 2026-07-04):
 * while `compliance.personal_use` is true the build-time partition test is relaxed (it skips rather
 * than fails), so directive wording can live anywhere for the owner's private/family use — but it
 * stays FINDABLE here, so before any public release the inventory can be reviewed and either moved
 * behind the `interpret` gate or reworded. Reads the zone definition from the same
 * {@see NeutralZoneScanner} the partition test uses (one home, no drift).
 *
 * By default it is a report and exits 0 (a flag, not a gate). Pass --strict to exit non-zero when
 * any advice spot exists — the form a pre-release CI check would use.
 */
class AuditAdvicePhrasing extends Command
{
    protected $signature = 'compliance:advice-audit {--strict : Exit non-zero if any advice phrasing exists in the neutral zone}';

    protected $description = 'List advice-vs-guidance spots (directive phrasing outside the walled-off interpretation layer).';

    public function handle(): int
    {
        $offenders = NeutralZoneScanner::advice();
        $posture = config('compliance.personal_use')
            ? 'personal-use advice mode (partition relaxed; these are flagged, not failures)'
            : 'public guidance-only posture (partition enforced; these fail the build)';

        $this->line("Posture: {$posture}");

        if ($offenders === []) {
            $this->info('No advice phrasing in the neutral zone — everything outside the interpretation wall is guidance-side.');

            return self::SUCCESS;
        }

        $rows = [];
        $total = 0;
        foreach ($offenders as $path => $phrases) {
            $rows[] = [$this->relative($path), implode(', ', $phrases)];
            $total += count($phrases);
        }

        $this->table(['File (advice, not guidance)', 'Directive phrases found'], $rows);
        $this->warn(sprintf('%d advice phrase(s) across %d file(s). These are education/guidance-line crossings to review before any public release.', $total, count($offenders)));

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    private function relative(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
