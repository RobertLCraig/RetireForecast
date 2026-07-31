# Public-release blockers

## Why
Four items, each flagged in code, harmless while the app is private and mandatory before any
public launch. Grouped because they share one trigger: the decision to release.

## Not this card
Deciding whether to release publicly at all. This card is the work that decision would require.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN `config('compliance.personal_use')` is false, THE APP SHALL re-apply the
      guidance-only partition, and `php artisan compliance:advice-audit` SHALL report no
      advice spots outside it.
- [ ] #2 THE APP SHALL source its stress-test dataset from an OGL or otherwise licensed source
      rather than the CC BY-NC-SA JST data.
- [ ] #3 THE APP SHALL serve a CSP whose `script-src` uses nonces rather than a broad allowance.
- [ ] #4 THE APP SHALL pass the a11y pass at a public bar (WCAG 2.2 AA, mobile included).
<!-- AC:END -->

## Tasks
- [ ] Flip `compliance.personal_use` false and confirm the partition re-applies
- [ ] Swap the stress-test dataset off the JST source
- [ ] Tighten CSP `script-src` to nonces
- [ ] Complete the a11y pass to a public bar
