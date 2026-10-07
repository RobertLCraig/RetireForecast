---
needs: 0012
---
# The couple's private details are in tracked docs again

## Why
From the expert panel, 2026-08-19 (engineer finding F14). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`.gitignore` protects `/docs/*.local.md` and `/docs/*.local.json`, which is where the deliberate
private captures live. The private data has leaked past that boundary anyway. A `git grep` for
lender and broker names and the target town hits **eleven tracked files**: HANDOVER.md,
DECISIONS.md, DATA-MODEL.md, four `PLAN-*.md`, the session-log archive, and three board cards.

HANDOVER.md's blockers section additionally carries a specific estate value, two success
probabilities and a personal working-age decision for a real, identifiable couple.

This has happened before and needed a history purge. There is no guard, and the public-release
blockers card does not list data hygiene among them.

Two related exposures: `docs/scenario-backup-*.local.json` are **plaintext** exports of data the
database stores encrypted, and a debug log level with a file channel will write scenario payloads
into `storage/logs/`.

## Links

**Blocked by**
- `0012` - the public-release blockers card owns the question of whether git history gets purged,
  and that decision sets how far this scrub has to go.
## Not this card
The history purge itself. Do the guard and the scrub first, then decide on history under 0012.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN any tracked file under `docs/` or `tests/` contains a name or figure on the private denylist, THE APP SHALL fail the test suite.
- [x] #2 THE APP SHALL keep the private figures in the gitignored captures, with tracked docs referring to them by card number.
<!-- AC:END -->

## Tasks
- [ ] Add `tests/Feature/Docs/NoPrivateDataTest.php`, shaped like `HandoverHygieneTest`
- [ ] Scrub the eleven tracked files; move figures into `SCENARIO-V2.local.md`
- [ ] Encrypt or delete the plaintext scenario backups
- [ ] Add data hygiene, and the history question, to card 0012

## Comments

**2026-10-06** RESULT: partial
TESTS: +2 new, all green
TOUCHED: tests/Feature/Docs/NoPrivateDataTest.php
docs/DATA-MODEL.md
docs/DECISIONS.md
docs/HANDOVER-ARCHIVE.md
docs/build/PLAN-park-home.md
docs/build/SESSION-LOG-ARCHIVE.md
docs/board/todo/0174-two-decision-cards-still-name-the-couples-lender-and-town.md
docs/board/todo/0175-an-engine-test-names-the-couples-lender.md
docs/board/todo/0176-park-home-site-names-survive-only-in-git-history.md
OUT-OF-SCOPE: 0174, 0175, 0176

Built `NoPrivateDataTest`. It reads `git ls-files -- docs tests` and fails on any denied term in a tracked file, with path, line and term. The denylist is sha256 hashes of lower-case terms of up to three words, matched as word n-grams, so a name wrapped over a line break is caught. The list holds the lender, broker and adviser names, the towns and park sites, the address, postcode and account number, both dates of birth, the salary and the estate figure the review named. A hashed list stops a reader but not a dictionary attack on a town name; the docblock says so, and whether that is enough before a public release is card 0012's call. I watched the guard fail on 48 hits in seven files, and watched the line-wrap canary test fail with the matcher cut to one-word terms.

Scrubbed five docs: the names became neutral words ("the real lender ESIS", "the dearer end") with pointers to the gitignored captures, which already hold the lender, broker and towns. The park-home site table now says Site A to G. Two money figures I first listed (the old loan balance and an archived ranking total) I took off the list: they identify nobody, and scrubbing every couple figure in every format is wider than this card. So one test still uses the loan balance as its example.

#1 not met. Cards 0022 and 0023 still carry denied names, and this session may not edit cards. The test exempts them by card-number prefix in `PENDING_SCRUB`, and fails if an exempt card goes clean, so the exemption cannot outlive the leak. Card 0174 scrubs both and deletes the exemption.

Not done, and they need a person: the plaintext `docs/scenario-backup-*.local.json` files sit only in Rob's checkout, and deleting or encrypting them is his call. Adding data hygiene and the history question to card 0012 needs a card edit this session may not make. The park-home site names were not in any capture and are now only in git history (card 0176). The engine test docblock outside `tests/` names the lender (card 0175). Nothing on screen changed, so no browser check is owed. The suite ran from this worktree with `php artisan test`, because there is no `vendor/bin/pest.bat`.

**2026-10-07** RESULT: partial
TESTS: +0 new, all green
TOUCHED: docs/HANDOVER.md
OUT-OF-SCOPE: none

Second take. The guard from the first take (NoPrivateDataTest) is in place and green. Its stale check still passes, which proves cards 0022 and 0023 still carry denied terms. #1 is still not met for the same reason as before: those two cards are exempt in PENDING_SCRUB, and only card 0174 (already in todo) may edit them. This session may not edit cards, so nothing new could be built toward #1. No new test: the guard and its failing-first proof already exist from the first take.

The only change: HANDOVER.md said data hygiene was breached in eleven tracked files. That was stale. It now says the guard exists, and names the two exempt cards and card 0174. The HANDOVER Blockers section carries no estate value, probability or working-age decision now.

Still needs a person, unchanged: the plaintext docs/scenario-backup-*.local.json files are only in Rob's checkout (delete or encrypt is his call), and adding data hygiene plus the history question to card 0012 is a card edit. Full suite ran with php artisan test from this worktree (there is no vendor/bin/pest.bat here); pint passed. Nothing on screen changed, so no browser check is owed.

**2026-10-07** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: none
OUT-OF-SCOPE: none

Third take. Nothing changed since the second take, so there was nothing to build. NoPrivateDataTest still passes, and its stale check still passes, which proves cards 0022 and 0023 (both in human-review) still carry denied terms. Card 0174, which scrubs them and deletes the PENDING_SCRUB exemption, is still in todo. #1 cannot be met until 0174 runs, because an unattended session may not edit cards. Re-running this card before 0174 is done will give the same result; the card's `needs:` should probably name 0174 as well as 0012.

Still needs a person, unchanged: delete or encrypt the plaintext docs/scenario-backup-*.local.json files in Rob's checkout, and add data hygiene plus the history question to card 0012. Full suite ran with php artisan test from this worktree (no vendor/bin/pest.bat here). No commit: the tree was clean and nothing changed.
