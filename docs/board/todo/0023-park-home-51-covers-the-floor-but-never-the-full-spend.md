---
needs: 0025, 0161
---
# Scenario 51 covers the essentials 76% of the time and the full spend 0% of the time

## Why
The 2026-08-12 Monte Carlo put scenario 51 "Park home Tring GBP 150k" at 76.4% on essentials and
0.0% on full spend. Diagnosed and confirmed (2026-08-29): an unfunded purchase shortfall of GBP 1.37
failed every path's all-or-nothing full-spend test. Card 0025 fixed the engine; fresh 500-path runs
read 79.8% / 78.6%. Only #4 is open, and it waits on card 0161.

## Links

**Blocked by**
- `0025` - its engine fix is what moved the full-spend figure; it is still in review.
- `0161` - #4 is the audit exiting 0, which needs the app database re-seeded and re-run first.

## Not this card
Re-pricing the park homes, the other park-home scenarios, or the ranked report.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN scenario 51 is projected, THE APP SHALL show why the full-spend probability is 0.0%,
      traced to a named input (the pitch fee plus upkeep, the depreciation, the purchase gap, or the
      discretionary line itself) rather than to an unexplained interaction.
- [x] #2 IF the 0.0% is an artefact of the GBP 46,412 completion gap being modelled as unfunded,
      THEN THE APP SHALL either charge that gap visibly or the scenario SHALL be corrected, so no
      stored scenario reports a probability that rests on an unseeable figure.
- [x] #3 WHEN the check is complete, THE APP SHALL have the stale "51 cannot complete" warning in
      SCENARIO-V2.local.md either confirmed and restated with current figures, or removed.
- [ ] #4 WHEN `php artisan scenarios:audit` is run afterwards, THE APP SHALL exit 0.
<!-- AC:END -->

## Plan
After card 0161 is done (migrate, re-seed `AssumptionSetSeeder`, Re-run all), run
`php artisan scenarios:audit`. Exit 0 ticks #4 and the card goes to `ai-review/`. Any problem line
about #51 itself reopens the investigation.

## Direction

**2026-08-29 - confirmed as an artefact, and no code was needed.** #51 carries an unfunded 2026
lump labelled "Unfunded purchase shortfall", GBP 1.37; #53 carries none and is otherwise identical.
It is charged and shown (`WarningCode::UNFUNDED_ONE_OFF_COST`, the `unfunded_one_off` note). The
GBP 46,412 note was stale and is restated in `docs/SCENARIO-V2.local.md`. #51 is the only stored
scenario with an unfunded one-off. The stored 10,000-path run still shows 0.0% until re-run.

**2026-10-05** Re-checked: #1 to #3 still hold. `scenarios:audit` exits 1 on 123 problems in two
classes, none about #51: 120 runs with no integrity stamp, and 3 assumption sets missing
inflationPersistence, inflationAssetCorrelations and economicSourcing. Raised as card 0161.

## Comments

**2026-10-05** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 4 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

**2026-10-07** Moved to todo/: there is no decision here for Rob. The only open item is #4, which
waits on card 0161 (bring the app database up to the code). Added `needs: 0161`, as both the
2026-10-05 session and the manager pass recommended, so the loop stops picking this card up until
0161 is done.
