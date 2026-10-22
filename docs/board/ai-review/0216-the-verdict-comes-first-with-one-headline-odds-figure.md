---
needs: 0212, 0213
---
# The verdict comes first, with one headline odds figure

## Why
Found by the full review on 2026-10-21 (layperson pass). Before a first-time reader sees the answer,
`scenario-results.blade.php` shows six header links (L89-96), the quick what-ifs, the "Run the full
10,000-path forecast" button, the tab bar, the disclaimer, an "Output mode" line (L208) and
"(full run, seed N)" (L218). The `php artisan queue:work` hint (L166) is for Rob, not the couple.

The verdict card then shows four overlapping success measures ("Essentials always met", "Full
spending met every year", "...in 90%+ of years", "Chance of running out", L235-239) and needs a
150-word paragraph (L246) to reconcile them.

## Direction
- Put the verdict sentence (L233) first, in large type.
- Show ONE odds figure as the headline: "Your money lasts in X out of 10 futures". Keep two wealth
  figures. Move the other measures into a "More detail" fold-out.
- Move run controls, seed, output mode and the worker hint into a fold-out at the foot of the tab.
- Trim the header to Edit, Compare and PDF.
- Give the run-out point as an age as well as a year: "about 2049, when you would be 91 and 89".

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL render the verdict sentence before any run control on the verdict tab. proves: `test_the_verdict_sentence_comes_before_the_run_controls`
- [x] #2 THE APP SHALL show exactly one headline odds figure on the verdict card. proves: `test_the_verdict_card_shows_one_headline_odds_figure`
- [x] #3 WHEN a plan runs short, THE APP SHALL state each member's age in that year beside it. proves: `test_the_run_out_year_is_given_with_ages`
- [ ] #4 Rob reads the verdict tab in a browser and the answer is the first thing he sees. proves: manual
<!-- AC:END -->

## Comments

**2026-10-22** RESULT: built, #4 open (browser)
TESTS: +4 new (2 in `ScenarioResultsTest`, `VerdictHeadlineTest` x2); 2 drifted and updated for the new plain labels (the "after pension tax" clause from card 0142 kept)
TOUCHED: `ResultPresenter` (`lastsOutOfTen`, `depletionAges`), `scenario-results.blade.php` (verdict hero, run controls and quick what-ifs folded into one `<details>`, card measures and run/mode line behind "More detail", header loses "Create a what-if", which moved into the fold-out)
OUT-OF-SCOPE: the Compare page and the PDF keep their old labels; the jargon pass is card 0221.

The hero shows on every tab, above every control. "X out of 10" is the futures with essentials never
short, rounded DOWN, so 10 out of 10 is said only when no future runs short. The run controls fold
open by themselves when there is no answer yet, or a run is going, failed or was cancelled. The
output-mode label (DECISIONS 2026-06-25) is still on the page, inside "More detail". **Not seen in a
browser.**
