# Source the annuity rate table and the chattels figures

## Why
Card 0065 replaced a single free-text annuity rate with a table by age, escalation basis and
joint-life setting, and added the chattels capital-gains rule. Every figure behind both was
**stated, not read off a live page**, because the unattended build loop has **no web access**.

- **The annuity rate table** (`Pension\AnnuityRateTable`). Seven base rates by age for a single
  life, level, standard health, plus an index-linked multiple of 0.62 and a joint-life reduction of
  20% at a full survivor's pension. Written from the standing shape of the UK open-market option as
  the published comparison tables have carried it, not from a dated quote. It reaches a projection
  through the builder's rate field, which the reader can see and type over, so nothing it decides is
  invisible; but a reader with no quote of their own gets these numbers.
- **The chattels exempt amount, £6,000, and the five-thirds marginal relief**
  (`CgtParameters::$chattelsExemptAmount` and `Tax\ChattelsGain`). Both are statute rather than
  judgement, and the statute is cited (TCGA 1992 s262), but neither was checked against the current
  gov.uk page. The £6,000 in particular is believed never to have been uprated since 1989, which is
  exactly the kind of belief that is worth five minutes of checking.

What it costs: the annuity figures decide how good annuitising looks against drawdown, which is one
of the two or three comparisons this tool exists to run. An over-generous table makes secured income
look cheap; an over-mean one buries it.

## Links

**Relates to**
- `0065` - built the table, the tax-free lump sum split and the chattels charge.
- `0136` - the purchased-life-annuity tax split and the enhanced uplift, the same subject.
- `0106`, `0109`, `0111`, `0113`, `0118`, `0125`, `0127`, `0129`, `0132`, `0134`, `0135`, `0137`,
  `0138`, `0139` - the same shape of gap from the same missing web access.

## Not this card
Pricing what the table deliberately leaves out (a guarantee period, value protection, the spouse's
age, the buyer's postcode, gilt yields moving the market). Each of them makes a real quote LOWER
than this table, so leaving them out is the flattering direction and is worth its own card, but it
is a change of shape and not a re-sourcing.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for every figure in
      docs/spec/ASSUMPTIONS.md sections 36 and 37, or record there that the search found none.
      proves: manual
- [ ] WHEN a sourced figure differs from the stated one, THE APP SHALL re-pin the constant to it.
      proves: `test_choosing_an_escalating_annuity_recalculates_the_rate_from_the_table`
<!-- AC:END -->

## Tasks
- [ ] Take a dated snapshot from a live open-market-option quote service (Money Helper's annuity
      comparison tool, or the Hargreaves Lansdown / Legal and General best-buy tables) across the
      age range, for single and joint life and for level and index-linked.
- [ ] Re-pin `AnnuityRateTable::LEVEL_SINGLE_LIFE_BPS`, `INDEX_LINKED_MULTIPLE` and
      `FULL_SURVIVOR_REDUCTION`, and move `VERIFIED_ON` to the snapshot date.
- [ ] Confirm the £6,000 chattels exempt amount against the current gov.uk page and re-stamp the
      CGT block's verified-on date.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if any figure that reaches a stored plan moves, and
      note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Two files hold every
figure: `packages/finance-engine/src/Pension/AnnuityRateTable.php` and
`packages/finance-engine/src/TaxYear/TaxYearRegistry.php`. The builder, the assembler and the
methodology page all READ the table rather than restating it, so moving a constant moves every
sentence about it.

Run `php artisan test` after, then `php artisan figures:freshness`, which now sweeps the economic
assumptions as well as the statutory ones.

## Comments
