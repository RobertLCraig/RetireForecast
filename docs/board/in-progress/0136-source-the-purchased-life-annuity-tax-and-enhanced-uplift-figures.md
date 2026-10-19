# Pin the purchased-life-annuity tax split and the enhanced uplift to a published source

## Why
Card 0060 let an annuity be bought with money that is not pension money, and taxed it as a
purchased life annuity. Two figures behind that were **stated, not read off a live page**, because
the unattended build loop has **no web access**. Both reach a projection.

- **The expectation of life the exempt capital element is spread over**
  (`Pension\PurchasedLifeAnnuity::capitalElementPerYear`). The statute sets it from tables
  prescribed by the Income Tax (Purchased Life Annuities) Regulations. This engine holds no copy of
  those tables, so it uses its own ONS cohort life expectancy instead. That is a real sourced
  figure rather than an invented one, but it is not the prescribed one, so the tax on such an
  annuity is an estimate. A shorter prescribed expectancy exempts MORE of each payment, so the
  direction of the error is not known without the tables.
- **The enhanced (impaired-life) uplift, 10%** (`Dto\AnnuityPurchase::ENHANCED_UPLIFT_BPS`). Stated
  as the cautious end of a market range running from a few per cent to roughly a third. It
  multiplies the secured income for the whole of the survivor's life, which is exactly the figure
  the card exists to size.

What it costs: the first is income tax on an annuity every year it is in payment; the second is the
income itself, and a household choosing between annuitising and drawing down is choosing on it.

## Links

**Relates to**
- `0060` - built both, and the two disclosures that read them.
- `0065` - annuity RATE sourcing and the tax-free lump sum interaction, which is the sibling gap.
- `0106`, `0109`, `0111`, `0113`, `0118`, `0125`, `0127`, `0129`, `0132`, `0134`, `0135` - the same
  shape of gap from the same missing web access.

## Not this card
The annuity rate itself, which is a user input defaulted from a quote and belongs to card 0065.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for both figures in docs/spec/ASSUMPTIONS.md section 32, or record there that the search found none. proves: manual
- [ ] WHEN the prescribed expectation-of-life table differs from the engine's own life expectancy, THE APP SHALL use the prescribed one and say so instead of calling the tax an estimate. proves: `test_only_the_interest_element_of_a_purchased_life_annuity_is_taxed`
<!-- AC:END -->

## Tasks
- [ ] Read the Income Tax (Purchased Life Annuities) Regulations and find the prescribed
      expectation-of-life tables, including whether they differ by sex and by the annuity's term.
- [ ] Confirm whether the capital element is fixed for the life of the annuity on an ESCALATING
      contract, which is what the engine assumes.
- [ ] Find a current published range for enhanced annuity uplifts and re-pin the constant.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if either figure moves, and note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Two files hold the
figures: `packages/finance-engine/src/Pension/PurchasedLifeAnnuity.php` and
`packages/finance-engine/src/Dto/AnnuityPurchase.php`. Nothing restates either: the projector, the
builder's help text and `ResultPresenter::assumedFigures()` all read the constants.

Run `php artisan test` after. `Forecast\PurchasedLifeAnnuityTest` pins the arithmetic against the
same classes, so a corrected figure reddens it rather than passing silently.

## Comments

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card needs web access, and this unattended session has none. WebSearch and WebFetch were both refused on 2026-10-19 (a fetch of legislation.gov.uk/uksi/2008/562 was the test). So nothing was built.

Criterion 1 is not met: no search ran, so writing 'the search found none' into ASSUMPTIONS.md section 32 would be false. Criterion 2 is not met: the prescribed expectation-of-life table is not in the repository, and putting a figure from memory into the engine would break the no-invented-figures rule. No failing test was written for 2, because the test cannot be expressed without the prescribed figure it should assert.

Direction: run this card in an interactive session with web allowed (the Plan already says so), or mark it not_for_the_loop so the scheduler stops picking it up. Open questions for that session are the card's own Tasks: which tables the Income Tax (Purchased Life Annuities) Regulations 2008 (SI 2008/562) prescribe and whether they vary by sex and term; whether the capital element stays fixed on an escalating contract; a current published enhanced-annuity uplift range to re-pin ENHANCED_UPLIFT_BPS; and an ENGINE_VERSION bump (plus golden-master re-pin) if either figure moves.

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same block as the first. This session has no web access: WebFetch (legislation.gov.uk/uksi/2008/562) and WebSearch were both refused on 2026-10-19. The repository does not hold the prescribed tables or an enhanced-uplift source either: the only mention is the 'To verify' note at docs/spec/ASSUMPTIONS.md line 755.

Criterion 1 is not met: no search ran, so recording 'the search found none' would be false. Criterion 2 is not met: its test needs the prescribed expectation-of-life figure to assert, and that figure is not available without the web. A figure from memory would break the no-invented-figures rule.

Direction: the loop cannot finish this card. Add not_for_the_loop: to its frontmatter so the scheduler stops picking it up, and run it in an interactive session with web allowed. The open questions are the card's own Tasks.
