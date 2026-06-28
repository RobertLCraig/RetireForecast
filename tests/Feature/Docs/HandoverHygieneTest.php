<?php

namespace Tests\Feature\Docs;

use Tests\TestCase;

/**
 * Doc-hygiene guard for HANDOVER.md — the docs' version of the banned-phrasing partition
 * test. A handover is a cache, not a diary: it must not restate facts a tool already owns
 * (test counts, commit lists), nor let a section fill with its own opposite (done items
 * under "What's next", resolved [x] under "Open items").
 *
 * Rules apply to the LIVE doc — everything above "## Session log", which is immutable dated
 * history and may keep its per-session figures. The principles live in CLAUDE.md "Doc hygiene".
 */
final class HandoverHygieneTest extends TestCase
{
    private function doc(): string
    {
        $path = base_path('HANDOVER.md');
        $this->assertFileExists($path, 'HANDOVER.md is missing.');

        return (string) file_get_contents($path);
    }

    /** Everything before the Session log: the part that must stay current. */
    private function live(string $doc): string
    {
        $cut = strpos($doc, '## Session log');

        return $cut === false ? $doc : substr($doc, 0, $cut);
    }

    /** A single "## Heading" section body, up to the next "## " heading. */
    private function section(string $doc, string $heading): string
    {
        $start = strpos($doc, $heading);
        if ($start === false) {
            return '';
        }
        $rest = substr($doc, $start + strlen($heading));
        $end = preg_match('/\n## /', $rest, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($rest);

        return substr($rest, 0, $end);
    }

    public function test_live_prose_states_no_test_counts(): void
    {
        $live = $this->live($this->doc());
        $smells = [
            '/\b\d+\s+(?:tests?|passed|assertions)\b/i',  // "362 tests", "143 passed", "2917 assertions"
            '/\bengine\s+\d+\s*\/\s*app\s+\d+/i',          // "engine 143 / app 219"
            '/\b\d{2,}\s+green\b/i',                        // "330 green", "320 → 330 green"
        ];
        foreach ($smells as $re) {
            preg_match_all($re, $live, $hits);
            $this->assertSame([], $hits[0],
                "HANDOVER live prose restates a derivable test count (it drifts): '".implode("', '", $hits[0])."'. "
                ."Counts belong only in the Session log; 'green' is the baseline invariant, not status. See CLAUDE.md 'Doc hygiene'.");
        }
    }

    public function test_whats_next_is_future_only(): void
    {
        $next = $this->section($this->doc(), "## What's next");
        $this->assertNotSame('', $next, '"What\'s next" section is missing.');
        $this->assertStringNotContainsString('✅', $next,
            '"What\'s next" has a ✅ — it is future-only; a finished item leaves the list (move it to Current state / Session log).');
        $this->assertStringNotContainsString('- [x]', $next,
            '"What\'s next" has a resolved [x] — it is future-only.');
        $this->assertDoesNotMatchRegularExpression('/\bDONE\b/', $next,
            '"What\'s next" says DONE — move finished work out; this section lists only what is still to do.');
    }

    public function test_open_items_are_open_only(): void
    {
        $open = $this->section($this->doc(), '## Open items');
        if ($open === '') {
            $open = $this->section($this->doc(), '## Blockers');
        }
        $this->assertNotSame('', $open, 'No "## Open items" / "## Blockers" section found.');
        $this->assertStringNotContainsString('- [x]', $open,
            'A resolved [x] is sitting in the open-items list — drop it (resolved items live in DECISIONS / the Session log).');
    }

    public function test_has_a_last_updated_date_stamp(): void
    {
        $this->assertMatchesRegularExpression('/_Last updated:\s*\d{4}-\d{2}-\d{2}/', $this->doc(),
            'HANDOVER needs a well-formed "_Last updated: YYYY-MM-DD" stamp.');
    }
}
