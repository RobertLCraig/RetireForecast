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

## Not this card
**The netting itself**, and the disclosure note, both of which card 0076 built and tested.

**`availableCapital` / "pension capital"**, the spendable-view pair. Those are a different figure
with a different definition and they are correctly labelled already.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL say, wherever it prints usable or spendable wealth, that the figure is net of
      the tax on the pension part. proves: `test_the_usable_wealth_label_says_the_pension_tax_is_off`
<!-- AC:END -->

## Tasks
- [ ] Reword the six labels listed above, one form of words, and update the `assertSee` strings in
      `tests/Feature/Livewire/ScenarioResultsTest.php` and `tests/Feature/Forecast/ScenarioPdfTest.php`
      that pin them

## Comments
