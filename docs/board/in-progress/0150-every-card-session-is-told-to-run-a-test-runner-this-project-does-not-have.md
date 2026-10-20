# Every card session is told to run a test runner this project does not have

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
