# A card worktree can start with the whole engine source deleted from its working tree

## Why
A session picked this project up in the scheduler's worktree at
`C:\Users\r\AppData\Local\ProgressBoard\worktrees\RetireForecast` on 2026-09-20 and found
`git status` reporting **289 tracked files deleted**, the entire contents of
`packages/finance-engine` — every DTO, every calculator, the mortality table and the package's own
`composer.json`. The directory was still there and was empty. Nothing in the branch's history
deleted them; they were deleted from the working tree only, so the index and `master` were both
untouched.

The cost is the worst kind this board can carry. A session that does not read its own `git status`
before committing — and `git commit -a`, `git add -A` and most "commit everything" habits do not —
commits 289 deletions on a card about something else. The scheduler then merges that branch back
into `master` and the finance engine is gone from the repository. Every one of those deletions looks
deliberate in the diff, and the suite would not catch it in the worktree that made it, because the
engine tests fail to *load* rather than fail to *pass*, which reads as an environment fault and gets
worked around.

It is also a silent start. Nothing announced it: the session was handed a card, and the first sign
was an unexplained fatal error when PHPUnit tried to autoload an engine class.

How it arose is not settled from inside this repository, and the honest statement of what was
observed is this. On Windows, Composer wires a path package by making `vendor/retireforecast/
finance-engine` a **junction** pointing at `packages/finance-engine`. At the moment the fault was
found, that junction did not exist in this worktree, and `vendor/retireforecast/` was an empty
directory **in `C:\Dev\RetireForecast` too**. A recursive delete that does not stop at a reparse
point deletes through a junction into its target, which empties `packages/finance-engine` and leaves
the empty directory behind — exactly the state found. `vendor/` is copied into each worktree and
refreshed for every card, so a refresh that deletes the previous `vendor/` recursively is the
candidate, but that tooling lives in ProgressBoard and was not read.

Recovery, for anyone who hits this before the card lands, is two commands and loses nothing:

    git checkout -- packages/finance-engine          # the files are in the index, not gone
    composer update retireforecast/finance-engine    # re-creates the vendor link

## Links

**Relates to**
- `0083` - hit at the start of a verification run of that card; that card is formatting-only and has
  nothing to do with the cause.

## Not this card
Changing how the engine is packaged, or moving it out of a path repository. The package layout is
right and is not what failed. Nor is this about making the suite louder when the engine is missing,
which is worth doing and is a different fault.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a fresh card worktree is created by the scheduler, THE APP SHALL leave `packages/finance-engine` fully populated, so `git status` in that worktree reports no deleted files. proves: manual - the check is creating a worktree and reading `git status`; no test inside the repository can observe how its own worktree was built.
- [ ] WHEN `vendor/` is refreshed or removed in any checkout of this project, THE APP SHALL leave `packages/finance-engine` intact, since the vendor entry is a junction into it and not a copy. proves: manual - same reason; the deletion happens outside the process the suite runs in.
<!-- AC:END -->

## Tasks
- [ ] Reproduce: create a card worktree, and check `git status` and `ls packages/finance-engine`
      before doing anything else.
- [ ] Find which step empties it. Read the worktree setup in `C:\Dev\ProgressBoard` — whatever
      copies and refreshes `vendor/` — and check whether it deletes the old `vendor/` recursively
      without excluding reparse points.
- [ ] Fix it where it happens. On Windows a junction must be removed with `Remove-Item` on the link
      itself (or `rmdir` without `/s`), never by a recursive delete of its parent, and a copy must
      exclude junctions (`robocopy /XJ`) rather than follow them.
- [ ] Confirm the fix by creating a worktree twice in a row and reading `git status` both times.
      Once is not enough: the first worktree of a session had the junction, the second did not.

## Plan
The fault is in ProgressBoard's worktree tooling, not in this repository, so the work is stood up in
`C:\Dev\ProgressBoard` on its own branch while this card stays on RetireForecast's board because
this is the repository that loses files. Nothing here needs editing unless the fix turns out to
belong in this project's `composer.json`, which is not expected.

"It worked" is a freshly-made worktree in which `git status --porcelain` prints nothing but
untracked files, `ls packages/finance-engine` lists `src`, `tests`, `resources` and `composer.json`,
and `php -d memory_limit=1G vendor/bin/phpunit --testsuite=Engine` runs rather than dying on an
autoload failure.

Be careful reproducing this: the reproduction deletes real files. Work in a scratch worktree, and
check `C:\Dev\RetireForecast\packages\finance-engine` is still populated after every attempt. Rob's
copy survived this occurrence, and there is no reason recorded to think it always would.

## Comments


**2026-09-21** The loop moved this card from todo/ to human-review/ WITHOUT trying it. All 2 of its open acceptance criteria say proves: manual, so there is nothing left an unattended session could close and starting one would change nothing. Each open criterion names what to look at and what a pass is: tick what passes and move the card on, or say what failed and move it back to todo/.
