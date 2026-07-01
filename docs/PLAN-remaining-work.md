# PLAN: remaining work (pick-up plan for a fresh agent)

> Everything left after Lane A's post-v1 backlog completed (2026-07-01). A fresh agent reads
> `HANDOVER.md` first (goal, data shape, decisions, current state, coordination), then this for
> the prioritised, executable backlog. Nothing here is on a blocking critical path for personal use;
> the tool is feature-complete and green for Rob's own use today.

## How to use this plan
- **Orient first:** read `HANDOVER.md` (esp. "Multi-agent coordination" and "How to pick up") and
  the relevant `DECISIONS.md` entries before touching code. Restate the goal + data shape.
- **Green is the invariant:** `php artisan test` all green before and after each change (run via the
  PowerShell tool + Herd, not Bash — see CLAUDE.md). Money = integer pence; every tax/statutory
  figure carries a source URL + `verified_on`; the engine stays framework-free (no `App\`/`Illuminate\`,
  no I/O, no clock).
- **Multi-lane tree:** other Claude sessions share this repo. Re-check `git status`/`git log` before any
  commit, commit only your own files (no blanket `git add -A`), never push without Rob's go-ahead.
- **Pick top-down.** P0/P1 first. Each item states What / Why / Where / Acceptance. Items marked
  **[needs Rob]** are blocked on a decision; **[lane-owned]** belong to another session — coordinate,
  do not grab.

---

## P0 — Go-live verification & sign-off (mostly Rob; some agent-prep)
The whole post-2026-06-29 cluster is built but unreviewed in the browser. Rob leads sign-off; an agent
can prepare and de-risk it.

- **Re-run forecasts before checking Monte Carlo output.** The local DB has 0 (or stale) completed runs,
  and the estate-inheritance fix + new features changed numbers. *Where:* run the queued sim (worker with
  JIT — see HANDOVER "How to pick up"); or the Compare-page "Re-run all N" button. *Acceptance:* every
  saved couple scenario has a fresh completed run before its MC charts / PDF are trusted.
- **Finish the a11y sweep** (agent-executable). *Why:* one finding fixed (keyboard-focusable scrollable
  tables); the axe/Lighthouse/`npm run a11y` (Pa11y CI) pass is not complete. *Where:* `docs/A11Y.md`,
  results + builder views. *Acceptance:* `npm run a11y` clean (or documented, justified exceptions).
- **Browser checks [needs Rob]:** mobile view of the results "on this page" nav (desktop signed off);
  the 2FA QR enrolment scan; a visual pass of the new panels (annuitisation inputs, stress-test,
  care-risk, withdrawal-sequencing). Not scriptable — Rob's eyes.

## P1 — Public-release blockers (must precede ANY public launch; harmless to defer while private)
The tool is a private personal-use tool today. Each of these must be resolved before it is released
publicly, and each is flagged in code so it cannot be forgotten.

- **Flip the regulatory line.** *What:* set `config('compliance.personal_use')` to false and confirm the
  guidance-only partition re-applies (the `interpret` Gate reverts to a per-user `can_interpret` grant;
  advice-style narratives disappear for ordinary users). *Why:* advice mode is the flagged regulatory
  line (DECISIONS 2026-06-30). *Where:* `config/compliance.php`, `App\Compliance\*`, the `BannedPhrasingTest`
  partition lint. *Acceptance:* suite green with the flag false (it already runs that way in CI), and a
  manual check that no directive wording shows for a non-granted user.
- **Swap the stress-test dataset.** *What:* the historical backtest uses the Jordà–Schularick–Taylor
  Macrohistory data, which is **CC BY-NC-SA (non-commercial)** — fine privately, not for public release.
  Replace `HistoricalReturns` with an OGL/commercially-licensed source (e.g. Bank of England prices +
  a licensed dividend series, or a DMS/Barclays licence), or remove the shipped numbers. *Why:* flagged
  in `HistoricalReturns` + DECISIONS 2026-07-01. *Where:* `packages/finance-engine/src/Forecast/HistoricalReturns.php`,
  `docs/RESEARCH-stress-test-and-official-sources.md`. *Acceptance:* the shipped series carries a
  redistributable licence + `verified_on`; `HistoricalBacktestTest` still green against the new figures.
- **Tighten the CSP to nonces.** *What:* move `script-src` off any relaxation to per-request nonces
  (Alpine CSP build). *Why:* flagged (What's next #3); needs the browser. *Where:* `config/security.php`,
  `resources/js/*`, the layout. *Acceptance:* the app runs under the tightened CSP with no console
  violations; charts + Alpine behaviours still work.
- **Full a11y pass** (P0 sweep completed to a public bar).

## P2 — Optional modelling refinements (unowned, agent-executable; non-blocking)
All flagged as v1 simplifications in code/DECISIONS. Each is self-contained. Pick by value.

### Care-cost (DECISIONS 2026-07-01)
- **Means-test the tail.** *What:* once a household's assessable capital falls below the means-test
  threshold, a local authority contributes, capping the self-funder outflow. v1 charges the gross cost
  (conservative). *Where:* `PathProjector` care block + `Care\CareMeansTest` (the existing hook, currently
  unused by the projection). *Acceptance:* a path whose assets deplete during care stops paying the full
  fee; a reconciliation test.
- **Sex/age-differentiated probability + HSLE timing.** *What:* v1 uses one probability and end-of-life
  timing; refine with a sex/age split and ONS health-state life expectancy for entry age. *Where:*
  `Care\CareAssumptions` + `CareCostSampler`. *Acceptance:* sourced figures + `verified_on`; sampler tests.
- **ONS xlsx auto-parse for `mortality:refresh`.** *What:* the command ingests the ONS data as the JSON
  resource shape; converting a fresh ONS "mortality rates qx" xlsx to that JSON is currently manual.
  Add xlsx parsing (phpspreadsheet, app layer). *Where:* `App\Console\Commands\RefreshMortalityData`,
  `App\Finance\MortalityDataset`. *Acceptance:* `mortality:refresh --from-xlsx <file>` produces the JSON +
  diffs; the engine stays I/O-free (parsing is app-side).

### CGT on selling a let former home (DECISIONS 2026-06-30)
- Auto-model deemed-occupation absences; per-owner band-straddle from each owner's exact income;
  shared-occupancy lettings relief. *Where:* `packages/finance-engine/src/Property/CgtPrivateResidenceCalculator.php`
  + the builder CGT wizard. *Acceptance:* worked-example tests for each rule; the wizard captures the inputs.

### Monte Carlo realism
- Stochastic house-price + salary growth inside the MC (currently deterministic in `SampledPathDraws`);
  post-2031 threshold reindexing already modelled — verify; per-scheme DB escalation (currently one blended
  proxy in `PathProjector::dbEscalation`). *Acceptance:* reproducible-under-seed tests; documented sources.

### Annuitisation (DECISIONS 2026-07-01)
- Explicit retirement-*month* override (proration currently uses birth month); optional annuity/care
  interplay. *Where:* `PathProjector::workFraction`, `Dto\Person`, the builder. *Acceptance:* proration test.

## P3 — Data & CI hygiene (small, high-leverage)
- **Wire the freshness guardrails into CI / a periodic run:** `figures:freshness` (gov.uk statutory figures)
  and `mortality:refresh` (ONS in-sync + staleness) both exit non-zero when something ages or drifts.
  *Acceptance:* a scheduled/CI job runs both and fails loudly. Low-value hardening leftovers (confirm worth
  it with Rob): tamper-evident run hash, forecast caching.

## Cross-lane in-flight — coordinate, do NOT grab [lane-owned]
- **Lane B — forced-housing deferred:** stop the bundled mortgage *payment* after a repay-from-capital
  redemption (a new `while_mortgaged` expense condition, designed not built); the in-place forced-sale
  model. See DECISIONS 2026-07-01.
- **Lane C — withdrawal sequencing:** built (FillBands + the £-delta panel); next steps have their own
  ready plan — see `docs/PLAN-withdrawal-sequencing.md` (PCLS-timing #5, an optimiser #6). Centres on
  `PathProjector::fundShortfall` / `DrawdownStrategy`.
- **Lane D — multiple properties:** a DRAFT proposal in `docs/PLAN-multi-property.md` [needs Rob] — extends
  the `Property` DTO + `PathProjector` (overlaps Lane B's property work); 5 open questions await Rob.

## Open decisions needing Rob [needs Rob]
- **Spreadsheet import:** the line-item expense-categories data-model decision; re-verify the IWT CSP vs the
  real 2023 export. (HANDOVER "Open items".)
- **Demo couple figures:** Rob supplies anonymised figures, entered via the UI (never hardcoded).
- **Multi-property:** the 5 open questions in `docs/PLAN-multi-property.md`.
- **Which P2 refinements are worth building** vs left as documented v1 limits.

## Definition of done (per item)
Green suite; sourced figures carry `verified_on`; a completeness/reconciliation test where a new input or
total is introduced; DECISIONS entry for any non-obvious choice; HANDOVER "Current state" + this plan
updated; committed on `master` (no push) with the `Co-Authored-By` trailer; own-files-only on the shared tree.
