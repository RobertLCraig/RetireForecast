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
- [ ] #2 THE APP SHALL keep the private figures in the gitignored captures, with tracked docs referring to them by card number.
<!-- AC:END -->

## Tasks
- [ ] Add `tests/Feature/Docs/NoPrivateDataTest.php`, shaped like `HandoverHygieneTest`
- [ ] Scrub the eleven tracked files; move figures into `SCENARIO-V2.local.md`
- [ ] Encrypt or delete the plaintext scenario backups
- [ ] Add data hygiene, and the history question, to card 0012

