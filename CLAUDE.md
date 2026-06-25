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
- **Education/guidance only**, never a personal recommendation. A build-time test must fail
  if any result template contains banned recommendation phrasing.
