# Downsizing deletes the residence nil-rate band

## Why
From the expert panel, 2026-08-19 (estate planner finding 1). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`InheritanceTaxCalculator` caps the residence nil-rate band at the home passing to descendants, and
`PathProjector::recordFinalDeathIht()` supplies that as home equity **at the final death**.

So a sell-and-rent plan has no home at death and gets a band of nil. A sell-and-buy-cheaper plan is
capped at the cheaper home. There is no downsizing logic anywhere in the engine.

The downsizing addition exists in statute precisely to prevent this. Where a qualifying former
residence was disposed of on or after 8 July 2015, the lost proportion of the band is restored,
provided other assets of at least equal value pass to direct descendants.

This is the tool's own purpose. It exists to compare staying put against downsizing, and as coded
every downsizing option is penalised by tax the statute is written to prevent. For an estate near
the bands that is a six-figure error, pointing the wrong way.

## Not this card
Lifetime gifting, which is card 0059.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a household disposes of a qualifying residence and later dies owning a cheaper home or none, THE APP SHALL restore the lost residence nil-rate band as a downsizing addition.
- [x] #2 THE APP SHALL cap the addition at the value of non-home assets passing to direct descendants.
- [x] #3 THE APP SHALL apply the estate taper to the total band after the addition, not before.
<!-- AC:END -->

## Tasks
- [x] Record the disposal value and date on projector state when a housing action sells
- [x] Compute the lost percentage of the maximum band at disposal, apply it at death
- [x] Cap at qualifying non-home assets; order the taper correctly
- [ ] Source the rule with `source` and `verified_on` (source given, NOT verified: no web in this
      session, carded as 0125)
- [x] Tests for sell-and-rent, sell-and-buy-cheaper and stay-put

## Comments

**2026-09-07**
RESULT: done
TESTS: +10 new, all green
TOUCHED:
packages/finance-engine/src/Dto/ResidenceDisposal.php (new)
packages/finance-engine/src/Dto/Household.php
packages/finance-engine/src/Iht/InheritanceTaxCalculator.php
packages/finance-engine/src/Iht/IhtResult.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Housing/HousingComparison.php
packages/finance-engine/src/Support/WarningCode.php
packages/finance-engine/tests/Iht/RnrbDownsizingAdditionTest.php (new)
packages/finance-engine/tests/Dto/HouseholdWitherTest.php
app/Forecast/ScenarioForecaster.php
app/Forecast/ResultPresenter.php
tests/Unit/Forecast/IhtPanelTest.php
resources/views/livewire/scenario-results.blade.php
resources/views/pdf/partials/report.blade.php
docs/spec/ASSUMPTIONS.md
docs/DATA-MODEL.md
docs/HANDOVER.md
docs/board/todo/0124-rent-variant-settings-silently-drop-six-fields.md (new)
docs/board/todo/0125-downsizing-addition-rule-is-stated-not-verified.md (new)
docs/board/todo/0126-a-home-sold-before-the-base-year-earns-no-downsizing-addition.md (new)
OUT-OF-SCOPE: 0124, 0125, 0126

A disposal is now a recorded fact. `Dto\ResidenceDisposal` holds the household's own interest in
the home at the moment it was sold (its share of the price less the debt secured on it, the same
net basis the estate values a home on at death) and the year. `HousingComparison` sets it on the
household for BOTH sell variants, because the year-0 sale happens before the projector runs;
`PathProjector` seeds `state['residenceDisposal']` from it and overwrites it at a forced sale, so
the two disposal routes end at one place. `InheritanceTaxCalculator::downsizingAddition()` is the
one home of the rule.

The arithmetic, watched failing first (the addition was stubbed to zero, so every criterion's test
was red on the figure rather than on a missing class):

    lost     = min(disposal, max band) - min(home at death, max band)
    addition = min(lost, everything other than the home passing to descendants)
    band     = min(tapered allowance, home at death + addition)

That last line is criterion #3, and it is why nothing else moved. With no disposal the addition is
zero and the expression collapses to the `min(band after taper, home)` the calculator already had,
so every stay-put plan is byte-identical and `GoldenMasterTest` did not redden. A sell plan is up
to £70,000 of tax better off per band, which is why `ENGINE_VERSION` is
`finance-engine/rnrb-downsizing-addition` and **the stored-scenario re-run is owed.**

Assumed, and written into docs/spec/ASSUMPTIONS.md §26 rather than left in code: the statute works
in PERCENTAGES of the maximum band at the disposal date and at death, and the engine subtracts
pence instead. The two are the same number here because one frozen band is in force for a whole
run, and the subtraction is exact where a percentage rounds twice. Where a plan disposes of more
than one home the engine takes the MOST RECENT, where the statute lets personal representatives
choose. And `ResidenceDisposal` carries a year, not a date, so the 8 July 2015 gate is stated
rather than resolved; the engine models no disposal before its own base year, so it can never be
the deciding test.

The one task left open is the SOURCE. Every figure and rule here is stated from the statute and
**not verified**: an unattended card session has no web, so no `verified_on` date could honestly be
written. That is card **0125**, and the constant's docblock says so where a reader will meet it.

Made visible rather than left inside the band: `IhtResult::$downsizingAddition` reports the
addition apart from the band it rides in, the calculator raises an
`IHT_DOWNSIZING_ADDITION` warning naming the amount, and the results page and the PDF both say how
much of the residence band was added back and why. A residence nil-rate band shown beside no house
is otherwise a figure the reader cannot account for. Built in a worktree, so **that copy has not
been seen in a browser.**

Raised, not fixed: **0124**. `HousingComparison::rentSettings()` rebuilds `ForecastSettings` by
hand and lists eight of its fourteen fields, so the rent variant silently reverts `modelIht`,
`useIsaAllowance`, `homeToDescendants`, `sellingCosts` and both triple-lock fields to their
defaults. The rent leg therefore models no Inheritance Tax at all, whatever the reader chose. This
card's end-to-end test could not read criterion #1 off the rent leg's own settings and supplies its
own; the test says so where it does it.

Also raised, not fixed: **0126**. A disposal made BEFORE the base year has no builder field, so a
household that downsized in 2020 and comes to this tool in 2026 still gets no addition, and nothing
tells them the rule was not applied.
