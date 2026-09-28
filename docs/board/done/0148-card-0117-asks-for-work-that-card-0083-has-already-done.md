# Card 0117 asks for work that card 0083 has already done, so a session will pick it up and find nothing

## What I need from you
Move `docs/board/todo/0117-two-files-fail-the-house-style-check.md` to `discarded/`, and add a dated line to it saying "superseded by 0083". Then tick both criteria here and move this card on.

**Pass:** from `C:\Dev\RetireForecast` in PowerShell, `vendor\bin\pint.bat --test` prints `{"tool":"pint","result":"passed"}`. It did on 2026-09-28. If it passes, 0117 has nothing left in it, and moving 0117 is the whole job.
**Fail:** pint names a file. Then 0117 still has real work: leave 0117 in `todo/`, add a dated line to it naming the file, and move this card to `discarded/`.

## Why
`0117-two-files-fail-the-house-style-check.md` sits in `todo/` with both its criteria open. Its
criteria are that `vendor/bin/pint --test` exits zero over the whole repository, and that the engine
stays framework-free after the fix. Both already hold: `vendor/bin/pint --test` reports `passed` on
a clean tree today, and `EngineIsolationTest` is green.

The work was done by card `0083`, which was written later, names the same drift, and names two of
the same three files. Neither card knows about the other.

The cost lands on the next unattended session that picks `0117` up. It will run the command, find it
already green, and have to decide on its own whether to tick criteria it did not meet or to leave a
card open that has nothing left in it. Neither choice is good: a tick it did not earn is exactly the
self-report the board's `proves:` rule exists to stop, and an honest "nothing to do" leaves the card
sitting in `todo/` for the next session to spend a run on again.

It came about the ordinary way. Two sessions hit the same repo-wide Pint drift a few weeks apart —
`0117` while working card `0048`, `0083` while working card `0027` — and each wrote a card for what
it had found without searching the lane for one already there.

## Links

**Relates to**
- `0083` - did the work; its comment thread records that two of the three named files had already
  been brought up to the ruleset by other cards before it ran.
- `0117` - the card this is about. Do not edit it without reading its `## Comments` first; it is
  empty today, which is itself evidence nobody has worked it.
- `0084` - the same class of waste, an unattended session spent on a card it cannot finish.

## Not this card
Deciding the wider question of whether a card should search the lane for a duplicate before being
written, or adding any check that would enforce it. This is one duplicate, closed one way or the
other.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a session next reads `todo/`, THE APP SHALL NOT offer `0117` as open work, because it has been settled — either moved to `discarded/` as superseded by `0083` with that one line of why, or carried forward if reading it turns up something `0083` genuinely left undone. proves: manual - a lane move is the scheduler's, and no test in this repository reads card lanes.
- [ ] THE APP SHALL record on `0117` which of the two it was, dated, so the next reader can tell a settled duplicate from a card nobody looked at. proves: manual - same reason.
<!-- AC:END -->

## Tasks
- [ ] Read `0117` against `0083`'s comment thread and confirm nothing in `0117` is left open. The
      one thing to check rather than assume is `0117`'s caution about
      `fully_qualified_strict_types` hoisting a docblock reference into a real `use`: `0083` records
      that it did fire, on an engine-to-engine class, and that the isolation guard still passes.
- [ ] Confirm the state for yourself: `vendor/bin/pint --test` from the project root, and
      `php artisan test --filter EngineIsolation`.
- [ ] Append the dated entry to `0117` saying it was superseded by `0083`, and by what evidence.
- [ ] Leave the lane move to whoever owns it on this board; the entry is what the mover reads.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. PHP is Laravel Herd's and is on PATH inside PowerShell
only, not Git Bash. The two cards are `docs/board/todo/0117-two-files-fail-the-house-style-check.md`
and `docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md` (`0083` may have moved
lane by the time this is picked up; `git log --follow` finds it).

`0117` names `app/Forecast/LumpSumTaxShock.php` and
`packages/finance-engine/src/Pension/TaxFreeCashCalculator.php`. `0083` names those two plus
`app/Forecast/QuickWhatIf.php`, so `0117` is the narrower of the two and nothing in it is outside
what `0083` covered.

## Comments


**2026-09-20** The loop moved this card from todo/ to human-review/ WITHOUT trying it. All 2 of its open acceptance criteria say proves: manual, so there is nothing left an unattended session could close and starting one would change nothing. Each open criterion names what to look at and what a pass is: tick what passes and move the card on, or say what failed and move it back to todo/.

**2026-09-28** Manager pass: checked, and the duplicate is real. `vendor\bin\pint.bat --test` passes on `master` today, and 0117's two files are a subset of 0083's three (0083 is now in `ai-review/`). What is left is a lane move on another card, which no loop session makes, so this stays in `human-review/` with the move as the ask.

**2026-09-28** Done: 0117 moved to `discarded/` in ca6fdd7. Nothing else was asked.
