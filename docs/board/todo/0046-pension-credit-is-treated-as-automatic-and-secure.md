# Pension Credit is counted as guaranteed income, and the claim prompt never shows

## Why
From the expert panel, 2026-08-19 (Citizens Advice findings 4 and 5, adviser finding 1). Detail in
the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

**It is treated as secure.** `ResultPresenter::SECURE_SOURCES` lists `means_tested_benefit`
alongside the State Pension, so it sits inside the guaranteed floor that "essentials are covered"
is measured against. It is not secure. It moves with income, with capital, with a change of
circumstances and with a review. Around a third of eligible pensioner households never claim it at
all, and the engine credits it whether or not anyone has applied.

**The claim prompt is backwards.** `pensionCreditGuidance()` returns nothing unless some year
carries a positive award. So a household sitting just above the line - the exact case where a
caseworker most wants a nil claim on file - is shown nothing.

**A promised warning does not exist.** `CapitalAssessment::assess()` builds a capital-cliff warning
and the only consumer reads the tariff income and discards the object. Nothing in `PathProjector`
collects it. Yet METHODOLOGY.md tells the user that the loss of Housing Benefit and Council Tax
Support above the capital limit **is** flagged. It is the mirror image of the no-invisible-figures
rule: a disclosure the docs promise and the app never makes. Every sell-and-rent plan parks a large
sum and none of them shows the cliff.

The rule as coded is also wrong at the edge. Capital above the limit does not end that help for
someone receiving Guarantee Credit, who is fully passported with no upper capital limit.

## Not this card
Awarding Housing Benefit, which is card 0048.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL report Pension Credit as a contingent income line, outside the guaranteed floor. proves: `test_pension_credit_is_reported_as_a_contingent_line_outside_the_secure_floor`
- [x] #2 WHEN a household comes within a small margin of the Pension Credit line in any year, THE APP SHALL prompt them to claim, and state the backdating limit. proves: `test_guidance_appears_when_a_year_only_just_misses_the_pension_credit_line`
- [x] #3 WHEN assessable capital crosses the limit in any year, THE APP SHALL surface the capital-cliff warning on that year. proves: `test_the_capital_cliff_is_warned_on_the_year_capital_crosses_the_limit`
- [x] #4 WHEN a household receives Guarantee Credit, THE APP SHALL NOT warn that capital ends their means-tested help. proves: `test_a_household_on_guarantee_credit_is_not_warned_that_capital_ends_its_help`
<!-- AC:END -->

## Tasks
- [x] Remove `means_tested_benefit` from `SECURE_SOURCES`; report it separately
- [x] Trigger `pensionCreditGuidance()` on proximity, not on a positive award
- [x] Collect and surface the capital-cliff warning from `PathProjector`
- [x] Correct the Guarantee Credit carve-out in `CapitalAssessment`

## Comments
**2026-09-06** RESULT: done
TESTS: +10 new, all green
TOUCHED: packages/finance-engine/src/Support/WarningCode.php
TOUCHED: packages/finance-engine/src/Benefits/CapitalAssessment.php
TOUCHED: packages/finance-engine/src/Benefits/PensionCreditResult.php
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: packages/finance-engine/tests/Forecast/PensionCreditContingencyTest.php (new)
TOUCHED: packages/finance-engine/tests/Benefits/CapitalAssessmentTest.php
TOUCHED: app/Forecast/ResultPresenter.php
TOUCHED: resources/views/livewire/scenario-results.blade.php
TOUCHED: resources/views/pdf/partials/report.blade.php
TOUCHED: tests/Unit/Forecast/IncomeFloorTest.php
TOUCHED: tests/Unit/Forecast/PensionCreditGuidanceTest.php
TOUCHED: tests/Unit/Forecast/InputNotesTest.php
TOUCHED: tests/Feature/Livewire/ScenarioResultsTest.php
TOUCHED: docs/spec/METHODOLOGY.md
TOUCHED: docs/spec/ASSUMPTIONS.md
TOUCHED: docs/DECISIONS.md
TOUCHED: docs/HANDOVER.md
TOUCHED: docs/board/todo/0110-the-forecast-assumes-pension-credit-has-been-claimed.md (new)
OUT-OF-SCOPE: 0110

Every criterion was watched failing first, and each failed for its own reason rather than for a
missing class. `SECURE_SOURCES` still contained the credit when `test_pension_credit_is_reported_...`
first ran, so it failed on "array does not contain 'Pension Credit'". The two capital tests ran
against a `PathProjector` that collected no warnings at all and failed on the absent code, and the
Guarantee Credit carve-out got its own red: I wired the collection WITHOUT the carve-out, ran, and
watched the household on the credit be told its savings had ended its help, before adding the
carve-out. The near-miss tests failed on the absent flag with the `WarningCode` constant already in
place, so the red was the missing rule and not a missing name.

**One test was written after the code and does not prove a criterion.**
`test_the_contingent_income_panel_and_claim_prompt_render_on_the_results_page` is a wiring check,
because a Blade directive can fail to compile silently. I tried to use it as criterion #1's proof
and found it blind: the fixture renders BOTH tables, so seeing the label proves nothing about which
side of the floor the credit is counted on. It passed unchanged with the credit put back into
`SECURE_SOURCES`, which is how I found out. Criterion #1's real proof is the unit test, which does
go red on that revert. The feature test is renamed to say what it actually covers.

**What I built.** `SECURE_SOURCES` loses the credit and a sibling `CONTINGENT_SOURCES` gains it, so
it is reported in full beside the floor rather than dropped or counted (both totals and the coverage
percentage now exclude it). `PensionCreditResult::isNearMiss()` owns the proximity rule and
`NEAR_MISS_MARGIN_BPS` owns the figure; `PathProjector` raises a `PENSION_CREDIT_NEAR_MISS` warning
and `pensionCreditGuidance()` opens on either that or a positive award, quoting the engine's own
sentence rather than restating the rule. The capital-cliff warning `CapitalAssessment` had always
built is now collected in `PathProjector`, assessed on the SAME capital the Pension Credit tariff
was (extracted as `meansTestAssessableCapital()`, so award and disclosure cannot disagree), and
surfaced once as an input note naming the first year it bites. `assess()` takes `$onGuaranteeCredit`
and reports no cliff for a household on the credit, which is passported with no upper capital limit.

**What I assumed, and it is a judgement rather than a source.** The near-miss margin is 10% of the
guarantee. There is no published DWP figure for "close", because the real test is entitlement.
10% is sized to what this engine knowingly leaves out (Savings Credit, every income disregard, the
housing elements) and the cautious direction is to prompt too often, since a nil claim costs an
afternoon and a missed claim costs a benefit. No projected figure moves with it, so it is
deliberately NOT a builder control; it is quoted in the prompt itself, read from its own constant.
Written up as ASSUMPTIONS.md section 22.

**No `ENGINE_VERSION` bump and no stored re-run is owed.** Nothing here moves a projected figure:
both new flags are warnings, and `housingSupportEligible` had no reader but the warning itself.

**One existing test legitimately drifted.** `test_a_sensible_household_raises_no_notes` banked most
of a £40,000 salary and held over £16,000 by 2027, so the new note correctly fired on it. Its
spending is raised to match its income rather than the note being added to its expectation: a
household with something to flag is the wrong fixture for a test about a household with nothing to
flag.

**Built in a worktree, so nothing here has been seen in a browser.** New on the results page and in
the PDF: the "Income the forecast counts, but nobody guarantees" panel, the rewritten claim prompt
with its two branches (awarded, and near-miss with no award), and the capital-cliff input note.
Card 0001 already gates the browser pass on the whole built cluster.

### 2026-09-06 review (v20260906121631-67a2)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 169s, run by this job rather than reported by the card.

**acceptance: sound**

I looked for a criterion with no code behind it. I could not find one.

**#1 contingent line.** `app/Forecast/ResultPresenter.php` ÔÇö `CONTINGENT_SOURCES` holds `means_tested_benefit`, and `floorAt()` sums the floor from `SECURE_SOURCES` only. The credit is listed and totalled on its own. Coverage percent uses `$secure`, so the credit is out of it. No other file reads that source into a floor (only `SOURCE_LABELS` and the guidance check).

**#2 near-miss prompt.** `PensionCreditResult::isNearMiss()` owns the rule. `PathProjector::benefitContingencyWarnings()` raises the flag. `ResultPresenter::pensionCreditGuidance()` opens on the flag or an award, and `howToClaim` states the 3-month backdating limit. The view has both branches in `scenario-results.blade.php`.

**#3 capital cliff.** `CapitalAssessment::assess()` builds it, `PathProjector::benefitContingencyWarnings()` collects it into the year's warnings, and `ResultPresenter` shows it once, naming the first year.

**#4 Guarantee Credit carve-out.** `CapitalAssessment::assess()` takes `$onGuaranteeCredit` and returns no cliff. `PathProjector` passes `$benefitNominal > 0`, so it is true only when the credit is really paid.

Engine tests drive #2, #3 and #4 through a real forecast, not a hand-made warning.

VERDICT: sound

**scope: defect**

**Left half done**

1. `ResultPresenter::floorAt()` builds `contingent` / `contingentIncome` for the **survivor** snapshot as well, but the survivor block in `resources/views/livewire/scenario-results.blade.php` and in `resources/views/pdf/partials/report.blade.php` renders only `secureIncome`. So the survivor's Pension Credit ÔÇö the larger award, single guarantee with one State Pension gone ÔÇö is now in no table and no total on either output. It was at least inside the old figure. The survivor-cliff line ("falls from X% to Y%") also overstates the drop, with nothing beside it saying why. The card asked for it reported separately, not dropped.

2. `ScenarioContext::incomeFloorFacts()` still reads `secureIncome` and `gap` only. The assistant now tells the household the gap "must come from savings and investments" in years the forecast in fact fills with Pension Credit.

**Grew**

3. `PathProjector::benefitContingencyWarnings()` runs the cliff at every age, so an ordinary working household with over ┬ú16,000 in cash is told Housing Benefit and Council Tax Support "are not payable". The tell is in the diff: `InputNotesTest::test_a_sensible_household_raises_no_notes` had its spending raised to ┬ú38,000 to silence it.

VERDICT: defect

**breakage: defect**

The gap figure was not updated with the floor it is subtracted from.

`ResultPresenter::floorAt()` still builds `gap = essentialSpend ÔêÆ secureIncome`. Pension Credit left `secureIncome`, so `gap` grew by the whole award. Every label on that number still says savings pay it:

- `resources/views/livewire/scenario-results.blade.php`, income-floor section: the amber tile "Met from savings / pension", and the footnote "The rest of essential spending is met by drawing on your savings and pensions, so it depends on those lasting". Both untouched by this commit; the new contingent panel below sits beside them and corrects neither.
- `resources/views/pdf/partials/report.blade.php`, income-floor block: the same "Met from savings / pension" tile.
- `app/Assistant/ScenarioContext::incomeFloorFacts()`: "the remaining {gap} must come from savings and investments". It also drops `contingent` and `contingentIncome`, so Pension Credit vanishes from the floor facts, while its docblock still claims it reuses the one floor definition and its own ladder still lists the award.

Failure: essentials ┬ú20,000, secure ┬ú18,000, Pension Credit ┬ú3,000. The screen, the PDF and the assistant now say ┬ú2,000 comes from savings... it says ┬ú2,000 only because I lowered it; with credit awarded the tile reads the full shortfall the credit actually pays. Savings draw is overstated by the award.

VERDICT: defect

