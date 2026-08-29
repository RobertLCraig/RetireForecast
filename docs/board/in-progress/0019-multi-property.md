---
needs: 0029, 0030
---

# Hold more than one property

## Why
The tool models exactly one property — the main home the buy-vs-rent comparison sells or swaps. A
household that owns a **second** property, whether a buy-to-let, a holiday place or a flat inherited
and then let out, has nowhere to put it. The only lever is a bare "rental income" figure, which
records what the rent is and nothing else: not what the property is worth, not that its value grows,
not the mortgage on it, not the costs of letting it, not the money and the Capital Gains Tax bill
when it is sold, and not its place in the estate on death.

The cost is that both headline outputs are wrong for such a household, not slightly. Wealth is
understated by the whole value of the property, the estate is understated by the same amount, and a
plan that quietly depends on selling the second property shows none of that money arriving.

It came about because the tool was built around one couple who own one flat and live in it. The
second-property case was written up as a proposal in June 2026, marked DRAFT, and never settled.

## Links

**Blocked by**
- `0029` - a per-property growth override currently switches house-price risk off. An additional
  property would inherit that design and spread the defect rather than pick up the fix.
- `0030` - the letting-cost model (management, void, maintenance, the service charge as a deductible
  letting expense) is being built there. Building this first means writing it a second time.

**Relates to**
- `0021` - the let-to-let flat is the only rent the engine models today, and it is a standalone
  income stream. That is what the "rent has exactly one source" rule below has to keep working.
- `0027`, `0032` - the sale friction and leasehold selling costs a disposal here reuses, rather than
  re-deriving them for a second kind of property.

## Not this card
- Phase 2 and Phase 3 of the plan: selling a property to fund retirement, means-tested capital and
  care treatment, buying a property mid-forecast, SDLT surcharge, the property allowance, loss
  carry-forward, refinancing.
- The letting-cost model itself (card 0030), sale friction (0027), leasehold selling costs (0032) and
  the growth-override semantics (0029). Read them; do not redeclare them here.
- Unifying `primaryResidence` into the new list. Settled against: the main home keeps its own slot.

## Acceptance
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

## Tasks
- [x] Take the plan out of DRAFT
- [x] Re-scope this card from the settled plan
- [ ] Add the three let-only fields to `Dto\Property`, with `isLet` as their discriminator
- [ ] Add `additionalProperties` to `Dto\Household`, carried through `copy()` **and**
      `HousingComparison::withHousing()`
- [ ] Grow, rent, cost and dispose of each additional property in `PathProjector`
- [ ] Sum them into the IHT estate, excluded from the residence nil-rate band
- [ ] Add the `properties` list to the builder, the assembler and `BuilderStateFixture::full`
- [ ] Show them in the wealth breakdown, income-by-source and a per-property sale waterfall
- [ ] Add the rent-overlap check to `php artisan scenarios:audit`

## Plan
Read [docs/build/PLAN-multi-property.md](../../build/PLAN-multi-property.md) in full first. It is
settled, it carries the touch-point map, and its "What changed since the draft" table lists four
things an older reading of this feature gets wrong.

Stand in the RetireForecast repository root. Run `php artisan test` from PowerShell (PHP is Laravel
Herd and is not on the Git Bash PATH), and `php artisan scenarios:audit` before and after. It worked
when the suite is green, the audit exits zero, and a scenario with a second property shows that
property's value in the wealth breakdown and its rent in income-by-source.

## Comments

**2026-08-29** Settled the plan and re-scoped this card off it; wrote no code, which criterion #1
requires. Answered all five of the plan's open questions from the repository rather than sending
them up, per `docs/board/README.md`: extend `Property` rather than add an `InvestmentProperty` DTO
(a second DTO would hold a second copy of the mortgage exclusivity invariant); keep `primaryResidence`
in its own slot (43 references across 11 files, and the projector's property state is scalar
throughout); an unsold second property counts in total wealth but never in usable wealth (this is
already how the main home is treated, and it is the more adverse of the plausible readings); keep the
standalone `rental` income stream and make an overlap visible rather than retire it (retiring it
would break the let-to-let scenario, whose rent is a standalone stream and is the base of the
Section 24 finance-cost reducer); and stop at Phase 1.

The draft had gone stale in four ways that all make the feature smaller, and the plan now carries a
table of them: Section 24 is already modelled, `Property` already has all three mortgage shapes
including amortising, `isLet` already exists, and cards 0027 to 0032 are building the letting-cost
and sale machinery this would otherwise write twice. That last point is why this card now carries
`needs: 0029, 0030`.

**One thing I could not settle from the repository, and it decides whether this gets built at all.**
The plan's motivating case is "a property inherited and then let out". Nothing tracked in this
repository records such a property. What is recorded is a household that owns one flat, lives in it,
and has a buy-to-let mortgage on it (DECISIONS 2026-06-30). The private captures that would say
otherwise are gitignored and are not in this worktree, so I could not check them. **Is there a second
property to model, now or expected?** If not, discard this card: the plan file stays as the record of
why the design landed where it did, and costs nothing to leave there.

Nothing here has been looked at in a browser, and there is nothing to look at yet.
