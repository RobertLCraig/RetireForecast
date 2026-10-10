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

**2026-10-10** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card is research and needs the web. In this unattended session WebSearch and WebFetch were both refused at the permission prompt, so no source could be read. The card's own Plan says it 'needs a session with web access'.

I checked the repository for an existing citation first: docs/ holds no source for the RPI-to-CPIH alignment date, the RPI-over-CPI wedge, or a distribution of fixed DB escalation rates. The only mentions are the unsourced claim itself (ASSUMPTIONS.md, the 0035 card, HANDOVER-ARCHIVE).

Why no criterion is met:
- #1 allows 'record that the search found none', but no search ran. Writing that line would be false, so I did not.
- #2 depends on a sourced figure. With none, the shipped constants (RPI_OVER_CPI_WEDGE_BPS = 0, DEFAULT_FIXED_ESCALATION_BPS = 300) stand unchanged, and there is no failing case to write a test for.

I did not route around the refusal with a browser MCP tool, because the refusal is the permission decision.

Next step: run this card in an attended session with web access (or grant WebSearch/WebFetch to card sessions). Cards 0085, 0086, 0087, 0091 and 0092 have the same block and can share one research pass, e.g. via the /research skill.

**2026-10-10** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same block. WebSearch and WebFetch were both refused at the permission prompt again in this unattended session, so no source could be read. The card's own Plan says it needs a session with web access.

Why no criterion is met:
- #1 allows 'record that the search found none', but no search ran. Writing that line would be false.
- #2 needs a sourced figure. With none, RPI_OVER_CPI_WEDGE_BPS = 0 and DEFAULT_FIXED_ESCALATION_BPS = 300 stand, and there is no failing case to write.

I did not route around the refusal with a browser MCP tool, because the refusal is the permission decision.

This card should not go back to the unattended loop: two takes have now failed on the same missing permission. Run it in an attended session with web access (for example with the /research skill), or grant WebSearch/WebFetch to card sessions. Cards 0085, 0086, 0087, 0091 and 0092 share the block and can share one research pass.

**2026-10-10** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-10 manager (m20261010154313-7639)

**outcome: rob**

The work left is web research, and only an attended session (or a permission change that only you can make) can do it.

**what the session said**

The card is stuck on web access. It is not stuck on code.

Both figures are still the old, unsourced ones:
- `PensionEscalationBasis::RPI_OVER_CPI_WEDGE_BPS` is still 0.
- `DbPension::DEFAULT_FIXED_ESCALATION_BPS` is still 300.

Both criteria are still open. No review finding is involved.

The job is research only. The unattended loop cannot do it, because WebSearch and WebFetch are refused there. If I reopen the card, the loop starts it again and stops on the same refusal a third time.

What you do now (pick one):
1. Run card 0095 in a normal session with web access. You can use the `/research` skill. Do 0085, 0086, 0087, 0091 and 0092 in the same pass, because they have the same block. **I recommend this one.**
2. Give WebSearch and WebFetch to card sessions. Then move the card back to `todo/`.

WHY: The work left is web research, and only an attended session (or a permission change that only you can make) can do it.

OUTCOME: rob

