---
needs: 0029, 0030
---

# Is there a second property to model?

## What I need from you
Does the household own, or expect to own, a second property (for example a buy-to-let, or an inherited flat that is let out)? Yes or no.
- **Yes:** the card is built once cards 0029 and 0030 pass review.
- **No:** the card is discarded. The design stays on file and costs nothing.

**My recommendation:** say no unless there really is one. Nothing recorded shows a second property, and this is a large build.
Paste to answer: `**2026-10-07** **Decided:** No second property. Discard.` or `**2026-10-07** **Decided:** Yes, a second property: <what it is>.`

## What you need to know
- The tool models one home: the flat the couple live in. A second property has nowhere to go except a bare "rental income" figure.
- If one exists, wealth and the estate are both understated by its whole value, and any plan that sells it shows no money arriving.
- The build is about eight pieces of work: value, rent, letting costs, sale, tax and estate handling for each extra property.
- The loop tried it twice on 2026-10-05 and stopped both times on this one question.

## See it
- The settled design: `C:\Dev\RetireForecast\docs\build\PLAN-multi-property.md`

---
## For the agent (Rob can stop reading here)
Yes: re-queue to `todo/` after 0029 and 0030 pass review, then build to the plan (read it in full first; its "What changed since the draft" table matters). Run `php artisan test` and `php artisan scenarios:audit` from PowerShell before and after. No: `git mv` to `discarded/`; the plan file stays as the record.

<!-- AC:BEGIN -->
- [x] #1 THE PLAN at docs/build/PLAN-multi-property.md SHALL leave DRAFT status with its open
      scope questions answered, before any code is written against it.
- [ ] #2 WHEN a household holds additional properties, THE APP SHALL count each one's value in total
      wealth and in the Inheritance Tax estate, and SHALL grant no residence nil-rate band on them.
      proves: `test_an_additional_property_counts_in_total_wealth_and_the_iht_estate_without_rnrb`
- [ ] #3 WHEN an additional property is let, THE APP SHALL tax its net rent through its owner's
      income-tax pass exactly once.
      proves: `test_an_additional_propertys_net_rent_is_taxed_once_through_its_owner`
- [ ] #4 WHEN a scenario carries both property-linked rent and a standalone rental income stream,
      THE APP SHALL report the overlap rather than silently summing both.
      proves: `test_rent_entered_on_a_property_and_as_a_standalone_stream_is_reported`
- [ ] #5 WHEN an additional property reaches its planned disposal year, THE APP SHALL add value less
      mortgage, selling costs and Capital Gains Tax to liquid wealth, and SHALL remove it from
      property wealth.
      proves: `test_an_additional_propertys_disposal_proceeds_reconcile_into_liquid_wealth`
- [ ] #6 WHILE an additional property is unsold, THE APP SHALL count it in total wealth and never in
      usable wealth. proves: `test_an_unsold_additional_property_is_excluded_from_usable_wealth`
- [ ] #7 WHEN the buy-vs-rent comparison builds its stay-put, buy and rent variants, THE APP SHALL
      carry the additional properties through all three unchanged.
      proves: `test_additional_properties_survive_every_housing_variant`
- [ ] #8 THE APP SHALL prove the above against a household holding a main home, an inherited let and
      a mortgaged buy-to-let, not a synthetic single-property happy path.
      proves: `test_a_residence_an_inherited_let_and_a_mortgaged_btl_forecast_together`
<!-- AC:END -->

Tasks: let-only fields on `Dto\Property` (`isLet` discriminator); `additionalProperties` on `Dto\Household` carried through `copy()` and `HousingComparison::withHousing()`; grow, rent, cost and dispose in `PathProjector`; IHT estate without RNRB; builder, assembler and `BuilderStateFixture::full`; wealth breakdown, income-by-source and a per-property sale waterfall; rent-overlap check in `scenarios:audit`.

## Comments

**2026-08-29** Settled the plan and re-scoped this card off it; wrote no code. Extend `Property`
rather than add a new DTO; keep `primaryResidence` in its own slot; an unsold second property counts
in total wealth but never in usable wealth; keep the standalone `rental` stream and report an
overlap; stop at Phase 1. Nothing tracked records a second property; the private captures that might
are gitignored.

**2026-10-05** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 7 of 8 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-07 manager (m20261007033904-b3b7)

**outcome: question**

The card is a yes/no choice about the household's own assets, and the repository cannot answer it.

**The question:** Does the household own, or expect to own, a second property, such as a buy-to-let or an inherited flat that is let out? If yes, what is it?

**what the session said**

The loop parked this card because it needs one fact from you. It cannot build until you answer.

The only "BTL" (buy-to-let) notes I found are in `docs/REVIEW-PANEL-2026-08-19.local.md`. They seem to be about a mortgage on the flat you live in, not about a second property. No file shows a second property. Only you know if one exists, or if you expect to inherit one.

QUESTION: Does the household own, or expect to own, a second property, such as a buy-to-let or an inherited flat that is let out? If yes, what is it?

WHY: The card is a yes/no choice about the household's own assets, and the repository cannot answer it.

OUTCOME: question

