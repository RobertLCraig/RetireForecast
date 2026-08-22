# Public-release blockers

## Why
Four items, each flagged in code, harmless while the app is private and mandatory before any
public launch. Grouped because they share one trigger: the decision to release.

## Not this card
Deciding whether to release publicly at all. This card is the work that decision would require.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN `config('compliance.personal_use')` is false, THE APP SHALL re-apply the
      guidance-only partition, and `php artisan compliance:advice-audit` SHALL report no
      advice spots outside it.
- [ ] #2 THE APP SHALL source its stress-test dataset from an OGL or otherwise licensed source
      rather than the CC BY-NC-SA JST data.
- [x] #3 THE APP SHALL serve a CSP whose `script-src` uses nonces rather than a broad allowance.
- [ ] #4 THE APP SHALL pass the a11y pass at a public bar (WCAG 2.2 AA, mobile included).
<!-- AC:END -->

## Tasks
- [x] Flip `compliance.personal_use` false and confirm the partition re-applies
- [ ] Swap the stress-test dataset off the JST source
- [x] Tighten CSP `script-src` to nonces
- [ ] Complete the a11y pass to a public bar

## Direction

**2026-08-22** Two of the four are done, one is a decision only Rob can make, and one is done
as far as a machine can take it.

**#1 guidance-only partition (met).** The audit listed exactly one advice spot, and it was a
false positive: a docblock in `AffordabilityAssessment::verdict()` that quoted the banned
phrase while explaining the rule. Reworded, so `php artisan compliance:advice-audit --strict`
now exits 0 with an empty neutral zone. Then rehearsed the public posture properly by running
the whole suite with `COMPLIANCE_PERSONAL_USE=false`: one test failed, because
`CombinationComparisonGateTest` leaned on advice mode being the suite default instead of
pinning it the way `InterpretationTest` does. Pinned. **The suite is now green in BOTH
postures**, which is the thing that was actually missing: before this, nothing proved the
release posture worked. Deliberately did NOT add a standing test asserting the neutral zone
stays clean: DECISIONS 2026-07-04 chose to let advice copy live there during personal use,
and such a test would go red on Rob's next advice edit. The standing gate stays the command.

**#2 stress-test dataset (open, and it is a decision, not a build).** Re-downloaded BoE "A
Millennium of Macroeconomic Data" v3.1 and searched the whole workbook rather than one sheet:
the strings "total return", "equity return" and "dividend yield" appear nowhere in it. Sheet
A31 has share PRICE indices and bond/bill yields only. So the 2026-07-01 finding holds and
the OGL route still cannot produce an accurate equity total return without inventing the
dividend leg, which is roughly half the long-run UK equity return, i.e. the single number
the sequence-risk panel exists to test. Three options, none of which I should pick for him:
(a) license DMS or Barclays Equity Gilt Study (money, best data); (b) ship BoE prices plus a
documented dividend-yield assumption (free, shippable, measurably less accurate); (c) drop
the shipped historical numbers from a public build. Given the standing "accuracy over less
work" instruction I would lean (a) if a public release is ever real, and (c) over (b), since
(b) buys a stress test we would then have to caveat. The re-verification is written into
docs/research/RESEARCH-stress-test-and-official-sources.md so nobody downloads 27MB again.

**#3 CSP nonces (met).** `SecurityHeaders` now mints a nonce per request before the view
renders and hands it to Laravel's Vite helper; Livewire reads the nonce back off that same
helper, so the bundle tag, the Livewire runtime and its inline init script all carry it.
`config/security.php` holds the literal source expression `'nonce'` as the placeholder the
middleware substitutes, so the policy still has one home. `script-src` is now
`'self' 'nonce-<random>' 'unsafe-eval'`, with `'unsafe-inline'` gone (it had to be removed,
not just left redundant, and the test asserts its absence). **`'unsafe-eval'` stays and is the
honest residual:** Livewire 4 bundles Alpine, which evaluates expressions through the Function
constructor, and Livewire does not expose Alpine's CSP build because its own `wire:` directives
compile to Alpine expressions. Removing it is a front-end rewrite, not a config change. If a
reviewer reads "rather than a broad allowance" as covering `'unsafe-eval'` too, then this
criterion is only half met and should be re-opened. Verified beyond the suite: served the
worktree app locally and confirmed the real Vite tag carries the nonce on every public page,
and drove a headless Chrome through a signed-in results page with zero console errors.

**#4 a11y at a public bar (open, but materially advanced).** Raised the automated bar: Pa11y's
`standard` option only reaches WCAG 2.1, so `.pa11yci.json` now sets no standard and lets axe
run its full default rule set, which is the superset that includes the one machine-checkable
2.2 AA rule (2.5.8 target-size), and every public URL now runs at a phone viewport as well.
Added `npm run a11y:auth` (scripts/a11y-authenticated.mjs) to sweep the signed-in pages, which
had never been machine-checked at all. It found **six genuine failures, all now fixed**: the
green "What can I afford?" button at 3.21:1, `text-gray-500` on the blue-50 and red-50 tints
(4.44 / 4.42:1), the results page's `text-gray-400` "On this page" label at 2.48:1, the
assistant edge tab having no accessible name whatsoever on a phone, and the builder `<h1>`
rendering **empty** on a new forecast. That last one was not a styling bug: it was the glued
Blade directive gotcha (`forecast@else`), and `BladeDirectivesCompileTest` had a hole because
its leak pattern only looked for directives that carry an argument list or an `end` prefix.
Widened to catch a bare `@else`, with a non-vacuity test. Separately, `collapse.js` was putting
`role="button"` on each results `<h2>`, which removed all 20 sections from the screen-reader
heading list; it now nests a real `<button>` inside the heading (verified in a real browser:
click and Enter both toggle, no console errors). All 11 public URLs and all 12 signed-in
page/viewport pairs now pass with zero violations.

**Why #4 stays open.** Only one WCAG 2.2 AA criterion is automatable. The other five (2.4.11
focus not obscured, 2.5.7 dragging movements, 3.2.6 consistent help, 3.3.7 redundant entry,
3.3.8 accessible authentication) and the ApexCharts canvases need a person at a browser, and
**this is a worktree: the Herd site serves C:\Dev\RetireForecast, not this tree, so nothing
here has had a browser sign-off.** That pass belongs with card 0001. `/account/security` is
also still unswept (it needs a password-confirmation step). Left PRD.md and PLAN.md saying
WCAG 2.1 AA on purpose: the automated bar is 2.2 now, but the project bar should only be
restated once the manual pass lands.

Two things I could not settle from the repository. The runner instruction named
`.\vendor\bin\pest.bat`, which does not exist here; this project is PHPUnit, so I ran
`php artisan test` and `.\vendor\bin\pint.bat --dirty --test`. And plain `vendor/bin/pint`
wants to reformat `app/Forecast/QuickWhatIf.php` and `app/Forecast/SimulationRunner.php`,
which is pre-existing drift unrelated to this card; I reverted those two so the diff stays
the card's, but somebody should run a bare `pint` on master.
