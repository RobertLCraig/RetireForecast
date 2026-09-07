# The deferred payment interest rate and the care property disregard are stated, not verified

## Why
Card 0055 built the care property disregard and the deferred payment agreement in an unattended
session with no web access, so both are the session's own statement of the rule rather than
anything read off a source. Both reach a projection, which is what makes this a defect and not a
tidy-up: the interest rate decides how much of the home a care spell eats, and the disregard list
decides whether the home is charged against at all.

Two things need pinning to a primary source and a `verified_on` date:

**The maximum interest rate on a deferred payment agreement.** Shipped as 4.65% a year on
`Care\DeferredPaymentAgreement::MAXIMUM_INTEREST_RATE_BPS`. The RULE is published: the average of
the OBR forecast for the 15-year gilt rate plus a default component of up to 0.15 percentage
points, re-set on 1 January and 1 July. The VALUE is the high end of the range that rule has
produced since 2015, chosen adverse under Rob's standing rule, and it is not pinned to any
publication.

**The mandatory property disregard list.** Shipped as spouse or civil partner, a relative aged 60
or over, an incapacitated relative of any age, and a child of the resident under 18, on
`Dto\Property::$occupiedByQualifyingRelative` and in the builder copy the reader ticks it from.
The citation given is the Care and Support (Charging and Assessment of Resources) Regulations 2014,
Schedule 2, and that citation was not read.

An unattended session cannot close this: WebSearch and WebFetch are refused in a card session, so
this one needs a person at a browser or a session that has the web.

## Links

**Relates to**
- `0055` - built both figures and flagged them; this card is the sourcing it could not do.
- `0113` - the same shape of gap on the Housing Benefit figures, still open.

## Not this card
Whether the rate should be adverse at all, which is settled: Rob's standing rule. If the pinned
figure turns out lower than 4.65%, keep the adverse posture and say in the constant why the shipped
number differs from the current publication.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a primary-source URL and a verified_on date for the deferred payment maximum interest rate, in the constant that owns it and in docs/spec/ASSUMPTIONS.md §28. proves: manual
- [ ] THE APP SHALL carry a primary-source citation for the mandatory property disregard list, in the same two places. proves: manual
<!-- AC:END -->

## Tasks
- [ ] Find the current published maximum interest rate for deferred payment agreements and the
      regulation that sets the rule.
- [ ] Read Schedule 2 of the Charging and Assessment of Resources Regulations 2014 on the property
      disregard and check the four categories, their exact wording and any conditions.
- [ ] Update `Care\DeferredPaymentAgreement`, `Dto\Property` and ASSUMPTIONS.md §28. If the rate
      moves, bump `ScenarioForecaster::ENGINE_VERSION`: every plan with a deferred care year moves.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The two homes are
`packages/finance-engine/src/Care/DeferredPaymentAgreement.php` and
`packages/finance-engine/src/Dto/Property.php`. Both carry a `SOURCING GAP` paragraph saying
exactly what is stated and what is not; replace it rather than adding beside it.

The rate is read in two places and restated in neither: `PathProjector::growState` rolls the
balance up with it and `ResultPresenter::inputNotes` discloses it. Changing the constant is
therefore the whole change.

`CareMeansTestedChargeTest` reads the rate from the constant too, so a moved figure will not redden
it. The Monte Carlo golden master WILL redden if the rate moves: re-pin it, bump `PIN_REVISION` and
write the DECISIONS.md entry, per that test's own docblock.

Run `php artisan test` after.

## Comments


**2026-09-07** The loop moved this card from todo/ to human-review/ WITHOUT trying it. All 2 of its open acceptance criteria say proves: manual, so there is nothing left an unattended session could close and starting one would change nothing. Each open criterion names what to look at and what a pass is: tick what passes and move the card on, or say what failed and move it back to todo/.
