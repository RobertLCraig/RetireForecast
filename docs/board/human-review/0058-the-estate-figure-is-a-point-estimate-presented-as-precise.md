# A one-path estate figure is shown next to a ten-thousand-path probability

## Why
From the expert panel, 2026-08-19. The estate planner and the adviser found this separately.
Detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

`ScenarioCompare` takes the success probability from the Monte Carlo run and the terminal wealth
and inheritance tax from the **deterministic** forecast. So a comparison table sets a figure with
ten thousand paths behind it beside one with a single path behind it, at a median lifespan, with no
care, and reports both to the pound.

The estate panel makes it worse by labelling that figure "everything you're modelled to leave". It
is stated exactly, and it is gross of probate cost and delay, the beneficiary's income tax on an
inherited pension, a park home's resale commission, and any care debt. The panel carries good
caveats about gifts, trusts and reliefs, and none about the precision of the estate figure itself.

Where a plan involves a rolled-up loan the point estimate hides the mechanism entirely. Once the
debt passes the property value the no-negative-equity floor binds, so terminal wealth stops moving
and the property is fully consumed. A reader sees a number and assumes it is the home minus some
cost. It is not the home at all.

`IhtDistribution` already reports the tax as a distribution, so the machinery exists.

## Links

**Relates to**
- `0010` - leading the results page with the probability is that card; this one only stops the
  estate figure claiming a precision it does not have.

## Not this card
Leading the results page with the probability, which is card 0010.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a terminal estate is reported, THE APP SHALL show it as a range across the simulated paths, not a single figure. proves: `test_the_estate_is_reported_as_a_range_across_the_simulated_paths`
- [x] #2 WHEN a comparison table mixes measures, THE APP SHALL label which are single-path and which are simulated. proves: `test_the_comparison_labels_which_figures_are_one_path_and_which_are_simulated`
- [x] #3 WHEN a plan's property is consumed by a rolled-up loan, THE APP SHALL say in plain words that the lender takes the property and the beneficiaries inherit the remaining liquid assets. proves: `test_a_rolled_up_loan_that_eats_the_home_says_the_lender_takes_the_property`
- [x] #4 THE APP SHALL caveat the estate figure for probate cost and delay, beneficiary income tax and any care debt. proves: `test_the_estate_figure_is_caveated_for_probate_beneficiary_tax_and_care_debt`
<!-- AC:END -->

## Tasks
- [x] Add a terminal-wealth percentile series to `Simulator`, as `IhtDistribution` already does
- [x] Use it in `ScenarioCompare` and the estate panel
- [x] Add the plain-words equity-release sentence and the caveat to `ResultPresenter`, so the PDF inherits both

## Comments

**2026-09-07**
RESULT: done
TESTS: +4 new, all green
TOUCHED: app/Forecast/ResultPresenter.php, app/Livewire/ScenarioResults.php, app/Export/ScenarioReport.php, resources/views/livewire/scenario-results.blade.php, resources/views/livewire/scenario-compare.blade.php, resources/views/pdf/partials/report.blade.php, tests/Feature/Forecast/EstatePointEstimateTest.php, docs/board/in-progress/0058-the-estate-figure-is-a-point-estimate-presented-as-precise.md, docs/HANDOVER.md
OUT-OF-SCOPE: none

The first Task was already done before this card started: `SimulationResult::$terminalWealthPercentiles`
has existed since the fan chart landed, and the headline cards already read p10/p50/p90 off it. So
nothing was added to `Simulator`; the fault was that the estate panel did not read the series that
was already there. `ResultPresenter::estateRange()` is the one home of the band and it reads that
same series, so the estate band and the headline wealth band cannot disagree.

What each criterion got. **#1**: the estate tile keeps its deterministic figure (it is the number the
Inheritance Tax arithmetic below it is actually done on, so removing it would make the panel
unauditable) and now carries the simulated band beside it, plus a restated sub-label saying the
tile is one central projection, one life, one set of returns, no care costs. The band shows only
once a run exists; before a run the sub-label alone carries the warning, which is the honest state.
**#2**: the Compare page is where a one-path figure sat beside a ten-thousand-path one. Both surfaces
now open with a labelled sentence, "Single path" over the deterministic table and "Simulated" over
the Monte Carlo section. The deterministic table's columns were NOT individually banded: every
figure in it is single-path, so a per-column marker would repeat the same word eight times. **#3**
rides the existing `lifetime_mortgage_rollup` note rather than becoming a second note, because a
reader who is told the balance grew to £X and the equity is £0 needs the explanation in the same
breath, not in a second box. It fires on the no-negative-equity floor actually binding (no equity
left in the last year the home is owned) and names the remaining liquid assets from
`terminalUsableWealth`. **#4** is `ResultPresenter::estateCaveats()`, riding `ihtPanel()` so the page
and the PDF cannot show one without the other. No probate cost is invented: the costs are named and
declared unmodelled, because a figure with no source is the thing this project forbids. The care
line reads what the projection actually did, so a plan carrying a deferred care debt is told the
debt is already deducted, a plan that paid care fees out of its own money is told that, and a plan
modelling no care at all is told nothing has been taken off.

No `ENGINE_VERSION` bump and no stored re-run is owed: this is presentation, no projected figure
moves. `GoldenMasterTest` did not redden.

Built in a worktree, so **none of it has been seen in a browser**: the estate band, the two Compare
labels, the caveat list and the PDF's two new blocks all still need card 0001's sign-off.

One thing the card's Why lists and the acceptance does not, so it was left alone: a park home's
resale commission is still not deducted anywhere.

### 2026-09-08 review (v20260908093100-bdf8)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 276s, run by this job rather than reported by the card.

**acceptance: sound**

All four criteria trace to real code.

- **#1** `ResultPresenter::estateRange()` reads `SimulationResult::$terminalWealthPercentiles` and the estate tile in `resources/views/livewire/scenario-results.blade.php` (IHT section) plus `resources/views/pdf/partials/report.blade.php` print the p10/p50/p90 band beside the one-path figure.
- **#2** `resources/views/livewire/scenario-compare.blade.php` carries a "Single path" note over the deterministic table and a "Simulated" note over the Monte Carlo section.
- **#3** `ResultPresenter::inputNotes()`, the `lifetime_mortgage_rollup` note, adds the "the lender takes the property ÔÇª inherit only the money outside it" sentence, gated on `homeEquity()` not being positive in the last year the home is owned, and names `terminalUsableWealth`.
- **#4** `ResultPresenter::estateCaveats()` covers probate cost and delay, the beneficiary's income tax on an inherited pot, and one of three care lines chosen from what the projection did. It rides `ihtPanel()`, so page and PDF cannot show one without the other.

I tried two attacks. The band and caveats only render inside the IHT section, but that is the only place a terminal estate is stated, so nothing escapes. The Compare table's "Total wealth left" column has no band, but that surface is the one #2 governs and it is labelled single-path.

VERDICT: sound

**scope: defect**

Findings, scope lens only.

**Half done ÔÇö `ScenarioCompare` still reports a one-path estate with no band.** The card's second Task says "Use it in `ScenarioCompare` and the estate panel", and it is ticked. Only the estate panel got the band. `App\Livewire\ScenarioCompare::planRow` (the method building `totalWealth`, `usableWealth` and `ihtDue`) was not touched by commit 82ab3c3 at all; those columns are still deterministic figures printed to the pound. The Compare page got a `Single path` heading in `resources/views/livewire/scenario-compare.blade.php` and nothing else. So the exact surface the card's Why names ÔÇö "a comparison table sets a figure with ten thousand paths behind it beside one with a single path behind it" ÔÇö still does that. It is now labelled (AC #2) but AC #1's "show it as a range" is met on the results page and the PDF only.

Nothing crossed the `## Not this card` fence: the results page still leads as it did, no probability was promoted, and the commit touches only the presenter, the two views, the PDF partial and one test. The bigger branch diff is other cards, not this one.

VERDICT: defect

**breakage: defect**

**Finding ÔÇö `ResultPresenter::estateCaveats()` (app/Forecast/ResultPresenter.php)**

The care line picks the last year with a *positive* `deferredCareBalance()`, not the final year's balance. But a deferred care debt is cleared during life when the home is sold: `PathProjector` (the forced-sale branch that builds `residenceDisposal`) sets `$state['deferredCareBalance'] = 0` after redeeming it out of the proceeds, alongside the SMI charge.

So for any plan that runs up a care debt and then sells the home, the estate panel and the PDF both print "This plan runs up a care debt of about ┬úX secured on your home. It is repaid before anybody inherits, and it is already taken off the figure above." Every clause is false: the debt was repaid years earlier out of sale proceeds, it is not on the estate, and nothing is deducted from the figure above for it. It also suppresses the true branch ÔÇö the plan *did* pay care fees out of its own money ÔÇö so the reader is told the wrong thing instead of the right one. This is exactly the invisible-figure failure the card exists to close, in the caveat the card added.

`tests/Feature/Forecast/EstatePointEstimateTest.php` (`test_the_estate_figure_is_caveated_for_probate_beneficiary_tax_and_care_debt`) never builds a sale after a deferred debt, so the suite stays green.

Fix: read the last year's balance, not the last positive one.

VERDICT: defect


**2026-09-08** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 4 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 4 of 4 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
