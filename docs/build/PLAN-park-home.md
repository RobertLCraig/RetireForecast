# PLAN: park homes as a housing option (a bought home that DEPRECIATES)

> A ready-to-build spec. A fresh agent should be able to execute this end to end. Read
> `docs/HANDOVER.md` first (orient), then this. Follows the project doc standard and the engine rules
> in `CLAUDE.md` (framework-free engine, integer pence, reconciliation invariants, completeness
> tests, tests green on every commit).

**Stage:** ✅ BUILT (2026-07-30) — kept as the build record. See DECISIONS 2026-07-30.

> **Built as specified.** Both gaps closed by two optional `HousingAction` fields
> (`buyRunningCosts`, `buyGrowthOverride`, the latter accepting NEGATIVE rates), a depreciation
> honesty note, and four scenarios (£150k Tring / £128k Wokingham, each with and without the £80k art
> sale). Guarded by `DepreciatingHomePurchaseTest` (the home must actually lose value; an explicit
> running cost must REPLACE the 1% default, not add to it; both-null is byte-identical).
>
> **Revised 2026-07-30 after researching the home's own upkeep** (the first pass modelled the pitch
> fee alone, which buys site upkeep, not maintenance of the home). At **£5,000/yr** rather than
> £3,000 the park home still leads, but only **with** the art sale:
>
> | Plan | Free to spend |
> |---|---|
> | Park home £128k Wokingham + £80k art sale | **£994/mo** |
> | Park home £150k Tring + £80k art sale | £930/mo |
> | *Sell & buy cheaper £165k* | *£865/mo* |
> | Park home £128k, **no** art sale | £693/mo |
>
> So: **with** the art sale the park home wins on spending (though sell-and-buy still leaves the
> larger estate, £303,506); **without** it, sell-and-buy-cheaper wins on both. £150k Tring cannot
> complete at all without the art sale — no mortgage is available on a park home and the £46,412 gap
> exceeds their savings.
**Owner lane:** housing options (same family as `PLAN-in-place-forced-sale.md` / `PLAN-multi-property.md`).
_Last updated: 2026-07-29 (scoped from research; no code changed)_

## Why this exists
Rob asked to consider buying a **park / holiday home** between **Wokingham and Tring** (the corridor
between two sets of family), funded from selling the flat, "and then going on a cruise / holiday every
year to make up the time difference".

The research below establishes that the idea splits into two very different things: one is **not legally
possible** as a housing plan, and the other is **the best-fitting option found so far** for this
household — but it buys solvency by consuming the estate, so it needs to be modelled honestly rather
than assumed good.

The engine cannot currently model it at all: **a bought home can only appreciate.**

## What the research established (2026-07-29)

### 1. The "holiday home + cruise" version is ruled out — do not re-propose it
A **holiday** park home **cannot be a main residence**. A 10/11-month licence confers no legal right to
live on site permanently, and the owner must be **registered at a separate address**. The annual cruise
does not solve this: the constraint is not the closed weeks, it is that the law requires another
permanent home — which this household would not have, having sold the flat.

It is also **not the cheaper option**: holiday site fees run **£3,250–£12,495/yr** versus residential
pitch fees of **£150–£300/mo (£1,800–£3,600/yr)**. More cost, less security, plus the cruise.

> Sources: [Park Home Magazine — thinking of living in a holiday home?](https://parkhomemagazine.co.uk/advice/thinking-of-living-in-a-holiday-home-think-again/) ·
> [Victory — can you live in a holiday home all year](https://www.victoryleisurehomes.co.uk/blog/tips-tricks/can-you-live-in-a-holiday-home-all-year) ·
> [Parkdean — site fees](https://www.parkdeanresorts.co.uk/caravans-for-sale/buyers-guide/pitch-fees/) ·
> [GOV.UK — park (mobile) homes: charges](https://www.gov.uk/park-mobile-homes/charges). verified_on 2026-07-29.

### 2. The RESIDENTIAL park home version is viable and in budget
A residential park home (Mobile Homes Act 1983) carries a **right of permanent residence** — full-time
home, no second address, no closed season, **no cruise needed**. Budget from a sale (engine-computed,
£208k mortgage redeemed, selling costs and CGT taken): **£110,210** at a £350k sale, **£130,440** at
£375k, **£150,670** at £400k.

Asking prices in the corridor (verified_on 2026-07-29):

| Wokingham end | | Tring end | |
|---|---|---|---|
| Windsor (retirement) | £115,000 | Coppice Farm Park, Tring | £150,000 |
| California Country Park, Finchampstead | £125,000–£130,000 | Chesham Rd, Wigginton, Tring | £156,000 |
| Peppard Rd, Emmer Green (over-45s) | £155,000 | Beech Park, Wigginton (over-50s) | £170,000 |
| Strande Park, Cookham | £165,000 | | |

Above budget but in-corridor, for context: Pine Copse, Crowthorne £280–290k; Warfield Park, Bracknell
£300–310k; Iver Park Estate £395k. **Both partners clear every age restriction seen (45+/50+/55+).**

> Sources: [OnTheMarket — park homes, Berkshire](https://www.onthemarket.com/for-sale/park-home/berkshire/) ·
> [OnTheMarket — park homes, Buckinghamshire](https://www.onthemarket.com/for-sale/park-home/buckinghamshire/).

### 3. Two things make it more than just "cheaper"
- **Running costs collapse.** The flat costs £6,685 service charge + £1,500 MG levy = **£8,185/yr**.
  A residential pitch fee is **£1,800–£3,600/yr** — a **£4,600–£6,400/yr saving**, on top of removing
  the £15,822/yr mortgage. For a household whose binding constraint is a survivor on ~£11.7k/yr, that
  is the right *shape* of fix, not just a cheaper number.
- **Pension Credit survives.** A park home owned and occupied is the main residence, so it is
  **disregarded as capital** — unlike selling and holding cash, which loses most of the ~£41k lifetime
  award to the tariff. ([Arden Parks](https://ardenparks.co.uk/can-you-claim-pension-credit-while-living-in-a-park-home/))

### 4. The catch, and why modelling it honestly matters
Park homes **depreciate** — reports of up to **90% of value lost over 10 years**, driven partly by
BS 3632 standard revisions every 8–10 years — and the site owner is entitled to **up to 10% commission
on resale** under the Mobile Homes Act 1983. So this option buys solvency by **spending the
inheritance**, in the same family as the lifetime mortgage. If the engine models the park home as
appreciating bricks (which is what it does today), the plan will look **strictly better than it is**.

> Sources: [Holiday Park Advice Centre — depreciation](https://holidayparkadvicecentre.co.uk/2025/04/04/park-home-depreciation-an-urgent-issue/) ·
> [Commons Library SN07003 — 10% commission](https://commonslibrary.parliament.uk/research-briefings/sn07003/).

### 5. The comparison this reframes
`Sell & buy cheaper £165k` (scenario 49) is currently the strongest plan — solvent for life, ~£303k
estate. But **£165k does not buy a house between Wokingham and Tring.** The park home is what that money
buys *near the family*. The decision is therefore not "park home vs £165k house" but **"is being near
the kids worth the estate?"** — which is a question for Rob, and the model's job is to price it, not
answer it.

## The engine gap
`HousingComparison::variantInputs()` builds the bought home at
[HousingComparison.php:222-228](../../packages/finance-engine/src/Housing/HousingComparison.php#L222-L228):

```php
$newProperty = new Property(
    currentValue: $outcome->buyPrice,
    ownership: $mortgaged ? OwnershipType::Mortgaged : OwnershipType::Outright,
    isPrimaryResidence: true,
    outstandingMortgage: $mortgaged ? $outcome->mortgage : null,
    runningCosts: $this->newHomeRunningCosts($household, $action, $outcome->buyPrice),
);
```

**Gap A — a bought home cannot depreciate.** No `growthAssumptionOverride` is passed, so the new home
always grows at the assumption-set house rate. `HousingAction` has no input for it either. Negative
rates are already valid on the builder side (`$rate = ['nullable', 'numeric']`, no floor) and the
projector already honours a per-property real rate — the value simply never reaches a *bought* home.

**Gap B — a bought home's running costs cannot be entered.** `newHomeRunningCosts()` either scales the
current home's running costs by `buyPrice / salePrice`, or falls back to **1% of value**. A pitch fee is
neither: it is a flat annual charge unrelated to the home's value. This household's flat has **empty**
`runningCosts` (its service charge sits in expense lines), so a £150k park home would default to
**£1,500/yr** against a real pitch fee of £1,800–£3,600 — understating it by up to £2,100/yr.

**Why an expense line is not the answer:** the buy and rent variants call
`ExpenseProfile::withoutPropertyCosts()`, which strips the `while_owning_home` bucket, so a pitch-fee
expense line would be removed on exactly the variant that needs it. `newHomeRunningCosts()` is the only
channel into a bought home's costs, so that is the channel to open.

## Design decision: no "park home" concept in the engine
Model it as **a bought home with a negative growth rate and an explicit running cost** — not as a new
property type. Rationale: it needs no new DTO or enum, it composes with everything already built
(Pension Credit disregard, IHT, care means test, forced sale), and the same two inputs also model a
short-lease leasehold flat or any other depreciating home. The "park home" lives in the scenario name
and the docs, not in the type system. This follows the existing `buyMortgageRate` precedent exactly.

## Changes

### `HousingAction` (engine DTO)
Two new optional fields, both null = today's behaviour:
| Field | Type | Notes |
|---|---|---|
| `buyRunningCosts` | `?Money` | annual running cost of the home being bought (a park-home pitch fee, or a known service charge). Null = the existing derived default (scale the current home's, else 1% of value). |
| `buyGrowthOverride` | `?Percent` | REAL annual growth of the home being bought; **may be negative** (a park home depreciates). Null = the assumption-set house-growth rate, as now. |

### `HousingComparison`
Pass both into `$newProperty`; `newHomeRunningCosts()` returns `$action->buyRunningCosts` when set,
before any derivation.

### Builder / assembler
`housing.buyRunningCosts` and `housing.buyGrowthReal` alongside the existing `housing.buyMortgageRate`
(step: the "if you sell and buy" section). Per [[new-builder-field-delta-gotcha]] **four things move
together**: `blankHousing()` defaults (both `''`), validation rules, `loadState()` backfill, and
`BuilderStateFixture::full()`. Both default empty, so **no existing what-if delta shifts**.

### Presenter
Extend the sale explainer / input notes with a depreciation note when `buyGrowthOverride` is negative —
state the modelled rate and what the home is worth at the end of the plan, the same honesty treatment
the lifetime-mortgage roll-up gets (`ResultPresenter::inputNotes` kind `lifetime_mortgage_rollup` is the
pattern to copy). **Do not** let a depreciating home be shown without it.

## Build order
1. `HousingAction` + `HousingComparison` (engine, with tests) — the gap that blocks everything.
2. Assembler + builder fields + fixture.
3. Presenter note.
4. Add the scenario(s) as delta-children of base 9 and record figures in the private V2 doc.

## Tests / guards
- **Completeness (the project's standing rule):** a negative `buyGrowthOverride` must demonstrably
  reach the forecast — the bought home's value must **fall** year on year, and the terminal estate must
  be lower than the same purchase without the override. A silently ignored override is the exact failure
  mode this rule exists to catch (cf. the dropped DLA income).
- `buyRunningCosts` reaches the projection and **replaces** the 1%-of-value default (assert the derived
  default is not also charged — no double count).
- Null on both = **byte-identical** to today for every existing scenario and stored run (the same
  back-compat guarantee `repaymentTerms` carries).
- Reconciliation: net worth still equals liquid + pension + home equity with a depreciating home.
- A park-home purchase at £150k with a £3,000 pitch fee produces a lower terminal estate but a later
  depletion year than the stay-put base — the trade-off the option exists to show.

## Resolved: depreciation, maintenance and pitch fees (researched 2026-07-29)

### Depreciation — Rob's instinct is half right, and the evidence is poor
Two different things get conflated and must be separated in the model and the copy:

- **Physical life is long.** A well-maintained BS 3632 residential park home lasts **70–80 years**;
  1960s homes are still occupied. It will *not* be a wreck by the end of the plan.
- **Market value still falls hard.** Age UK's Factsheet 71 (Feb 2026) is blunt: park homes "generally
  depreciate rather than appreciate… a poor long-term investment financially." A major driver is that
  **BS 3632 is revised every ~8–10 years**, making older but structurally sound homes technically
  obsolete and hard to resell — plus the **10% resale commission**.

**Evidence quality is weak and partisan, and the build must say so.** The severe figures ("up to 90%
of value over 10 years", ≈ -20%/yr) come from **campaigning sites** with an axe to grind; the benign
figures (**3–6%/yr** through mid-life, 50–60% retained at 10 years) come from **manufacturer/park
marketing** and partly from the **US** "park model" market, which is not the same product. There is no
neutral UK index.

**No neutral UK index exists — this was checked, not assumed.** The government's own park-homes research
([the 2022 DLUHC report](https://assets.publishing.service.gov.uk/media/62ab3d69d3bf7f0afecf6943/Park_Homes_Research_Report.pdf)
and the [sale-commission impact study](https://gov.uk/government/publications/mobile-homes-the-impact-of-a-change-in-the-maximum-park-home-sale-commission/the-impact-of-a-change-in-the-maximum-park-home-sale-commission-executive-summary))
is policy analysis, not valuation: it sizes the sector (100,406 park-home addresses, ~159,000 owner-occupier
residents in 2021) and models commission effects, but publishes **no price or resale-value series at all**.
So the rate below is a judgement between two biased sources, and is labelled as such wherever it is shown.

**DECIDED default: -8%/yr real, user-editable**, as the adverse-but-defensible midpoint per
[[adverse-default-user-editable]]. Over the plan's 23 years that leaves roughly:

| Rate | £150,000 in 2049 | £130,000 in 2049 | Reading |
|---|---|---|---|
| -3%/yr | ~£74,000 | ~£64,000 | benign (industry marketing) |
| -5%/yr | ~£46,000 | ~£40,000 | moderate |
| **-8%/yr (default)** | **~£22,000** | **~£19,000** | adverse midpoint |
| -15%/yr | ~£4,000 | ~£3,000 | severe (campaign sites) |

So at the default the home is worth **~15% of its price** by the end — close to Rob's "worth nothing
by the time they're done with it", without asserting a figure the evidence cannot carry. **Ship all
four as selectable sensitivities**, not just the default.

### Maintenance and running costs — the pitch fee is NOT the whole cost (researched 2026-07-30)
The pitch fee covers site maintenance and communal facilities **only**. The owner still pays utilities,
council tax, buildings insurance **and all maintenance of the home itself** — which is not small:

| Item | Cost | How often |
|---|---|---|
| Ongoing maintenance / incidentals | **£1,000–£2,000/yr** | annual (the recommended budget) |
| Exterior repainting | **£1,500–£2,500** | every **5–7 years** |
| Chassis clean + repaint | from **£1,200** | occasional |
| Chassis restoration if corroded | ~**£3,750** | occasional, condition-dependent |
| Underfloor insulation | **£1,800** single / **£2,400** double unit | one-off improvement |
| Re-roofing | ~**£10,000** (~£100/m²) | every **20–40 years** |

**So the modelled running cost is £5,000/yr, not £3,000** = £3,000 pitch fee + £1,500 ongoing
maintenance (mid of the range) + ~£350 amortised repainting. Re-roofing stays a separate **£10,000
one-off in 2041** — it lands in the survivor years, exactly the lump a fixed income cannot absorb.
Chassis work and underfloor insulation are **not modelled** (occasional and condition-dependent);
flag them as the reason to get a structural survey before buying.

**Utilities can be worse than a house, not better.** Many parks have **no mains gas**, so heating is
**LPG** — cylinders per pitch or a shared tank — which is materially dearer than natural gas, and the
site owner may resell it at a margin (case law limits the charge to the unit price the site owner
paid; the pitch agreement gives a right to an explanation of the charges). Water is usually metered
plus a share of the site standing charge. **Offsetting saving:** park homes are usually **council tax
band A**, the cheapest — so the V2 scenarios carrying the flat's £2,004 forward are conservative.

> Sources: [Park Home Magazine — are you aware of the costs?](https://parkhomemagazine.co.uk/home-improvements/are-you-aware-of-the-costs/) ·
> [Prestige — chassis refurbishment cost](https://www.prestigedevelopments.com/park-home-chassis-refurbishment-cost/) ·
> [Checkatrade — park home refurbishment costs 2026](https://www.checkatrade.com/blog/cost-guides/park-home-refurbishment-costs/) ·
> [LEASE — costs of living in a park home](https://parkhomes.lease-advice.org/pitch-fees-and-bills/costs/) ·
> [LEASE — limits on utility charges](https://parkhomes.lease-advice.org/pitch-fees-and-bills/utilities/utility-charges/limits/) ·
> [Tingdene — park home running costs](https://tingdene-parks.net/news-events/park-home-running-costs/). verified_on 2026-07-30.

### Pitch fees — and a correction to this plan's earlier draft
Typical **residential** pitch fees are **£150–£300/mo (£1,800–£3,600/yr)**; a documented current example
is **£157.23/mo**. **Proposed model figure: £3,000/yr**, the upper-middle of the range (South East).

**Correction — the earlier draft of this plan was wrong about escalation.** The **Mobile Homes (Pitch
Fees) Act 2023** (Royal Assent May 2023, in force **2 July 2023**) changed the statutory review
presumption from **RPI to CPI**, and expressly forbids a site owner recovering the RPI/CPI difference by
other means. Pitch fees are reviewed **annually** against **CPI**. **Therefore the engine's default
flat-real treatment is already correct** and no `propertyCostsRealGrowth` is needed — the limitation
previously flagged here **does not exist**. (Above-CPI drift remains possible via "agreed park
improvements"; note it in copy, do not model it.)

> Sources: [Age UK Factsheet 71 — Park homes (Feb 2026)](https://www.ageuk.org.uk/siteassets/documents/factsheets/fs71_park_homes_fcs.pdf) ·
> [LEASE — Mobile Homes (Pitch Fees) Act 2023 Q&A](https://parkhomes.lease-advice.org/article/mobile-homes-pitch-fees-act-2023-questions-and-answers/) ·
> [Park Home Magazine — pitch fees: RPI to CPI](https://parkhomemagazine.co.uk/news/pitch-fees-rpi-to-cpi-this-july/) ·
> [Checkatrade — park home refurbishment costs 2026](https://www.checkatrade.com/blog/cost-guides/park-home-refurbishment-costs/) ·
> [Quickmove — lifespan of a residential park home](https://www.quickmoveproperties.co.uk/what-is-the-lifespan-of-a-residential-park-home) ·
> [LEASE — buying a park home: things to consider](https://parkhomes.lease-advice.org/buying-a-park-home/things-to-consider/). verified_on 2026-07-29.

### Non-financial risks — put these in the scenario note, not the engine
- **No mortgage is available on a park home** (you own the structure, not the pitch; it is neither
  freehold nor leasehold). **It must be a cash purchase** — so if the sale price disappoints there is
  no borrowing lever to close the gap, unlike every other buy option modelled.
- **Site closure or sale to a new operator** can change rules and, at worst, force relocation.
- **Resale can be slow**; some parks vet the buyer, and age restrictions shrink the buyer pool.
- Residents have **fewer statutory protections** than freeholders.

## Scenarios to build (step 4)
Model **both** price points and compare (Rob's call — the extra capital may matter more than location):

| Child | Price | Pitch fee | Depreciation | Note |
|---|---|---|---|---|
| Park home — Tring £150k | £150,000 | £3,000/yr | -8%/yr real | Coppice Farm Park, near the Berkhamsted family |
| Park home — Wokingham £128k | £128,000 | £3,000/yr | -8%/yr real | California Country Park, Finchampstead; **£22k more stays invested** |

Both: `variant = buy_outright`, no mortgage (cash purchase — see risks), the £208k redeemed on sale,
plus a **£10,000 one-off re-roofing cost in 2041**. Then run the depreciation sensitivities above.

## Open questions for Rob
1. ~~Depreciation rate~~ — resolved above (-8%/yr default, four sensitivities shipped).
2. ~~Which price point~~ — resolved: **model both**.
3. ~~Pitch fee~~ — resolved (£3,000/yr).
4. **The holiday/cruise budget is an OUTPUT, not an input** (Rob, 2026-07-29): the point of the exercise
   is to find what they could afford to spend. That is a lever/threshold question, and it generalises
   beyond this option — see **[PLAN-spendable-view.md](PLAN-spendable-view.md)**, which specs the
   "available capital" and "monthly allowance" figures per year per scenario. **Build that first, or at
   least alongside**, or this option cannot answer the question it was raised to answer.

## Not in scope / known limitations to flag in the build
- **The 10% resale commission is not modelled.** It bites only on an actual resale (e.g. a forced sale
  to fund care), not on a hold-to-death plan. Add to DATA-MODEL "Known divergences" rather than build.
- **Above-CPI pitch-fee drift via "agreed park improvements"** — possible in reality, not modelled.
- **Site rules, park solvency and pitch-agreement risk** are qualitative and out of model scope. Put
  them in the scenario note (they are decision-relevant even though they are not numbers).
