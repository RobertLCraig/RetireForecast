# A Welsh home gets England's stamp duty and care rules

## Why
Found by the full review on 2026-10-21. The region select offers "England, Wales & Northern Ireland"
as one choice (`ScenarioBuilder::$region`, `RegionProfile::EnglandWalesNi`). Income tax is the same
there, but two other rules are not:

- **Purchase tax.** Wales charges Land Transaction Tax, not SDLT. `SdltParameters.php:17` says so in a
  comment, but nothing stops a Welsh buy plan being charged SDLT.
- **Care means test.** Wales has one capital limit of £50,000 and no tariff band (England: £23,250 /
  £14,250). The Welsh personal expenses allowance is £46.35 a week (England £31.80). Secondary
  source only: https://moneyguide.org.uk/family-life/care-costs-scotland-wales-northern-ireland/ .
  Confirm on gov.wales before building.

A Welsh couple gets a wrong buy cost and a wrong care charge, and is not told.

## Direction
Smallest honest fix first: split the select so Wales is its own choice, and either model LTT and the
Welsh care rules, or refuse Wales the way Scotland is refused ("not available yet"). Do not guess.
Northern Ireland has its own care rules too; check them in the same pass.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the reader says the home is in Wales, THE APP SHALL either apply Welsh purchase tax and care rules or refuse the region with a reason. proves: `test_a_welsh_home_is_not_charged_english_rules`
<!-- AC:END -->

## Comments

**2026-10-22** RESULT: built (refuse, not model)
TESTS: +1 new (`ScenarioBuilderTest::test_a_welsh_home_is_not_charged_english_rules`)
TOUCHED: `ScenarioBuilder` region rule and `regionSupported()`, the step-1 region select, the Scotland refusal text
OUT-OF-SCOPE: modelling Land Transaction Tax and the Welsh care test (a new card if Rob wants Wales); Northern Ireland's care rules, which were NOT checked here

The select now offers "England or Northern Ireland", "Wales" and "Scotland". Wales is refused with
a reason, the way Scotland is. The stored value `england_wales_ni` is kept, so no saved scenario
changes; only its label lost "Wales". The test could not be watched red: before this card "wales"
was not a value at all, so it was refused as invalid. What it guards is that Wales stays refused
until it is modelled. A Welsh couple who already saved a plan under the old label is not detected;
there is no way to tell from the data. The new select is **not seen in a browser**.
