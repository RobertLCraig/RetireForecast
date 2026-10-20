# Every card session is told to run a test runner this project does not have

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
The scheduler's standing instruction to an unattended card session says, as its step 3:

> Run the suite from PowerShell: `.\vendor\bin\pest.bat`, and `.\vendor\bin\pint.bat` for style.

There is no `vendor/bin/pest.bat` in this project and there never has been. The runner is PHPUnit:
`vendor/bin/phpunit`, wrapped by `php artisan test`, which is what `CLAUDE.md` documents and what
the whole suite has always been run with. Pint is right; Pest is not.

The cost is that the one instruction a session is given about proving its work green is an
instruction it cannot follow, and each session has to work that out for itself and substitute
something. Three separate runs of card `0083` each hit this, each resolved it the same way, and each
recorded it only in prose in that card's comment thread — which is how a fault survives being found
three times. The substitution is currently compounded by card `0147`: `php artisan test` dies at the
128M Herd default, so a session that reaches for the documented fallback does not get a green either
and has to find `php -d memory_limit=1G vendor/bin/phpunit` on its own.

The expensive version of this is not the lost minutes. It is a session concluding that a missing
runner is a broken environment, and reporting the card blocked, or "running the suite" via something
that does not run the whole suite and calling the result green.

What cannot be settled from inside this repository is which side should move. Either the scheduler's
instruction is corrected to name this project's actual runner, or the project adopts Pest and the
instruction becomes true. That is a call about the scheduler's template, which lives in
`C:\Dev\ProgressBoard`, and it was not read.

## Links

**Relates to**
- `0147` - the documented fallback, `php artisan test`, also does not finish; together these leave a
  session with no working command it was told about.
- `0083` - where this was found, three times over, and recorded only in prose.
- `0149` - same shape: a fault in the scheduler's worktree tooling, surfacing inside this repository.

## Not this card
Adopting Pest, or migrating any test to it. That is one of the two possible answers and it is a
decision, not a fix to be made in passing. This card is only to get the instruction and the
repository saying the same thing.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN an unattended card session is told how to run the suite, THE APP SHALL name a command
      that exists in this project and runs the whole suite. proves: manual - the instruction lives in
      the scheduler's template outside this repository, so no test inside it can read what a session
      was told.
- [ ] THE APP SHALL record the chosen runner in one place only, so `CLAUDE.md` and the scheduler's
      instruction cannot drift apart again. proves: none - which place, and whether the answer is to
      fix the instruction or to adopt Pest, is a call for Rob.
<!-- AC:END -->

## Tasks
- [ ] Confirm the instruction text: find the card-session template in `C:\Dev\ProgressBoard` and read
      what it actually tells a session to run.
- [ ] Put the question to Rob: correct the instruction, or adopt Pest.
- [ ] Apply whichever answer, and check it against `CLAUDE.md`'s test commands.

## Comments

**2026-10-20** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card is a decision, not a build. Its own Tasks say to put the question to Rob, criterion 2 says the answer is a call for Rob, and the only fix lives in C:\Dev\ProgressBoard, which an unattended RetireForecast card session must not edit. So no criterion is met and nothing was committed.

Task 1 is done (read-only), so Rob has the facts:
- The instruction comes from C:\Dev\ProgressBoard\bin\prompts\build.md line 51, a hard-coded `.\vendor\bin\pest.bat`. The raise prompt has the same hard-code at app\Support\Loop\BuildPrompt.php line 120.
- ProgressBoard already derives the right runner per project: app\Support\Loop\Suite.php `Suite::command()` tries pest.bat, then phpunit.bat. For RetireForecast it returns `vendor\bin\phpunit.bat`, because this tree's vendor\bin holds phpunit.bat and pint.bat and no pest.bat (checked).
- So the scheduler's own suite gate and the prompt it gives a session already disagree.

The two options for Rob:
1. Fix the prompt (recommended): make build.md and BuildPrompt::raise() name the project's `Suite::command()` result instead of a literal pest.bat. That is one place, inside ProgressBoard, and it is what the gate already runs, so the prompt cannot drift from what is checked. CLAUDE.md here can keep `php artisan test`. Card 0147 still applies: the PHPUnit run may need `php -d memory_limit=1G` until it lands.
2. Adopt Pest in RetireForecast: makes the literal true here, but it is a migration this card rules out, and every other non-Pest project would still be told the wrong command.

Once Rob picks, the work belongs on a ProgressBoard card, not this board.

**2026-10-20** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Still a decision for Rob, with no answer on the card yet. Re-checked today: C:\Dev\ProgressBoard\bin\prompts\build.md line 51 still hard-codes .\vendor\bin\pest.bat, and this tree's vendor\bin still has phpunit.bat and pint.bat and no pest.bat. The fix lives in ProgressBoard, which an unattended RetireForecast session must not edit. The options and the recommendation (make the prompt name Suite::command(), which the gate already runs) are in the previous comment and are unchanged. Once Rob picks, the work goes on a ProgressBoard card.

**2026-10-20** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.
