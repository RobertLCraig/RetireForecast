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

**2026-08-22 (second pass)** Picked up #4 where the first pass left it: the automated bar was
already clean, so what was left was the coverage gap it named and the five criteria it called
"manual only". Both moved, and one of the five turned out to be a real failure.

**The last unswept page is swept.** `/account/security` is now in `npm run a11y:auth`. Asking
for it bounces through Fortify's password-confirmation screen, so the script scans that on the
way past as well (nothing else reaches it) and then confirms and carries on. Both pass at both
viewports, so **all 11 public URLs and all 15 signed-in page/viewport scans are at zero
violations**. Also `aria-hidden` on the 2FA QR code: Fortify emits a bare unlabelled `<svg>`,
and the setup key printed beside it is the same secret as text under the same condition, so
there is now one accessible copy of it rather than a nameless graphic.

**#4's five "manual only" criteria are no longer a blank.** Worked through one at a time and
written into the table at the top of docs/spec/A11Y.md so nobody re-derives them. 2.5.7, 3.2.6,
3.3.7 and 3.3.8 are **met from the code as it stands**, each with its evidence (native range
sliders only, so the criterion's user-agent exception applies; the help links live in the shared
layout footer, same place every page; the builder wizard is one Livewire component so nothing is
re-entered; no CAPTCHA anywhere and every credential field carries the `autocomplete` a password
manager needs).

**2.4.11 Focus Not Obscured was failing, and is now fixed.** The assistant is a `position: fixed`
panel — a 24rem column on desktop, the whole screen on a phone — but the page behind it stayed in
the tab order, so a keyboard user tabbed into controls entirely hidden underneath it. Measured
rather than argued: wrote `npm run a11y:focus`, which tabs the results page with the panel open
and asks the document what actually paints over each focus stop. It found three (one desktop, two
mobile). Fixed with `resources/js/assistant-inert.js`, which makes everything outside the panel
`inert` while it is open and returns focus to the edge tab when it closes. The same command is the
standing check and asserts the close path too, because a stuck `inert` would freeze the page more
thoroughly than the bug it fixes. Green both ways at both viewports.

**Why #4 still stays open.** Everything above ran in headless Chrome against this worktree served
on a throwaway SQLite database, so it is a real check of this code but it is **not** the human
pass. What still needs a person: the ApexCharts canvases, 400% reflow, a screen-reader walkthrough,
and the judgements no tool makes (link text in context, meaningful sequence, meaning carried by
colour inside a chart). That belongs with card 0001. One coverage gap also remains and is written
into A11Y.md: the **two-factor enrolment state** of `/account/security` renders only after a click
that mutates the user, so no sweep has seen the QR / recovery-code panel.

**#2 is unchanged and still Rob's call.** Nothing in the repository has moved on it since the
first pass; the three options and the recommendation above stand.

One thing I could not settle from the repository: whether the assistant would even ship in a
public build (`ASSISTANT_ENABLED` needs a local Ollama). I fixed 2.4.11 for it regardless, because
it is on in this environment and the fix is one attribute, but if the assistant is out of a public
build then 2.4.11 was never a public-bar blocker.

**2026-08-22 (third pass)** Went to close the one coverage gap the last pass left open, and found
first that the sweep which reported that gap could not be trusted.

**The signed-in sweep was reporting green for pages it never loaded.** Confirming the password
clicked a bare `button[type=submit]`, and on that page the signed-in layout's **Log out** button is
the first submit in the DOM. So the sweep signed itself out at that point and carried on: the
desktop `/account/security` scan was really the landing page, and every one of the seven mobile
scans was really the login page. Both of the previous passes' counts were about six scans short of
what they claimed. Fixed by scoping the click to the form, but the click was the symptom. The
defect is that a walked session can be lost and nothing noticed, so `scan()` now compares
`location.pathname` with the path it is labelling and fails the run on a mismatch. Proved
non-vacuous rather than asserted: run with a bad password it prints 14 FAILs where it used to print
14 oks. **With that fixed, every page was re-scanned for real and all of them still pass**, so this
turned up no hidden violation, only hidden absence of proof.

**The last coverage gap is closed.** `/account/security` is now swept in all three of its states,
not just the one it loads in. The enrolment panel (QR + setup key) and the enabled panel (recovery
codes) only render after clicks that mutate the user, which is why no script had ever seen them, so
the sweep now drives the whole enrolment: click Turn on, scan, read the setup key off the page,
compute the authenticator code itself, confirm, scan, then turn 2FA back off so the user is left as
found. That last step is what makes it re-runnable. The TOTP is fifteen lines of node's own
`crypto` rather than a new dependency, since this is its only caller. Both new states pass at both
viewports, first time, with no fixes needed. **19 signed-in page/viewport scans (up from a claimed
15, a real 9) and 11 public URLs at zero violations, plus `npm run a11y:focus` green at both
viewports**, all with `ASSISTANT_ENABLED=true` so the assistant markup was in scope throughout.

One incidental thing worth knowing for anyone extending these scripts: puppeteer typing into a
field immediately after `goto` can silently go nowhere, which is how the empty confirm-password
form sat there until the navigation timed out rather than saying what was wrong. The script now
fills through a helper that reads the value back and retries once. It is a harness race, not an app
bug: the key events all arrive at the field and nothing prevents them.

**Why #4 still stays open.** Unchanged in kind from the last pass, and I do not think any further
machine work moves it. Every page state a script can reach is swept; what is left needs a person at
a browser (the ApexCharts canvases, 400% reflow, a screen-reader walkthrough, link text in context,
meaning carried by colour inside a chart), and **this is a worktree, so the Herd site serves
C:\Dev\RetireForecast and not this code**. Everything above ran against a locally served copy of
this tree on a throwaway SQLite database, which is a real check of this code but is not the human
pass. That belongs with card 0001.

**#2 is unchanged and still Rob's call.** Nothing in the repository has moved on it. The three
options and the recommendation from the first pass stand; I have deliberately not picked one.

Two things I could not settle from the repository, both carried over. `.\vendor\bin\pest.bat` still
does not exist (this project is PHPUnit), so the suite was `php artisan test`. And a bare
`vendor/bin/pint` still wants to reformat `app/Forecast/QuickWhatIf.php` and
`app/Forecast/SimulationRunner.php` — pre-existing drift in files this card never touched, so I
left them alone again rather than widen the diff. `pint --dirty` is clean. Somebody should run a
bare `pint` on master.
