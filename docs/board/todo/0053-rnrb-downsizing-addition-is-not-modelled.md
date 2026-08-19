# Downsizing deletes the residence nil-rate band

## Why
From the expert panel, 2026-08-19 (estate planner finding 1). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`InheritanceTaxCalculator` caps the residence nil-rate band at the home passing to descendants, and
`PathProjector::recordFinalDeathIht()` supplies that as home equity **at the final death**.

So a sell-and-rent plan has no home at death and gets a band of nil. A sell-and-buy-cheaper plan is
capped at the cheaper home. There is no downsizing logic anywhere in the engine.

The downsizing addition exists in statute precisely to prevent this. Where a qualifying former
residence was disposed of on or after 8 July 2015, the lost proportion of the band is restored,
provided other assets of at least equal value pass to direct descendants.

This is the tool's own purpose. It exists to compare staying put against downsizing, and as coded
every downsizing option is penalised by tax the statute is written to prevent. For an estate near
the bands that is a six-figure error, pointing the wrong way.

## Not this card
Lifetime gifting, which is card 0059.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a household disposes of a qualifying residence and later dies owning a cheaper home or none, THE APP SHALL restore the lost residence nil-rate band as a downsizing addition.
- [ ] #2 THE APP SHALL cap the addition at the value of non-home assets passing to direct descendants.
- [ ] #3 THE APP SHALL apply the estate taper to the total band after the addition, not before.
<!-- AC:END -->

## Tasks
- [ ] Record the disposal value and date on projector state when a housing action sells
- [ ] Compute the lost percentage of the maximum band at disposal, apply it at death
- [ ] Cap at qualifying non-home assets; order the taper correctly
- [ ] Source the rule with `source` and `verified_on`
- [ ] Tests for sell-and-rent, sell-and-buy-cheaper and stay-put
