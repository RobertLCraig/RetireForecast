# The usable-wealth labels do not say the pension tax has been taken off

## Why
Card 0076 made spendable wealth net of the tax that would be due on the pension part. The figures
moved; the labels above them did not. Every surface still says only that the home is excluded:

- `resources/views/livewire/scenario-results.blade.php:1643` and
  `resources/views/pdf/partials/report.blade.php:1341`, the cashflow ladder column, "Usable (excl. home)".
- `resources/views/livewire/scenario-results.blade.php:241` and
  `resources/views/pdf/partials/report.blade.php:113`, the headline tile, "Usable wealth left (excl. home)".
- `resources/views/pdf/partials/report.blade.php:127`, "Median usable (excl. home)".
- `resources/views/livewire/scenario-compare.blade.php:124`, the comparison table's own column.

A deduction the reader cannot see in the label is the house rule's exact target: a computed figure
has to be labelled as computed. The netting IS disclosed, in full and with its rate, as an
assumed-figure note on the results page and in the PDF, so this is a signposting gap rather than an
invisible figure. It is small and it is nowhere near the arithmetic, which is why card 0076 left it
rather than growing.

## Links

**Relates to**
- `0076` - netted the pension tax out of usable wealth and disclosed it; this card fixes only the labels it left.

## Not this card
**The netting itself**, and the disclosure note, both of which card 0076 built and tested.

**`availableCapital` / "pension capital"**, the spendable-view pair. Those are a different figure
with a different definition and they are correctly labelled already.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL say, wherever it prints usable or spendable wealth, that the figure is net of
      the tax on the pension part. proves: `test_the_usable_wealth_label_says_the_pension_tax_is_off`
<!-- AC:END -->

## Tasks
- [ ] Reword the six labels listed above, one form of words, and update the `assertSee` strings in
      `tests/Feature/Livewire/ScenarioResultsTest.php` and `tests/Feature/Forecast/ScenarioPdfTest.php`
      that pin them

## Comments

**2026-10-20** RESULT: done
TESTS: +4 new, all green
TOUCHED: app/Forecast/ResultPresenter.php
app/Livewire/ScenarioResults.php
resources/views/livewire/scenario-builder.blade.php
resources/views/livewire/scenario-compare.blade.php
resources/views/livewire/scenario-results.blade.php
resources/views/pdf/partials/report.blade.php
tests/Feature/Forecast/ScenarioPdfTest.php
tests/Feature/Livewire/ScenarioBuilderTest.php
tests/Feature/Livewire/ScenarioCompareTest.php
tests/Feature/Livewire/ScenarioResultsTest.php
docs/board/todo/0207-the-assistant-is-told-spendable-wealth-without-the-pension-tax.md
OUT-OF-SCOPE: 0207

One form of words, "after pension tax", added inside the existing bracket: "Usable (excl. home, after pension tax)", "Usable wealth left (excl. home, after pension tax)", "Median usable (excl. home, after pension tax)". The criterion says wherever the app prints the figure, which is wider than the six labels the Tasks list, so the same words also went on: the ladder CSV header, the builder live preview ("Spendable at end", same terminalUsableWealth figure), the fan and burndown chart axis/basis labels in ResultPresenter, the fan sr-only caption, the compare chart caption and aria-label, and the ladder intro prose on the page and in the PDF. All of these carry the same netted figure (Simulator and PathProjector both net it).

proves: test_the_usable_wealth_label_says_the_pension_tax_is_off exists in four classes (ScenarioResultsTest: page ladder, run tile, CSV; ScenarioPdfTest: ladder, tile, median column after a 20-path preview; ScenarioCompareTest; ScenarioBuilderTest). All four were watched red on the old labels before the views changed; the PDF one was also re-watched red against the HEAD report template after it gained the preview line. Existing assertSee strings that pinned the old labels in ScenarioResultsTest, ScenarioPdfTest and ScenarioBuilderTest were updated to the new words (a deliberate wording change, not a loosening).

Not changed: the assistant context strings (ScenarioContext, ComparisonContext) still say only "excludes the home"; that is card 0207. Wider plain-English prose such as the compare page's "usable money (excluding your home)" footnote was left as prose, not a label.

Still needs a browser check: Herd serves C:\Dev\RetireForecast, not this worktree, so the longer column header in the cashflow ladder and the PDF's dense landscape table have not been seen for wrapping or overflow.
