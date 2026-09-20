# The documented test command runs out of memory before it reaches the end of the suite

## Why
`php artisan test` is the command `CLAUDE.md` tells every session to run, and it does not finish.
It dies partway through the Feature suite:

    Fatal error: Allowed memory size of 134217728 bytes exhausted
      in vendor/livewire/livewire/src/Features/SupportMorphAwareBladeCompilation/...php:472
    Fatal error: Premature end of PHP process when running
      Tests\Feature\DecisionSupport\ThresholdInvalidationTest::...

134217728 bytes is 128M, which is the `memory_limit` in the Herd CLI's php.ini
(`C:\Users\r\.config\herd\bin\php84\php.ini`) — the one PHP install this machine uses, so this is
not a worktree artefact. The same suite passes end to end when the limit is raised by hand:
`php -d memory_limit=1G vendor/bin/phpunit` reports 1605 tests, 1604 passed, 1 skipped.

The cost is that the project's own green/red signal fails in the way that reads worst. It ends in a
PHP fatal error rather than a test failure, so a session that ran the documented command sees red
and cannot tell whether its own change broke anything. An unattended session can lose a whole run
to it, and the obvious wrong conclusion — "my change broke the suite" — is the expensive one.

Nobody chose 128M. It is PHP's stock default, inherited from Herd, and the suite grew past it. The
web side of this project already runs at 1512M (DECISIONS, the export batching work), but nothing
was ever set for the test process.

## Links

**Relates to**
- `0083` - hit while verifying that card's "the suite stays green" criterion; that card changed
  formatting only and has nothing to do with the cause.

## Not this card
Making the suite use less memory, splitting it, or changing which tests run. This is only about the
documented command completing. Whether one test is a memory hog is a separate question and nothing
here suggests one is.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN `php artisan test` is run from the project root with no extra flags, THE APP SHALL run every test to completion without a PHP fatal error. proves: manual - the check is the command itself; a suite cannot assert on the memory limit of the process running it.
- [ ] THE APP SHALL report the same counts as the hand-raised run does today (1605 tests, 1604 passed, 1 skipped, as at 2026-09-20), so the fix is shown to have added headroom and not skipped work. proves: manual - same reason.
<!-- AC:END -->

## Tasks
- [ ] Reproduce: `php artisan test` from the project root, and note where it dies.
- [ ] Set the limit where the test process reads it, not machine-wide. PHPUnit supports
      `<ini name="memory_limit" value="1G"/>` inside the `<php>` block already in `phpunit.xml`,
      which keeps the fix in the repository rather than in one developer's php.ini.
- [ ] Confirm `php artisan test` now completes, and that the counts match the figures above.
- [ ] Leave `CLAUDE.md` alone if `phpunit.xml` carries the fix — the documented command is then
      already correct. Only if the fix needs a flag does the instruction change.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. PHP is Laravel Herd's and is on PATH inside PowerShell
only, not Git Bash. `phpunit.xml` is at the project root; its `<php>` block is lines 24-45 and
already carries the suite's env settings, so this is one element added inside it.

Prove the setting actually took effect rather than trusting a green run: a passing suite and a suite
that never approached the ceiling look identical from outside. Setting the value deliberately low
first, watching the same fatal error appear, then raising it is the cheapest way to see it bite.

## Comments
