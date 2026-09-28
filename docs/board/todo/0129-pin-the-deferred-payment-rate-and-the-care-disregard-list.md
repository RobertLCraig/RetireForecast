---
waiting_on: progressboard#0211, the research-only web session Rob ruled for on 0084 - recheck 2026-10-13
---
# The deferred payment interest rate and the care property disregard are stated, not verified

## What I need from you
Reply A or B: who looks up the deferred payment rate and Schedule 2's property disregard, given the unattended loop cannot reach the web (card 0084 asks the same question)?

- **A. An attended session.** The card stays out of the loop. The next time you run a session by hand, tell it "source card 0129". It has the web and can read the OBR-based rate and the 2014 Regulations in one sitting. I recommend A: it is what 0084 recommends, and it keeps an agent nobody is watching off the internet.
- **B. Web for the loop.** You allow WebSearch and WebFetch in the unattended build sessions' permissions. This card and the other sourcing cards (0085, 0086, 0087, 0091, 0092) then build on their own. The risk of an unwatched agent fetching from the internet is yours to accept.

**Pass:** you reply A or B. After A, the card stays here until an attended session writes a primary-source URL and `verified_on` into `Care\DeferredPaymentAgreement`, `Dto\Property` and `docs/spec/ASSUMPTIONS.md` §28. After B, it goes back to `todo/` and the loop's next take does that.
**Fail:** the card is moved back to `todo/` without either answer. The loop will park it again, because both criteria are `proves: manual` web reads. Answer 0084 first.

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

**2026-09-28** Manager pass: left in `human-review/` with an ask. Both open criteria need a source read on the web, which an unattended session is refused. Only Rob can decide whether the loop gets the web (card 0084).

**2026-09-29** **Decided:** B, in the narrower form Rob ruled on 0084 on 2026-09-28: the unattended loop gets the web only in a separate research session that can write notes under `docs/research/` and nothing else, and a later build session with no web builds this card from the note. Settled by an attended agent under Rob's rule that human-review holds only what he must decide: this card's A-or-B ask is the question 0084 asked, and Rob answered it there. The research session is `progressboard#0211`, not yet built, so this card carries `waiting_on:` until it lands, which keeps the build loop from spending another take on the refusal.

