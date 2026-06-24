# RetireForecast — agent instructions

**Orient before changing anything.** Read HANDOVER.md first; it indexes this project's
docs (PRD.md, DATA-MODEL.md, DECISIONS.md, and the full plan at docs/PLAN.md). Restate the
goal, success criteria, and data shape before proposing changes. Do not act on a partial
read. This follows the global project documentation standard.

## Conventions
- **Run from the project root** (`C:\Dev\RetireForecast`). The test runner shells out to a
  relative phpunit path and fails from elsewhere.
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
- **Education/guidance only**, never a personal recommendation. A build-time test must fail
  if any result template contains banned recommendation phrasing.
