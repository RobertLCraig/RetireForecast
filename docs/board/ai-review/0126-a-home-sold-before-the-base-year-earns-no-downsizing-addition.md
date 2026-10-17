# A home sold before the plan starts earns no downsizing addition

## Why
Card 0053 built the Inheritance Tax downsizing addition, but only for a disposal the MODEL makes:
the year-0 sell variants and an in-projection forced sale. `Household::$formerResidenceDisposal` is
derived, and there is no builder field behind it.

So a household that already downsized, say in 2020, and comes to this tool in 2026 has no disposal
on record and gets no addition. The statute would give them one, and the amount is worth up to
£175,000 of band per person. Their forecast therefore overstates the Inheritance Tax on every
option, including staying put, which is the option a household that has already downsized is most
likely to be weighing.

Nothing tells them either: the results page names the addition only when there IS one, so the
absence reads as "the rule does not apply to me".

## Links

**Relates to**
- `0053` - built the rule; this is the input it has no way to receive.

## Not this card
The arithmetic, which is settled and tested.

## Acceptance
<!-- AC:BEGIN -->
- [x] THE APP SHALL let a reader record a former main home sold before the plan starts, with its value and the year. proves: `test_a_disposal_entered_in_the_builder_earns_a_downsizing_addition`
- [x] THE APP SHALL keep a modelled disposal and an entered one on one definition, so a plan that sells again does not claim two additions. proves: `test_a_modelled_sale_replaces_an_entered_prior_disposal`
<!-- AC:END -->

## Tasks
- [ ] Add the builder field. **Four things move together**: the blank default, validation,
      `loadState` backfill and `BuilderStateFixture::full`.
- [ ] Map it through `HouseholdAssembler` to `Household::$formerResidenceDisposal`, and decide the
      precedence against the disposal the housing transforms set.
- [ ] Check the value basis: the statute wants the value of the interest disposed of, net of what
      was secured on it, which is what the engine records for its own disposals.

## Comments

**2026-10-17** RESULT: done
TESTS: +2 new, all green
TOUCHED: app/Livewire/ScenarioBuilder.php
app/Forecast/HouseholdAssembler.php
app/Forecast/WhatIfChanges.php
resources/views/livewire/scenario-builder.blade.php
packages/finance-engine/src/Dto/ResidenceDisposal.php (docblock only)
packages/finance-engine/src/Iht/InheritanceTaxCalculator.php (docblock only)
docs/DATA-MODEL.md
docs/HANDOVER.md
tests/Feature/Livewire/ScenarioBuilderTest.php
tests/Feature/Forecast/ScenarioForecasterTest.php
docs/board/todo/0205-the-downsizing-addition-measures-an-early-sale-against-the-wrong-band.md
OUT-OF-SCOPE: 0205

Built two step-1 inputs beside the IHT questions, priorHomeSaleValue (pounds, net of what was secured on it) and priorHomeSaleYear. Each is required with the other; the year is 1900 to the current year. Both are top-level and stored only when given, like marriageDate, so of the card's four things only the default and validation moved: loadState sets top-level keys by property_exists, so it needs no backfill, and BuilderStateFixture::full carries no top-level sparse keys, so it needs no change. HouseholdAssembler maps them to Household::$formerResidenceDisposal.

Precedence needed no new code: the sell transforms (HousingComparison::withHousing) and the forced sale in PathProjector already write their own disposal into that one slot, so a modelled sale replaces an entered one. Stay put keeps the entered sale.

Watched failing: test_a_modelled_sale_replaces_an_entered_prior_disposal failed on the stay-put household carrying null, not the entered sale. test_a_disposal_entered_in_the_builder_earns_a_downsizing_addition first failed on the missing builder property; after the builder field was added it failed on the assembled household carrying null, which is the criterion's reason. The replace rule itself was never seen red, because the engine already held one slot before this card; the test pins it by asserting the rent plan's addition is identical with and without an entered sale.

Value basis: the help text asks for the reader's share of the price less the mortgage paid off from it, the net-of-secured-debt basis the engine records its own sales on. A 2015 sale is excluded by the existing year gate, and the help text says so.

Not settled here: card 0205. The calculator caps the former home at the band in force at DEATH, so a 2016 to 2019 sale, now reachable, gets too small an addition. The statute detail is from memory; no web in this session.

The new inputs are NOT seen in a browser: Herd serves C:\Dev\RetireForecast, not this worktree. They still need a browser check.
