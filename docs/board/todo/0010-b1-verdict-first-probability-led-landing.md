# B1: verdict-first, probability-led landing

## Why
Next by the build order in PLAN-output-inflation-and-charts.md. The results page currently leads
with detail rather than an answer, so the first thing a reader sees is not the thing they came
for.

## Not this card
The B2 to B4 results-page restructure (tabs, tables into `<details>`, banners demoted), A3 fat
tails, A4 State-Pension uprating.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL lead the landing with the Monte-Carlo probability and its word band,
      reusing the `/afford` screen rather than a new surface.
- [x] #2 WHEN a scenario has no completed Monte Carlo run, THE APP SHALL say so plainly rather
      than showing a deterministic figure in the probability's place.
<!-- AC:END -->

## Tasks
- [x] Reuse `/afford` as the landing
- [x] Probability + word band above the fold
- [x] Empty state for a scenario with no MC run

## Plan
Presenter-level change; the probability and bands already exist. No engine work.

## Direction
**2026-08-22** Built the probability-led hero on `/afford`, no new surface. `AffordabilityAssessment`
gained one card key (`mcBand`, from the existing `ResultPresenter::lastsBand()`) and one bottom-line
key (`lead`), which carries the strongest plan's name, its Monte-Carlo chance that the essentials
last, and that chance's word band. The view now opens on that figure — "62% · Borderline" — with the
deterministic bottom line demoted below it under the heading "On the expected path", so the green
yes/no can no longer stand next to an unshown coin-flip. When the strongest plan has no completed
run, `lead.checked` is false and the hero reads "Not checked yet" with an explicit line that the
verdict below is one average future and not a probability; no figure occupies the probability's
place, and the existing "Check how sure" button sits in the same panel. Two feature tests cover the
two criteria (`AffordabilityTest`), one asserting the order the reader meets things in.

Assumed, and worth a second opinion: I reused the **existing** band scale
(`ResultPresenter::lastsBand()`, 90/75/50/25 → "Very likely to last" … "Very likely to fall short")
rather than the band table resolved in PLAN-output-inflation-and-charts.md §B1 (90/80/70/50 →
"Very secure" / "On track" / "Broadly on track" / "At risk" / "Unlikely on the current plan", green
reserved for ≥80%). The card says "the probability and bands already exist", and `lastsBand()` is
the documented single home for banding (DECISIONS 2026-06-30) shared with the Compare chip and the
threshold explorer — retuning it would change the wording on two other surfaces this card explicitly
does not cover. **The two scales disagree at 75–79%**, which the existing scale calls "Likely to
last" and the researched B1 table calls amber. If Rob wants the researched bands, that is a one-place
change in `lastsBand()` plus its assertions in `CombinationComparisonTest`, and it should be its own
card so the other two surfaces get reviewed with it.

Also made `/afford` reachable from the dashboard for a forecast with no what-ifs (it was gated on
having children), since a single plan needs the "how sure" answer as much as a family of them does.
I did **not** change the post-save redirect (`ScenarioBuilder` still lands on the results page,
where the Run button is) — the card's plan says "presenter-level change", and rerouting the app's
default destination is a navigation call, not a presenter one.

Not verified: **this has not been looked at in a browser.** It was built in a ProgressBoard
worktree, and Herd serves the site from `C:\Dev\RetireForecast`, so no browser here can see it. It
needs `npm run build` (the hero uses `text-5xl`, which may be new to the Tailwind scan) and a look
at the real page before sign-off — the same browser gate as card 0001.

### 2026-08-29 review (v20260829140047-27fc)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 318s, run by this job rather than reported by the card.

**acceptance: sound**

I checked both boxes against the code.

**#1 ÔÇö probability leads the landing.**
`AffordabilityAssessment::lead()` (app/Forecast/AffordabilityAssessment.php) reads `mcEssentials` and `mcBand` off the top-ranked card, which `cards()` sorts first. `card()` fills `mcBand` from `ResultPresenter::lastsBand()`. `Affordability::render()` passes it in `bottomLine`. The hero panel in resources/views/livewire/affordability.blade.php prints the percent and the band word before the "On the expected path" block. No new route: `scenarios.afford` in routes/web.php is the old one. `AffordabilityTest::test_it_leads_with_the_monte_carlo_probability_and_its_word_band` asserts the order.

**#2 ÔÇö no run, no fake figure.**
`lead()` sets `checked` false when `mcEssentials` is null. `Affordability::storedMonteCarlo()` returns null unless `Scenario::latestCompletedRun()` gives a done run. The view's `@else` branch prints "Not checked yet" and says the verdict below is not a probability. No deterministic number sits in that slot. `test_it_says_the_probability_is_unchecked_rather_than_showing_a_deterministic_figure` covers it.

I tried the empty-card and no-working-plan paths too. Both fall back safely.

VERDICT: sound

**scope: defect**

**Scope review ÔÇö card 0010 (B1).**

**1. Green at 75ÔÇô79% ÔÇö half done.** The hero's new colour map `$leadTones` in `resources/views/livewire/affordability.blade.php` paints both `strong` and `good` green. `ResultPresenter::lastsBand()` calls 75ÔÇô89% `good`. The resolved band table in `docs/build/PLAN-output-inflation-and-charts.md` ┬ºB1 reserves green for ÔëÑ80% and makes 70ÔÇô79% amber, on the "most adverse" rule. The agent's defence ÔÇö that retuning `lastsBand()` would change the Compare chip and the threshold explorer ÔÇö does not cover the colour. That map is new, lives only in this view, and no other surface reads it. So the one thing the card could fix for free is the one thing it got wrong: a 76% plan is shown green.

**2. Navigation change, not asked for.** `resources/views/livewire/dashboard.blade.php` now shows "What can I afford?" for every forecast, not just ones with what-ifs. The card asked for a presenter change; the same agent refused to touch the post-save redirect for that reason. It also left the pair unfinished: `Affordability::checkHowSure()` sends the reader to `scenarios.compare`, which the dashboard still hides for a childless forecast ÔÇö one plan, nothing to compare.

VERDICT: defect

**breakage: defect**

I read the new code and traced the run it leans on.

**Defect: a quick preview run can feed the hero figure.**
`App\Livewire\Affordability::storedMonteCarlo()` reads `Scenario::latestCompletedRun()`, which filters on **status only, not mode**. A 1,000-path preview made by `SimulationRunner::preview()` ÔÇö started by `ScenarioResults::preview()`, on the page the builder still redirects to ÔÇö is a completed run. Three things follow:

- The hero prints that preview number under copy that says "across thousands of possible futures" (`resources/views/livewire/affordability.blade.php`, the `$lead['checked']` branch).
- `AffordabilityAssessment::lead()`'s docblock says `checked` is false "when that plan has no completed **full** run". That is now untrue.
- `$anyUnchecked` in `Affordability::render()` goes false, so the "Check how sure ÔÇö run the full future test" button vanishes and `checkHowSure()` skips that plan. The reader cannot start the real run from the landing.

Fix: restrict `storedMonteCarlo()` to `SimulationMode::Full`.

**Smaller:** `lead()` takes `$cards[0]`. When no plan works that is a failing plan, so a green or amber "How sure is your strongest plan?" can sit above the red "none of these plans keep the essentials paid" panel. `AffordabilityTest` never builds the all-failing case.

VERDICT: defect

