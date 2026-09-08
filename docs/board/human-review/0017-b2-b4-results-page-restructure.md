# B2 to B4: results-page restructure

## Why
Follows B1 in the build order. The results page carries everything at one level, so the reader
has no way to skim it.

## Links

**Relates to**
- `0010` - B1, the verdict-first landing, is that card, and this restructure sits on top of the
  landing it builds.

## Not this card
B1 (card 0010), A3 fat tails, A4 State-Pension uprating.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL group the results page into tabs.
- [x] #2 THE APP SHALL place detail tables inside `<details>` so the page skims.
- [x] #3 THE APP SHALL demote the banners from their current prominence.
- [x] #4 THE APP SHALL keep every figure reachable without JavaScript, per the progressive
      enhancement rule (text plus an accessible table plus CSV).
<!-- AC:END -->

## Tasks
- [x] B2: tabs
- [x] B3: tables into `<details>`
- [x] B4: banners demoted

## Direction
**2026-08-22** Built B2 to B4 on the results page, presentation only; no engine, presenter or
figure changed.

**B2 tabs.** Four groups, the ones PLAN-output-inflation-and-charts.md §B2 names: *The verdict /
Money over time / Where the money goes / The fine print*. They are **plain links** (`?tab=…`) the
server resolves in `ScenarioResults::mount()`, not a JavaScript widget, and an unknown `?tab=`
falls back to the verdict rather than hiding every panel. `ScenarioResults::TABS` is the one list
of tab keys and labels; the view's `$sections` array is the one map of section → tab → nav label →
present-this-render, so a section cannot be in a tab the nav does not list. The pre-existing "on
this page" side nav now jumps *within* the tab on display; the tab bar moves between tabs. Marked
with `aria-current="page"` rather than the ARIA tab-widget roles, whose arrow-key contract a set of
links does not honour.

**B3 tables.** Nine tables that rendered open inline are now inside a `<details>` "Show the…"
disclosure, matching the two that already were (the fan chart and the lump-sum breakdown):
assumption sensitivity, the three income-plan tables, PLSA, secure income by source, the stress-test
crises, the sale waterfall, and the big year-by-year cashflow. `<details>` is native HTML, so the
figures are still there with scripting off.

**B4 banners.** The four banners that rendered *above* the first figure now sit below the verdict
and the outlook chart: the input-sanity note and the care-not-modelled heads-up stay on the verdict
tab (a caveat read before there is anything to caveat is noise); "New in this build" and "Since your
last run" are housekeeping, not figures, so they moved to the fine print.

Assumed, and worth a second opinion:
- **Sections are rendered and hidden, not skipped.** Every section is still in the page HTML with
  its `<details>` table and CSV twin; only the inactive tabs carry `hidden`. That is what makes #4
  true without a JavaScript tab widget, and it keeps the page's cost the same as before, but it does
  mean the browser still builds the charts in the three tabs you cannot see. If that measures badly
  in a browser, the fix is to skip rendering an inactive tab and let the links do the work.
- **Which section is in which tab** is my judgement, not the plan's: the plan names the four tabs
  but maps no sections. The lump-sum tax shock is on the verdict tab because the PRD calls it
  headline output #1; the protection gap, capacity for loss, "Build a what-if" and "How far can we
  go?" are there too, as the risk to the answer and what to do about it.
- **Cross-tab wording.** Six places said "the cashflow table below" about a section that is now in
  another tab; those now name the tab. The `whatsNew` links carry `?tab=` so they land on a visible
  section. Anything still saying "below" points within its own tab.

Not verified here: **no browser check and no a11y run.** This was built in a ProgressBoard worktree
and Herd serves the site from `C:\Dev\RetireForecast`, so nothing here can see the real page.
`npm run a11y` / `a11y:auth` / `a11y:focus` need the app served and were not run; the tab bar, the
new disclosures and the hidden panels all touch what those suites check, so they should run before
sign-off, along with the browser look card 0001 already gates on. The whole PHP suite is green and
`npm run build` compiles the new tab-bar utilities.

One thing I did not change, so it is not a regression but is worth naming: the two **CSV download
buttons are `wire:click`**, so the CSV itself has always needed JavaScript. The accessible table
next to each chart is the no-script path, and that is intact.

### 2026-08-29 review (v20260829192215-6fb5)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 198s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 7:40pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 7:40pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 7:40pm (Europe/London)


## Comments
<!-- The card's thread, appended by ProgressBoard. Append-only: entries are added, never edited or removed. An entry beginning **Decided:** is an answer, and that is what a decision card exits on. -->

**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 4 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 4 of 4 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
