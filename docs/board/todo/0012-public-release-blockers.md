---
no_outward_effect: "public release" here is a config posture (COMPLIANCE_PERSONAL_USE=false) rehearsed in the suite, not a deploy
---
# Public-release blockers

## Why
Four items, each flagged in code, harmless while the app is private and mandatory before any
public launch. Grouped because they share one trigger: the decision to release. Two are done (#1
guidance-only partition, #3 CSP nonces). One is a build (#2: the historical stress-test panel runs
on the Jorda-Schularick-Taylor dataset, which is CC BY-NC-SA and cannot ship publicly). One is a
browser pass (#4) that card 0001 now carries.

## Not this card
Deciding whether to release publicly at all. This card is the work that decision would require.
Licensing a paid dataset (DMS or the Barclays Equity Gilt Study) so a public build keeps the stress
panel is a separate card, raised only if a release is ever scheduled.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN `config('compliance.personal_use')` is false, THE APP SHALL re-apply the
      guidance-only partition, and `php artisan compliance:advice-audit` SHALL report no
      advice spots outside it. proves: `test_personal_use_mode_opens_the_capability_to_everyone`
- [ ] #2 WHEN `config('compliance.personal_use')` is false, THE APP SHALL render neither the
      historical sequence panel nor its PDF twin, and SHALL say in its place that historical stress
      testing is not available in this build; WHEN it is true, both SHALL render as they do today.
      proves: `<write the test name before the code>`
- [x] #3 THE APP SHALL serve a CSP whose `script-src` uses nonces rather than a broad allowance.
      proves: `test_every_script_tag_rendered_carries_the_requests_nonce`
- [ ] #4 THE APP SHALL pass the a11y pass at a public bar (WCAG 2.2 AA, mobile included).
      proves: manual, the four hand checks ride on card 0001's browser pass
<!-- AC:END -->

## Tasks
- [x] Flip `compliance.personal_use` false and confirm the partition re-applies
- [ ] Gate the historical sequence panel and its PDF twin on `compliance.personal_use`, with a
      feature test in each posture
- [x] Tighten CSP `script-src` to nonces
- [ ] Hand a11y checks: done under card 0001, tick when that card records them

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The dataset is
`packages/finance-engine/src/Forecast/HistoricalReturns.php` (licence note in its docblock); the
engine runs it through `HistoricalBacktester` and `HistoricalSequenceDraws`, and the only app caller
is `app/Forecast/ScenarioForecaster.php`. The panel renders in
`resources/views/livewire/scenario-results.blade.php` and its PDF twin in
`resources/views/pdf/partials/report.blade.php`. Gate at the app boundary, not in the engine: the
engine stays framework-free and the private build keeps the dataset. Keep the config read in one
place (the forecaster or presenter), so the two views read one flag. Write two feature tests first,
one per posture, the way `InterpretationTest` pins `compliance.personal_use`; the suite has been
green in both postures since 2026-08-22. No `ENGINE_VERSION` bump: no projected money moves.

## Comments

**2026-08-22** Three unattended passes, condensed. **#1 met:** the audit's one advice spot was a
docblock in `AffordabilityAssessment::verdict()` quoting the banned phrase; reworded, so
`compliance:advice-audit --strict` exits 0. `CombinationComparisonGateTest` was pinned to advice
mode instead of leaning on the suite default, and the whole suite runs green with
`COMPLIANCE_PERSONAL_USE=false`. **#2 researched:** the Bank of England "Millennium" workbook has
share prices only, no dividends or total returns, so a free rebuild would test the wrong number;
written into `docs/research/RESEARCH-stress-test-and-official-sources.md`. **#3 met:**
`SecurityHeaders` mints a nonce per request and hands it to the Vite helper; Livewire reads it back;
`'unsafe-inline'` is gone and the test asserts its absence. `'unsafe-eval'` stays and is the honest
residual: Livewire 4 bundles Alpine, which evaluates through `Function`, and removing it is a
front-end rewrite. **#4 advanced as far as a machine can:** `.pa11yci.json` runs axe's full rule
set at desktop and phone viewports; `npm run a11y:auth` sweeps every signed-in page including all
three states of `/account/security` (it drives 2FA enrolment and turns it off again) and fails the
run if a walked session is lost; `npm run a11y:focus` proves the assistant panel makes the page
behind it `inert` (2.4.11). Six real contrast and naming failures fixed, a glued `@else` that
emptied the builder `<h1>` fixed with a widened `BladeDirectivesCompileTest`, and `collapse.js`
now nests a real `<button>` in each results `<h2>`. 11 public URLs and 19 signed-in page/viewport
scans at zero violations. The five non-automatable 2.2 criteria are worked through in the table at
the top of `docs/spec/A11Y.md`. Left for a person: the ApexCharts canvases, 400% reflow, a
screen-reader walkthrough, link text in context, and colour meaning inside a chart. One thing the
repo could not settle: whether the assistant ships in a public build at all (`ASSISTANT_ENABLED`
needs a local Ollama); if not, 2.4.11 was never a public-bar blocker. Pre-existing pint drift in
`QuickWhatIf.php` and `SimulationRunner.php` is card 0083.

**2026-09-30** **Decided:** Option B for now: a public build ships no historical stress test, and
private use keeps the JST data. Applied without waiting for Rob because it is what both the builder
and the 2026-09-30 manager pass recommended, it costs nothing, and it forecloses nothing: option A
(licence DMS or the Barclays Equity Gilt Study) only becomes a cost when a release is actually
scheduled, and the PRD still says only "possible free public release later". Rob, if you would
rather buy the licence now, say so here and #2 goes back to sourcing the dataset. The four hand
a11y checks in #4 are folded into card 0001's browser pass, where every other hand check on this
project already sits. Acceptance #2 was rewritten to the gate this decision asks for, and #1 and
#3 now name the tests that prove them. Card moves to `todo/` for the build.
