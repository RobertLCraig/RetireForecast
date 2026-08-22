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
