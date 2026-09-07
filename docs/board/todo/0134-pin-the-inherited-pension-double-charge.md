# Pin the inherited-pension double charge to a published source

## Why
Card 0057 made an unused pension pot show BOTH the taxes that fall on it: Inheritance Tax on the
estate, and then the beneficiary's own income tax on drawing what is left. Three statements do that
work, and none was read off a live page, because the unattended build loop has **no web access**:

- **The age-75 dividing line.** An inherited fund is taxable as the beneficiary's own pension income
  where the member died at or after 75, and free of income tax where they died before
  (`InheritanceTaxCalculator::BENEFICIARY_TAXED_FROM_AGE`). Stated from Finance Act 2004 s.579A and
  Schedule 28 as amended by the Taxation of Pensions Act 2014; the PTM073010 citation in
  [docs/spec/ASSUMPTIONS.md](../../spec/ASSUMPTIONS.md) section 30 is an unvisited URL.
- **That the April 2027 change does not displace it.** The whole point of the card is that a pot
  suffers both charges rather than one. Nothing in the repository cites the 2027 legislation on
  this point.
- **The nomination routing.** That a death benefit is paid at the scheme's discretion on the
  member's expression of wish rather than under the will, so the spouse exemption follows
  `DcPension::$nominatedBeneficiary`. Also stated, not cited.

What it costs: the first two set the size of the second charge, which is the figure the whole
spend-versus-preserve comparison turns on; the third reaches a projection directly, because it
decides whether the first death is taxed at all.

The apportionment (that the pot bears its rateable share of the estate's Inheritance Tax, so the
income tax is charged on the pot NET of it) is the same shape of gap and should be settled in the
same pass.

## Links

**Relates to**
- `0057` - built the charge, the nomination and the disclosure that reads these constants.
- `0079` - an inherited pot is taxed in full even where the member died before 75, which is the
  under-75 half of the same rule. One research pass settles both.
- `0106`, `0109`, `0111`, `0113`, `0118`, `0125`, `0127`, `0129`, `0132` - the same shape of gap
  from the same missing web access.

## Not this card
The assumed 40% beneficiary rate. That is a judgement with no published answer, deliberately set
adverse and exposed as a builder control; it is written up in ASSUMPTIONS section 30 and needs no
source.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for all three rules in docs/spec/ASSUMPTIONS.md section 30, or record there that the search found none. proves: manual
- [ ] WHEN a published rule differs from the shipped one, THE APP SHALL use the published one, and the charge, the warning and the results copy SHALL all move with it. proves: `test_a_pot_inherited_on_a_death_at_or_after_75_is_taxed_as_the_beneficiarys_income`
<!-- AC:END -->

## Tasks
- [ ] Read PTM073010 and the Finance Act 2004 provisions behind the age-75 line, and confirm both
      the age and that it is the MEMBER's age that governs.
- [ ] Confirm in the April 2027 legislation that the Inheritance Tax charge does not displace the
      beneficiary's income tax, and how the two interact where the tax is paid out of the pot.
- [ ] Confirm the rateable apportionment of Inheritance Tax across the chargeable estate, which is
      what `InheritanceTaxCalculator::beneficiaryIncomeTax` charges the beneficiary net of.
- [ ] Confirm that a nominated death benefit falls outside the will and the intestacy rules, which
      is what card 0057 routed the spouse exemption off.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if any rule moves, and note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. One file holds all of
it: `packages/finance-engine/src/Iht/InheritanceTaxCalculator.php`. Nothing restates the constants:
the projector, the disclosure and the results copy all read them.

Run `php artisan test` after. `InheritedPensionTaxTest` pins the arithmetic to the penny and
`InheritedPensionForecastTest` proves it reaches a forecast, so a corrected rule reddens both
together.

## Comments
