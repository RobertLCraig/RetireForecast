# Two tax dates are read one year wrong: the threshold thaw and pensions entering the estate

## Why
Found by the full review on 2026-10-21. Both are small, and one errs cautious. Check the engine's
calendar-year to tax-year mapping first: if calendar year Y stands for tax year Y/Y+1, both are wrong
as below.

1. **The threshold freeze runs one year too long.** `ForecastSettings::$freezeEndYear` defaults to
   2031, and `PathProjector` holds thresholds flat while `$calendarYear <= freezeEndYear`. The freeze
   ends on 5 April 2031, so tax year 2031-32 (calendar 2031) should already be uprated. One extra year
   of fiscal drag, about 2% real on every threshold.
2. **Pensions enter the estate from 1 January 2027, not 6 April 2027.**
   `PathProjector::PENSIONS_IN_ESTATE_FROM_YEAR = 2027` tests the death year only. A death from
   January to 5 April 2027 is charged IHT on the pension wrongly. Source:
   https://www.mayerbrown.com/en/insights/publications/2026/04/finance-act-2026-inheritance-tax-treatment-of-pension-death-benefits

Also fix while here: the `TaxYearRegistry.php` IHT docblock says the residence nil-rate band is
frozen to 5 April 2030. Budget 2025 extended both bands to 5 April 2031. The figure is right; only
the comment is stale.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the plan reaches tax year 2031-32, THE APP SHALL index the income-tax thresholds with inflation. proves: `test_thresholds_index_from_tax_year_2031_32`
- [x] #2 WHEN a member dies before 6 April 2027, THE APP SHALL keep their unused pension out of the estate. proves: manual
<!-- AC:END -->

## Comments

**2026-10-22** RESULT: built (#1); #2 needed no change
TESTS: +1 new (`ThresholdFreezeTest::test_thresholds_index_from_tax_year_2031_32`), watched red on the old default and green on the new
TOUCHED: `ForecastSettings::$freezeEndYear` default 2031 to 2030 and its docblock, the RNRB docblock in `TaxYearRegistry`, ENGINE_VERSION
OUT-OF-SCOPE: none

The mapping the card asked to check holds by construction: `ForecastSettings(baseYear: 2026,
baseTaxYear: '2026-27')`, so a year is the tax year that starts in it. Under that rule:
- #1 was wrong. `$freezeEndYear` is the last FROZEN year, so 2031 kept tax year 2031-32 frozen.
  It is now 2030.
- #2 was not wrong. Death year 2027 means a death in tax year 2027-28, which starts on 6 April
  2027, so `PENSIONS_IN_ESTATE_FROM_YEAR = 2027` is the April date already. The review read the
  year as a calendar year. No test was written for a defect that does not exist; `proves: manual`
  is this note.
