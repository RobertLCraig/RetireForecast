# The severe-disability addition still skips the non-dependant test and the registered-blind route

## Why
Card 0051 fixed the qualifying-benefit half of the severe-disability addition: the award now has to
be the care side (`Dto\DisabilityAwardRate`), so a mobility-only or lowest-rate-care award no longer
buys an addition nobody is entitled to. Two conditions named in the same expert-panel finding were
left open, both because settling them needs a source this project does not yet hold.

**The non-dependant test.** The addition requires that no other adult "normally resides" with the
claimant, apart from ones the rules excuse. The engine tests the PARTNER only, so a household with
an adult child living at home is awarded an addition it would not get. The model has no concept of a
non-dependant adult at all, so this is a data-shape question first: something has to hold the fact.

**The registered-blind route.** The panel listed it as a missing route into the addition. The
unattended card that would have built it had no web access and could not check whether registration
as severely sight impaired is in fact a route for the pension-age severe-disability addition, or
only for the working-age disability premium it is more often quoted against. Getting that wrong in
either direction moves a real award, so it was left rather than guessed.

## Links

**Relates to**
- `0051` - fixed the qualifying-rate half and left these two.
- `0044` - added the disability start age the addition already reads.

## Not this card
The `paid`-Carer's-Allowance-removes-the-addition interaction, which the engine models as underlying
entitlement on purpose (DECISIONS 2026-07-29).

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN another non-excused adult normally lives in the household, THE APP SHALL award no severe-disability addition. proves: `test_a_non_dependant_adult_blocks_the_severe_disability_addition`
- [ ] THE APP SHALL settle, from a cited source, whether registration as severely sight impaired is a route into the pension-age addition, and either model it or record why not. proves: none
<!-- AC:END -->

## Tasks
- [ ] Check the registered-blind rule against a citable source. **Needs web, so not an unattended
      card.**
- [ ] Decide where a non-dependant adult lives in the data shape. A count on the household is the
      smallest thing that answers the question; anything richer needs a reason.
- [ ] Wire the test into `PathProjector::pensionCreditAward` beside the qualifying-rate gate.
- [ ] Say in the results note what is and is not tested, as the disability note already does.

## Plan
`packages/finance-engine/src/Forecast/PathProjector.php` (`pensionCreditAward`),
`packages/finance-engine/src/Dto/Household.php` and the builder if a new input is needed. A new
builder field moves four things together: the blank default, the validation rule, the load-state
backfill and `Tests\Support\BuilderStateFixture`. Default it to "nobody else lives here", which is
what every stored scenario means today, so nothing stored moves.

## Comments
