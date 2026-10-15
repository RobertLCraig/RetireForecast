# The care means test values a property with no deduction for the costs of sale

## Why
Card 0048 added the notional costs of sale to the pension-age BENEFITS means test:
`Benefits\CapitalAssessment::propertyCapital` values property at market value, less 10% for the
costs of selling it, then less anything secured on it. That is the rule the Housing Benefit and
Pension Credit capital regulations set.

The CARE financial assessment values property in its own place and did not move.
`PathProjector::careAssessableCapital` still adds the resident's share of the home at value less
mortgage and nothing else. The two are now different answers to the same question in one codebase,
which is the shape of fault this project has a standing rule against: one quantity, one definition.

The Care Act 2014 statutory guidance annex on the treatment of capital carries the same 10%
deduction where a sale would incur costs. **That is this session's recollection and was not read
off the guidance** (the session had no web access), so the first task is to confirm it rather than
to apply it.

What it costs if it is right: a resident is assessed on capital about 10% of the home's value too
high, which brings forward the year the local authority starts contributing and overstates what the
household bears. That reaches every plan where a resident lives alone in care or the home is let,
which is the plan the care modelling exists for.

## Links

**Relates to**
- `0048` - added the deduction on the benefits side and deliberately did not touch the care side.
- `0113` - pins the 10% figure itself. Settle that first, or this card applies an unpinned number
  in a second place.

## Not this card
The benefits means test, which is built. The 12-week property disregard and deferred payment
agreements, which are a documented v1 simplification in `careAssessableCapital` and are not carded.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL confirm from the Care Act 2014 statutory guidance whether the notional costs of sale apply to the care financial assessment, and record the answer with its source in docs/spec/ASSUMPTIONS.md. proves: manual
- [ ] IF they apply, THE APP SHALL value property capital for the care assessment through the one definition the benefits assessment already uses. proves: `test_the_care_assessment_deducts_the_notional_costs_of_sale`
<!-- AC:END -->

## Tasks
- [ ] Read the Care and Support Statutory Guidance, Annex B (treatment of capital), on property
      valuation and the 10% deduction.
- [ ] If it applies, call `CapitalAssessment::propertyCapital` from
      `PathProjector::careAssessableCapital` instead of subtracting the mortgage by hand.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`: every plan that models care and holds property
      moves.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The care valuation is
`PathProjector::careAssessableCapital`; the benefits one it should match is
`CapitalAssessment::propertyCapital`. Note the resident's share: the care assessment splits a
jointly held home equally between the members, and the deduction has to come off the whole value
before the split or it will not reconcile.

`CareMeansTest` covers the charge. The probe to read the answer through is the care charge itself,
because `ForecastResult` reports household totals and nothing else says which person holds an
asset.

Run `php artisan test` after.

## Comments

**2026-10-15** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Criterion 1 needs the Care and Support Statutory Guidance, Annex B, read off the page. This unattended session has no web: WebFetch on gov.uk was refused for permission. No local copy exists in the repository (docs/, docs/research/) or in SecondBrain (find.py 'care act annex capital' returns nothing relevant). The card itself says the 10% is a recollection to be confirmed, not applied, so I did not record it in ASSUMPTIONS.md from memory.

Criterion 2 is conditional on criterion 1, so I did not build it. Building it now would apply an unconfirmed rule, and card 0113 (which pins the 10% figure) is still in human-review, which this card's Links say to settle first.

To unblock: run this card in a session with web access (or have Rob paste Annex B's property-valuation paragraphs into docs/research/). The build is then small: PathProjector::careAssessableCapital (packages/finance-engine/src/Forecast/PathProjector.php, near line 2344) calls CapitalAssessment::propertyCapital on the WHOLE value and secured debt before the resident's share split, the test reads the answer through the care charge, and ScenarioForecaster::ENGINE_VERSION is bumped.

**2026-10-15** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same block. Criterion 1 needs the Care and Support Statutory Guidance, Annex B, read off the page. WebFetch (gov.uk) and WebSearch were both refused for permission again in this unattended session. No local copy exists: grep of docs/ for 'Annex B', 'expenses of sale', 'Charging for Care and Support' finds only this card, 0130, 0113, 0048 and the benefits-side ASSUMPTIONS entry. The card says the 10% is a recollection to confirm, not apply, so I did not record it from memory.

Criterion 2 is conditional on criterion 1 and was not built. Card 0113 (pins the 10% figure) is still in human-review, which the card's Links say to settle first.

To unblock: run this card in an attended session with web access, or have Rob paste Annex B's property-valuation paragraphs into docs/research/. Rerunning it unattended will block a third time. The build is then small: PathProjector::careAssessableCapital calls CapitalAssessment::propertyCapital on the WHOLE value and secured debt before the resident's share split, the test reads the answer through the care charge, and ScenarioForecaster::ENGINE_VERSION is bumped.

**2026-10-15** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-15 manager (m20261015123242-58f7)

**outcome: rob**

The work needs a page from gov.uk, and only an attended session or a person can get it.

**what the session said**

This card is stuck on a missing source document, not on the code. Neither take changed any code, so the code has no review finding to check against a criterion.

Both open criteria wait on the same document: the Care and Support Statutory Guidance, Annex B. Criterion 1 is a manual check of that text. Criterion 2 applies only if the text says the 10% costs-of-sale deduction applies. Unattended sessions cannot open web pages. I searched `docs/` and found no saved copy of Annex B. The only hits are the cards and the benefits-side entry in `docs/spec/ASSUMPTIONS.md`.

If I reopen the card, the builder blocks a third time. This is not a question for the owner either: the guidance is a fact to read, not a preference.

What Rob must do: run this card in a session that can open web pages, or put the Annex B paragraphs on property value into `docs/research/`. Then move the card back to `todo/`. Card 0113 sets the 10% figure and is still in `human-review/`, so settle 0113 first.

WHY: The work needs a page from gov.uk, and only an attended session or a person can get it.

OUTCOME: rob

