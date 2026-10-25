---
research: the current deferred payment agreement maximum interest rate (the OBR-based gilt rate and its published value) and the property disregards in Schedule 2 of the Care and Support (Charging and Assessment of Resources) Regulations 2014, each with a primary-source URL, for Care\DeferredPaymentAgreement, Dto\Property and docs/spec/ASSUMPTIONS.md §28
---
# The deferred payment interest rate and the care property disregard are stated, not verified

## What I need from you
Nothing. The A/B question was decided on 2026-09-29 (see Comments): the loop's research-only session
(`progressboard#0211`, built 2026-10-13) looks the figures up and writes a note under `docs/research/`,
then a build session with no web builds this card from the note.

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
- `0084` - asked whether the unattended loop may use the web; Rob's ruling there is the
  research-only session this card's `waiting_on:` names.

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


**2026-10-13** `waiting_on:` replaced with `research:`. `progressboard#0211` is built and merged, so the loop's next take runs a research-only session for this card, then builds from its note. Attended session, on Rob's go.

**2026-10-18** Research note committed by the loop: docs/research/0129-research.md. 18 figure row(s) lifted by the script, each from an untrusted web page, so check it against its URL: Fixed addition to the gilt rate for the DPA maximum interest rate (reg 9(3)) = 0.15% (https://www.legislation.gov.uk/uksi/2014/2671/regulation/9); DPA relevant period 1 (reg 9(5)) = 1 January to 30 June (https://www.legislation.gov.uk/uksi/2014/2671/regulation/9); DPA relevant period 2 (reg 9(5)) = 1 July to 31 December (https://www.legislation.gov.uk/uksi/2014/2671/regulation/9); Commencement of SI 2014/2671 reg 9 = 1 April 2015 (https://www.legislation.gov.uk/uksi/2014/2671/regulation/9); OBR March 2026 EFO publication date = 3 March 2026 (https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/); OBR March 2026 EFO Table A.3 market gilt rate (weighted average on conventional gilts), 2026-27 = 4.5% (https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/); OBR November 2025 EFO Table A.3 market gilt rate, 2025-26 = 4.6% (https://obr.uk/efo/economic-and-fiscal-outlook-november-2025/); OBR November 2025 EFO Table A.3 market gilt rate, 2026-27 = 4.7% (https://obr.uk/efo/economic-and-fiscal-outlook-november-2025/); DPA maximum interest rate from 1 July 2026 (Central Bedfordshire) = 4.65% (https://www.centralbedfordshire.gov.uk/health-and-social-care/paying-care/deferred-payments/how-does-it-work); DPA interest rate 1 July 2026 to 31 December 2026 (Reading, charges the maximum) = 4.65% (https://www.reading.gov.uk/adult-care/paying-for-your-care/care-and-support-charging-and-financial-assessment-framework-policies/appendix-j-charges-for-deferred-payment-agreements-and-interim-funding-arrangements); DPA interest rate 1 April 2026 to 30 June 2026 (Reading, charges the maximum) = 4.75% (https://www.reading.gov.uk/adult-care/paying-for-your-care/care-and-support-charging-and-financial-assessment-framework-policies/appendix-j-charges-for-deferred-payment-agreements-and-interim-funding-arrangements); Schedule 2 revised text stated up to date to = 15 October 2026 (https://www.legislation.gov.uk/uksi/2014/2672/schedule/2); Commencement of Schedule 2 paras 4 and 5 = 1 April 2015 (https://www.legislation.gov.uk/uksi/2014/2672/schedule/2); Home disregard on first entering a care home (Sch 2 para 2(1)) = 12 weeks (https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made); Disregard after the qualifying relative dies or enters a care home (Sch 2 para 4(3)) = 12 weeks (https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made); Discretionary disregard after an unexpected change in the relative's circumstances (Sch 2 para 4(4)) = 12 weeks (https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made); Qualifying relative age threshold, family member or relative (Sch 2 para 4(5)(b)) = 60 or over (https://www.legislation.gov.uk/uksi/2014/2672/schedule/2); Qualifying relative age threshold, child (Sch 2 para 4(5)(c)) = under 18 (https://www.legislation.gov.uk/uksi/2014/2672/schedule/2)

**2026-10-18** The loop moved this card from todo/ to human-review/ WITHOUT trying it. All 2 of its open acceptance criteria say proves: manual, so there is nothing left an unattended session could close and starting one would change nothing. Each open criterion names what to look at and what a pass is: tick what passes and move the card on, or say what failed and move it back to todo/.

### 2026-10-18 manager (m20261018121739-4849)

**outcome: rob**

The research is done, but the loop will not build a card whose criteria are all `proves: manual`, so only an attended session can finish it.

**what the session said**

The research note is in place: `docs/research/0129-research.md`. The code still has the old "SOURCING GAP" text:

- `Care\DeferredPaymentAgreement::MAXIMUM_INTEREST_RATE_BPS` still says "SOURCING GAP".
- `Dto\Property` has no Schedule 2 citation for the disregard list yet.

The note gives a builder with no web all it needs:

- **Rate:** Regulation 9 of SI 2014/2671 sets the rule: the OBR gilt rate plus 0.15%. The current published maximum is 4.65%. That is the same as the shipped figure, so `ENGINE_VERSION` does not change and the golden master does not need a new pin.
- **Disregard list:** The note found three wording errors in the shipped list:
  - The law says "partner", not "spouse or civil partner".
  - The law says "other family member or relative", not just "relative".
  - The disregard is mandatory only if the relative lived in the home before the resident first went into a care home.

`reopen` will not help. Both criteria say `proves: manual`, and the loop parks a card like that without trying it. The card would come straight back. So a person must do this step: build the card from the note in an attended session, or change the criteria so a test can prove them.

WHY: The research is done, but the loop will not build a card whose criteria are all `proves: manual`, so only an attended session can finish it.

OUTCOME: rob


**2026-10-25** **Research, 2026-10-25 (attended session, web)**
