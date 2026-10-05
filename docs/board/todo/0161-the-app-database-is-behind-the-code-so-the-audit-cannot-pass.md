---
not_for_the_loop: rewrites the stored assumption sets and every stored run in the app database, which deleting a file cannot undo
---
# The app database is behind the code, so `scenarios:audit` cannot pass

## Why
On 2026-10-05 `php artisan scenarios:audit` exits 1 on two problem classes, and neither is about a
scenario's own figures:

- 120 lines of "run N carries no integrity stamp (it predates the column)". HANDOVER already says
  these clear only when every stored scenario is re-run.
- 3 lines saying each stored assumption set is missing `inflationPersistence`,
  `inflationAssetCorrelations` and `economicSourcing`, "NOT reaching any forecast". This is new
  since card 0023's 2026-08-29 pass. The shipped sets gained those figures (cards 0062, 0064, 0065),
  but the rows in the app database were never re-seeded.

Every card that ends with "the audit SHALL exit 0" is blocked on this, card 0023 #4 among them. The
audit is the release gate, so it is red for a reason no engine card can fix.

## Links

**Blocks**
- `0023` - its #4 is the audit exiting 0.

## Not this card
- Any engine or audit change. Both checks are right; the data is stale.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN the stored assumption sets are re-seeded and every stored scenario is re-run, THE APP SHALL report no "missing shipped figure" and no "carries no integrity stamp" line from `php artisan scenarios:audit`. proves: none
<!-- AC:END -->

## Plan
Person-driven, against `C:\Dev\RetireForecast` with the queue worker running. In this order:
`php artisan migrate` (card 0018's hash column), then
`php artisan db:seed --class=Database\Seeders\AssumptionSetSeeder`, then "Re-run all" on Compare,
then `php artisan scenarios:audit`. Re-seed BEFORE the re-run, or the runs are stamped on the old
figures. Read the ranked report off only after the audit is clean.

## Comments
