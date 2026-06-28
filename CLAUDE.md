# RetireForecast — agent instructions

**Orient before changing anything.** Read HANDOVER.md first; it indexes this project's
docs (PRD.md, DATA-MODEL.md, DECISIONS.md, and the full plan at docs/PLAN.md). Restate the
goal, success criteria, and data shape before proposing changes. Do not act on a partial
read. This follows the global project documentation standard.

## Conventions
- **Run from the project root** (`C:\Dev\RetireForecast`). The test runner shells out to a
  relative phpunit path and fails from elsewhere.
- **Run PHP via PowerShell, not Bash.** `php` / `artisan` / `composer` / PHP-related `npm`
  are **not** on the Git Bash PATH on this machine (Bash returns `php: command not found`).
  PHP 8.4 is provided by **Laravel Herd** (`C:\Users\r\.config\herd\bin\php.bat`) and is on
  PATH inside the **PowerShell** tool. Use PowerShell for build/test/artisan/composer/npm;
  Bash is fine for git, grep and file ops. Don't re-probe for php each session.
- **Test the engine:** `php artisan test --testsuite=Engine`
- **Test everything:** `php artisan test`
- **Green is the invariant, not a status.** Every commit and checkpoint runs the whole suite
  green. If a change reddens a test, fix the code, or update the test that legitimately drifted
  from a deliberate change — never commit red, never weaken or delete a test to force green.
  Because green is always true at a checkpoint, do **not** record test counts or "suite passes"
  as handover/status prose: they are derivable (run the suite) and only drift.
- **If `vendor/` is missing:** `composer install`. If engine classes are not found,
  re-register the path package: `composer update retireforecast/finance-engine`.
- **The engine is framework-free.** Code under `packages/finance-engine` must never
  `use App\...` or `Illuminate\...`, and must not touch the container, DB, filesystem or
  the clock. Inject config and a clock interface instead. This is what makes the HMRC
  worked-example tests trustworthy.
- **Money is integer pence**, never a float. Use the `Money` value object. Rates are
  `Percent` (integer basis points). Dates are ISO `Y-m-d`. Ages derive from DOB, never
  stored.
- **Every tax figure carries a `source` URL and a `verified_on` date.** No magic numbers.
- **No silent failure.** Every operation reports in-progress / succeeded / failed-with-reason;
  long runs show live progress.
- **Data-layer integrity (hard rule — see DECISIONS 2026-06-25).** Every quantity has **one
  definition, one home.** Derive totals from their parts; never store a total that can drift
  from its components (e.g. `ExpenseProfile::targetAnnualSpend()` sums essential+discretionary;
  ages derive from DOB). Keep the engine DTOs the single source of truth that storage and UI
  map to/from. **Assert reconciliation invariants in tests** (sum of parts == reported total),
  and verify every import against a **sanitised real-file golden fixture** — a synthetic
  happy-path test is not enough (two real double-counting bugs slipped past one). Surface every
  imported/aggregated total for review; a mismatch must be a visible failure, not a silent one.
  **Completeness is the sibling of reconciliation: never silently drop an input that should count.**
  A tax-free income stream (DLA) was dropped from the forecast because only *taxable* streams were
  summed. Every input that should affect a result must reach it — guard with a **per-source
  completeness test** (e.g. salary, DB, State Pension, taxable + tax-free income, DC withdrawals,
  asset drawdown each demonstrably contribute to the forecast).
- **Education/guidance only**, never a personal recommendation. A build-time test must fail
  if any result template contains banned recommendation phrasing.
- **Doc hygiene (the handover stays lean and honest).** Treat docs like the data layer — one
  home per fact, no derivable facts copied in. (1) **Don't write what a tool already owns:**
  test counts (run the suite), commit history (`git log`), the file tree (browse it), "is it
  green" (it's the invariant). Name the command, don't transcribe the output — a transcribed
  derivable is drift-in-waiting. (2) **One home per fact, by lifecycle:** rationale → DECISIONS.md;
  dated narrative → the HANDOVER Session log (prune old entries). The handover *points*, it doesn't
  restate. (3) **Report exceptions, not invariants:** "green / clean / compiles" are baseline-true,
  so they aren't status — only deviations (known bugs, deliberate skips) are. (4) **Sections have a
  tense:** "What's next" is future-only (a finished item *leaves* the list, it doesn't earn a ✅ and
  stay); "Open items" is open-only (no resolved `[x]`). Enforced by `HandoverHygieneTest` — a count
  in live prose, or a finished item that lingers, fails the build (the docs' banned-phrasing-partition
  equivalent).
