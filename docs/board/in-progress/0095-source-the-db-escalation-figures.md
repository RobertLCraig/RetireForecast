# Pin the DB escalation figures to a published source

## Why
Card 0035 made both defined-benefit escalation dropdowns live. Two of the figures behind them are
the building session's own judgement, cited to nothing published:

- `PensionEscalationBasis::RPI_OVER_CPI_WEDGE_BPS` = **0**. RPI is modelled as escalating at exactly
  CPI. The reasoning shipped in the docblock is that RPI is being aligned with CPIH from February
  2030, so a plan of this length spends nearly all of its years past the point where the two agree.
  That reasoning is stated from the building session's own knowledge and was NOT checked against the
  UK Statistics Authority or HM Treasury announcement. The pre-2030 years also carry a real wedge
  that the model simply does not apply.
- `DbPension::DEFAULT_FIXED_ESCALATION_BPS` = **300** (3% a year), used when a scheme is set to a
  Fixed increase and the reader entered no rate. 3% and 5% are the two rates scheme rules commonly
  grant and 3% is the lower, which is the house rule for an income figure, but neither the pair nor
  the choice between them is cited.

Both compound on GUARANTEED income for the whole projection, which is the part of the plan a reader
leans on hardest. The 3% default is disclosed (`ResultPresenter::assumedFigures()`) and the RPI
treatment is disclosed too, so neither is invisible; they are unsourced, which is a different fault.

The two statutory caps beside them, 5% and 2.5%, are NOT part of this gap: they are the limited
price indexation ceilings in the Pensions Act 1995 s.51 as amended by the Pensions Act 2004 s.278,
and the enum's docblock says so.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0035 could ship and disclose the figures but could not go and check them.

## Links

**Relates to**
- `0035` - introduced both figures and their disclosures.
- `0085`, `0086`, `0087`, `0091`, `0092` - the same shape of gap, from the same missing web access.
  Whoever picks one up can settle several in one research pass.

## Not this card
The mechanism and the disclosure. Per-scheme escalation, the deferred/in-payment split and the two
disclosure sentences are card 0035's and are built. This card only replaces numbers and adds their
citations.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a primary or fetchable secondary source URL and a verified_on date for the RPI-over-CPI wedge and the default fixed escalation rate in docs/spec/ASSUMPTIONS.md, or record there that the search found none. proves: manual
- [ ] WHEN a sourced figure differs from the shipped one, THE APP SHALL use the sourced one, and the disclosure that reads the constant SHALL move with it. proves: `test_an_assumed_fixed_db_escalation_rate_is_disclosed_with_its_value`
<!-- AC:END -->

## Tasks
- [ ] Confirm the RPI-to-CPIH alignment from February 2030 against a primary source (the UK
      Statistics Authority statement, or the Chancellor's response to the 2020 consultation), and
      cite it.
- [ ] Find a published long-run RPI-over-CPI wedge (the OBR's Economic and Fiscal Outlook
      determinants table is the likely place) and decide whether the pre-2030 years justify a
      non-zero wedge, a decaying one, or none.
- [ ] Find a published distribution of fixed escalation rates in UK scheme rules (the Pensions
      Regulator, the PPF Purple Book, or a scheme-funding survey) to settle the 3% default.
- [ ] Set the constants, or record on this card why the shipped figures stand.
- [ ] Update docs/spec/ASSUMPTIONS.md §18, moving it out of the sourcing-gap list, and add the
      citations.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Both constants are in
`packages/finance-engine/src/Dto/` (`PensionEscalationBasis.php` and `DbPension.php`), and the two
disclosures in `ResultPresenter::assumedFigures()` read them rather than restating them, so changing
a constant moves the screen with no other edit. Fixtures:
`packages/finance-engine/tests/Forecast/DbEscalationTest.php` (which asserts the wedge is zero, so a
non-zero wedge must move that test with it) and
`tests/Unit/Forecast/AssumedFiguresDisclosureTest.php`.

**A moved figure needs an `ENGINE_VERSION` bump** in `app/Forecast/ScenarioForecaster.php` and a
re-run of every stored scenario, because these figures change projected income. Run
`php artisan test` and `php artisan scenarios:audit` after.

## Comments
