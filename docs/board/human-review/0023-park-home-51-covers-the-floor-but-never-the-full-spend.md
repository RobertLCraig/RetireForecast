---
needs: 0025
---
# Scenario 51 covers the essentials 76% of the time and the full spend 0% of the time

## Why
The full Monte Carlo on 2026-08-12 (10,000 paths, seed 20260812) put scenario 51 "Park home Tring
GBP 150k" at **76.4% on essentials and 0.0% on full spend**. Every other plan in the set has the two
figures within a few points of each other; a 76-point gap is not a gradient, it is a cliff, and
0.0% means the discretionary budget was never once met in ten thousand futures.

That is either a true and important finding (the plan is affordable only if they give up all
discretionary spending, permanently) or an artefact. It cannot be left ambiguous, because 51 sits
sixth on the ranked chart and reads as a plan that works.

There is already a standing doubt about this scenario: SCENARIO-V2.local.md carries a warning that
the "51 cannot complete, the GBP 46,412 gap exceeds their savings" note predates the 2026-07-29
rebuild, and that `scenarios:audit` now reports 51 as never running short. Two unexplained things
about the same scenario is one too many.

## Links

**Blocked by**
- `0025` - the 0.0% figure is almost certainly the unfunded-one-off defect, so fixing that first
  turns this card into a confirmation rather than an investigation.
## Not this card
Do not re-price the park homes, do not touch the other three park-home scenarios unless the same
defect is found in them, and do not change the ranked report. This card establishes whether the
figure is real. Acting on it is a separate card.

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

## Tasks
- [x] Project 51 and 53 side by side (both park homes without the art sale) and diff the year rows;
      53 scores 76.1% on full spend, so the pair isolates what 51 does differently.
- [x] Check whether the purchase gap on 51 is funded, charged, or silently absorbed.
- [x] Confirm the essentials/full-spend split is reading the same expense profile the builder stored.
- [x] Record the finding in SCENARIO-V2.local.md and correct or delete the stale warning.


## Direction

**2026-08-19 - the expert panel believes this is diagnosed, and it is not specific to 51.**
Two reviewers reached the same answer separately. `fullSpendAlwaysMet` is all-or-nothing across a
whole path. `HousingComparison::withHousing()` charges an unfunded purchase gap through
`withOneOffCost()`, and `oneOffCostsNominal()` adds that to the spend target but **not** to the
essential floor. The gap is a year-0 constant, independent of the sampled draws, so it produces
unmet spend on 100% of paths - which is exactly a 0.0% full-spend probability with essentials
untouched, and exactly why `scenarios:audit` still reports the plan as never running short.

So acceptance #2 is the live branch: the completion gap is being modelled as unfunded, and the
model is behaving correctly while reporting it in a way nobody can read. Card 0025 is the engine
fix. Do that first, then re-run 51 and 53 side by side to confirm rather than to discover.

Full detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md` (engineer finding F3,
property finding 14).

**2026-08-29 - confirmed as an artefact, and no code was needed.** Card 0025 landed the engine fix
first, exactly as this card's Direction planned, so this session was the confirmation it asked for.
No production code changed here.

**What I ran.** Three throwaway scripts against the app database, none of them stored, none of them
left in the repo: a side-by-side deterministic projection of #51 and #53, a sweep of every stored
scenario for an unfunded one-off lump, and a 500-path confirmation Monte Carlo on the two.

**What they say.**

1. *Traced to a named input (#1).* The whole difference between #51 and #53 is one line in one year.
   #51 carries an unfunded 2026 lump labelled **"Unfunded purchase shortfall", GBP 1.37**; #53
   carries none. Everything else is identical: both are `buy_outright`, both read the same expense
   profile the builder stored, both now report `essYears = 1.0000` and `fullYears = 1.0000`, and
   neither runs short. So the cause is the **purchase gap**, not the pitch fee plus upkeep, not the
   depreciation, and not the discretionary line. The reader is shown it: the year carries
   `WarningCode::UNFUNDED_ONE_OFF_COST` and `ResultPresenter::inputNotes()` surfaces it as an
   `unfunded_one_off` note on the results page and in the PDF, quoting the engine's own sentence.
2. *The gap is charged visibly (#2).* GBP 1.37 is not a rounding artefact. `SavingsFunding::draw()`
   is exact integer pence, so the figure is a genuine residue: after the net sale proceeds and every
   liquid account (cash, Premium Bonds, GIA, ISA - never pensions), the plan is GBP 1.37 short of
   the purchase plus SDLT plus moving costs. It is charged, it stays inside `unmetSpend`, and it is
   named on screen. The **GBP 46,412** in the old note is dead: it predates the 2026-07-29 rebuild.
   The sweep found #51 is the **only** stored scenario carrying an unfunded one-off at all, so the
   defect is not present in #52, #53, #54 or #67 and I touched none of them.
3. *The 0.0% is gone.* At 500 paths on the original seed, `buy_outright` #51 reads **79.8%
   essentials / 78.6% full spend** (and 85.8% on the new "met in 95%+ of years" companion). The
   76-point cliff was the old all-or-nothing full-spend test failing a whole 50-year path on that
   one GBP 1.37, which landed identically on all 10,000 paths. #53 reads 86.0% / 81.4% on the same
   run, the same shape. These are confirmation figures at 500 paths, **not** a replacement for the
   ranked report, and nothing was written back.

**#3 - the stale warning.** Rewritten, not deleted, in `docs/SCENARIO-V2.local.md` (the 51-54 park
home row): the "cannot complete / GBP 46,412" claim is withdrawn and restated with the current
finding, the "runs short: never (bar 51)" cell corrected to "never", and the final-wealth range
refreshed from today's audit table. **Note on where I wrote it:** that file is gitignored, so it
does not exist in this worktree - there is one copy on the machine and it lives in
`C:\Dev\RetireForecast\docs\`. I edited that copy directly, because otherwise this criterion can
never be met from a worktree. It was a single targeted edit to one table cell in an untracked
private doc; no git command was run outside this worktree.

**#4 - left open, and not for a reason this card can fix.** `php artisan scenarios:audit` exits 1,
on **120 problems that are all the same one**: "run N carries no integrity stamp (it predates the
column)". Not one audit problem of any other class appears, and none is about #51. The cause is
that `2026_08_29_100000_add_hashes_to_simulation_runs_table` is still **Pending** on the app
database, and - importantly - running that migration would **not** clear it, because check 8 tests
`$run->integrity_hash === null` and every one of the 120 already-completed runs would still be
null. Clearing it needs every stored scenario re-run, which this card's "Not this card" forbids
("do not change the ranked report") and which belongs with card 0018 and with the
`ENGINE_VERSION` bump card 0025 made. The audit was equally red before this session, so nothing
here made it worse.

**Assumed.** That "THE APP SHALL charge that gap visibly" in #2 is a statement about the app's
behaviour, which is now true on every fresh projection. The **stored** 10,000-path run for #51
still shows the old 0.0% until the scenarios are re-run - the same re-run that #4 needs. HANDOVER
already flags that no pre-0025 probability is comparable with a post-0025 one, and I have added
the same warning to the private doc.

**Not settled from the repository.** Nothing. **Not checked in a browser:** no code changed, so
there is nothing new to look at, but the input note and headline tile that card 0025 added still
carry that card's own outstanding browser check.

**2026-10-05** RESULT: partial
TESTS: +0 new, all green (php artisan test: 1636 passed, 1 skipped)
TOUCHED: docs/board/todo/0161-the-app-database-is-behind-the-code-so-the-audit-cannot-pass.md
OUT-OF-SCOPE: 0161

Only #4 was open. No production code changed, so no test was written: #4 is a property of the app database, not of the code, and proves: is not named for it.

#1 to #3 still hold after today's re-work of card 0025 (d3b9ac3). I checked the one way it could undo them: `Simulator` still counts `fullSpendAlwaysMet`, not the new `ForecastResult::fullyFunded()`, so #51's GBP 1.37 unfunded purchase lump no longer zeroes its full-spend probability. The /afford verdict now asks `fullyFunded()`, so #51 there reads as not fully funded because of that lump. That is the gap charged visibly, which is what #2 asks for. I did not re-run the 2026-08-29 side-by-side projection.

#4 is NOT met. `php artisan scenarios:audit` (read-only, run from this worktree against the app Postgres DB) exits 1 on two problem classes, and none is about #51's figures or disclosure. #51's five lines are all integrity-stamp lines. (a) 120 runs 'carry no integrity stamp', as on 2026-08-29. (b) NEW since then: all 3 stored assumption sets miss inflationPersistence, inflationAssetCorrelations and economicSourcing, because they were not re-seeded after cards 0062, 0064 and 0065. Clearing both rewrites Rob's app database and moves the ranked report, which this card's 'Not this card' rules out. It also cannot be undone by deleting a file. So I raised it as card 0161 (not_for_the_loop), with the order: migrate, re-seed AssumptionSetSeeder, Re-run all, audit. #4 should pass once 0161 is done. Consider giving this card needs: 0161.

Not checked in a browser: no screen changed.

**2026-10-05** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Only #4 is open, and it is still blocked on card 0161, which is still in todo/ and is not_for_the_loop. No code changed, so I wrote no test and made no commit.

Re-checked today, read-only, from this worktree against the app Postgres DB: `php artisan scenarios:audit` exits 1 on 123 problems. They are the same two classes as the last entry: 120 runs with no integrity stamp, and the 3 assumption sets missing inflationPersistence, inflationAssetCorrelations and economicSourcing. None is about #51. `2026_08_29_100000_add_hashes_to_simulation_runs_table` is still Pending. #51 still reads 'never' runs short in the audit table.

#1 to #3 are unchanged since the last entry. To finish: Rob works card 0161 (migrate, re-seed AssumptionSetSeeder, re-run all scenarios), then a re-run of the audit settles #4. This card should carry needs: 0161 so the loop stops picking it up first.

Not checked in a browser: no screen changed.

## Comments
<!-- The card's thread, appended by ProgressBoard. Append-only: entries are added, never edited or removed. An entry beginning **Decided:** is an answer, and that is what a decision card exits on. -->

**2026-10-05** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 4 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-05 manager (m20261005150923-a98d)

**outcome: rob**

The only open item waits on card 0161, which rewrites your database, so only you can do it.

**what the session said**

Only criterion #4 is still open. The builder cannot close it.

#4 asks for `php artisan scenarios:audit` to exit 0. It exits 1 because the app database is behind the code. There are two causes:

- 120 stored runs have no integrity stamp.
- 3 assumption sets are missing new fields.

No problem in the audit is about scenario 51. Criteria #1 to #3 still hold, and no review finding disproves them.

The fix is card 0161: `docs/board/todo/0161-the-app-database-is-behind-the-code-so-the-audit-cannot-pass.md`. That card is marked not_for_the_loop. Its steps are migrate, re-seed `AssumptionSetSeeder`, re-run all scenarios, then audit. Those steps rewrite your database and change the ranked report. Only you can approve that.

What you do now:

1. Work card 0161.
2. Run `php artisan scenarios:audit` again to settle #4.
3. Add `needs: 0161` to card 0023, so the loop stops picking it up first.

WHY: The only open item waits on card 0161, which rewrites your database, so only you can do it.

OUTCOME: rob

