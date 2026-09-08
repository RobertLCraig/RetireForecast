# Economic assumptions — SIGNED OFF 2026-06-24

_Last updated: 2026-09-08 (§33: the asset mix is an input, and each asset class carries its own sourcing)_

> **Status: SIGNED OFF by Rob (2026-06-24), adopted as proposed.** Set A (FCA default) is the
> engine default; Sets B and C ship as runtime compare overlays. Re-verification against source
> is a **manual review** tracked as an open item in HANDOVER (the sets sit at their 2026-06-24
> sign-off; `AssumptionSetLibrary` carries a prose `sourceNote`, not per-figure URLs/dates —
> `figures:freshness` covers statutory tax figures only, `mortality:refresh` covers the ONS grid).
> See [DECISIONS.md](../DECISIONS.md).

## Runtime editability (2026-06-29 / 2026-07-02)
The signed-off presets are the sourced **baseline**, no longer the only figures a forecast can use:
- **User-derived custom sets (2026-06-29; care cost growth added 2026-07-18):** the seven economic
  assumptions (investment growth, inflation, house growth, rent growth, salary growth, income yield,
  care cost growth) are editable on builder step 1, stored as a sparse `assumptionOverrides` delta on
  the chosen preset (empty = the preset, so a re-sourced preset flows through). Applied once in
  `ScenarioForecaster::assumptions()`; the results panel labels a tuned set **(customised)** and marks
  each user-set figure.
- **Per-asset overrides (2026-07-02):** `DcPension::growthAssumptionOverride`,
  `Property::growthAssumptionOverride` and `Account::yield` let an individual asset depart from
  the set's figure (wired + completeness-tested — DECISIONS 2026-07-02).

All return and volatility figures are **REAL (above-inflation), annual**. Three asset
classes: global equities, gilts/bonds, cash. The engine reads whichever `AssumptionSet`
it is handed; a simulation snapshots the set it used so results stay reproducible.

## Why FCA returns but DMS volatilities
The FCA prescribes only central/mean projection rates (the 2% / 5% / 8% nominal lower/
intermediate/higher set, intermediate capped at 5%) and **publishes no volatilities** — yet
a Monte Carlo needs volatility and correlation. So the default set takes FCA-derived
expected returns and borrows volatilities/correlations from the Barclays Equity Gilt Study /
Dimson-Marsh-Staunton (DMS) long-run record. FCA real returns are derived from the FCA's own
asset-class nominal midpoints (equities 6.5%, gilts 2.0%, cash 1.5% nominal) deflated by the
Handbook's 2.0% inflation: `real = (1+nominal)/1.02 − 1` → equities **+4.4%**, bonds **0.0%**,
cash **−0.5%**.

## The shipped sets (signed off 2026-06-24; live in `AssumptionSetLibrary`)

| Field | Set A — FCA default (recommended) | Set B — DMS historical | Set C — OBR/BoE inflation-anchored |
|---|---|---|---|
| Equity real return | 4.4% | 5.2% | 4.4% |
| Bond real return | 0.0% | 1.5% | 0.0% |
| Cash real return | −0.5% | 0.5% | −0.5% |
| Equity volatility | 23% | 23% | 23% |
| Bond volatility | 13% | 13% | 13% |
| Cash volatility | 2% | 7.5% | 2% |
| Eq–Bond correlation | 0.30 | 0.46 | 0.30 |
| Eq–Cash correlation | 0.10 | 0.10 | 0.10 |
| Bond–Cash correlation | 0.30 | 0.30 | 0.30 |
| Inflation mean | 2.0% | 3.0% | 2.0% |
| Inflation volatility | 1.5% | 4.0% | 1.0% |
| House growth (real) | 1.0% | 2.5% | 1.0% |
| House growth volatility (real, **index**) | 9% | 11% | 9% |
| Single-property volatility (real, derived = index × 2.0, §13) | 18% | 22% | 18% |
| House–equity correlation | 0.20 | 0.20 | 0.20 |
| Rent inflation (real) | 0.5% | 0.5% | 0.0% |
| Salary growth (real) | 1.0% | 1.5% | 1.0% |
| Salary growth volatility (real) | 2.0% | 2.5% | 2.0% |
| Salary–equity correlation | 0.10 | 0.10 | 0.10 |
| Investment income yield (nominal) | 2.0% | 2.0% | 2.0% |
| Care cost growth (real, above CPI) | 2.0% | 2.0% | 2.0% |
| Investment charges (a year, on invested balances) | 0.50% | 0.50% | 0.50% |

## Judgement calls flagged for Rob (these are the bits to sanity-check)
1. **Cash real volatility set to 2%, not DMS's 7.5%.** DMS's 7.5% is mostly historical
   inflation shocks (1900–1980). Since the model shocks inflation separately, using 7.5%
   would double-count inflation risk on cash. Set A/C use 2%; Set B keeps the pure-DMS 7.5%
   to represent the full historical experience. **Confirm you're happy with the 2% override.**
2. **Eq–Cash (0.10) and Bond–Cash (0.30) correlations are reasoned estimates**, not directly
   cited point figures (DMS/Barclays publish the equity–bond correlation, not these).
3. **House price growth +1% real is the cautious end** of a wide range (long-run UK real
   house growth estimates run ~1% to ~3%). Set B uses the historical +2.5%.
4. **Set A equity 4.4% is deliberately below long-run history (5.2%)** because the FCA caps
   the central equity rate — a "don't over-promise" default.
5. **Investment income yield 2.0% nominal (uniform across sets, added 2026-06-27 for A5):** the
   slice of a GIA's total return paid as taxable income (dividends/interest), anchored to the
   global-equity dividend yield (~1.3–2%) — a modelling assumption, not a cited point figure.
   Reviewed and kept 2026-06-27.
6. **House-price growth is now stochastic in the Monte Carlo (added 2026-07-18).** REAL house-price
   volatility **9%** a year (Set B's long-run **11%** spans the volatile mid-century + 1970s–2000s
   cycles) — roughly half of equities' ~20%, matching the long-run finding that housing is far less
   volatile than equities. Modelled as a single **house–equity correlation of 0.20** (low positive)
   rather than a full extra matrix row: the deterministic central projection is unchanged (it uses
   the mean), but the fan now widens with the home's value. The **low** correlation is the
   load-bearing choice — it is *why* selling and investing the proceeds diversifies concentrated
   housing risk, so the sell-and-rent option carries different risk from stay-put/buy. **Confirm
   you're happy with 9%/11% vol and the 0.20 correlation** (both tunable per set).
7. **Salary growth is now stochastic in the Monte Carlo too (added 2026-07-18).** A still-working
   household's future pay rises (and the savings/pension contributions the surplus funds) are
   uncertain, so REAL salary growth now draws a per-year shock. Volatility **2.0%** real (Set B's
   long-run **2.5%** spans the volatile 1970s–80s real-wage swings): aggregate real earnings growth
   is far smoother than markets — about half the volatility of GDP growth (SF Fed) and near-acyclical
   once workforce composition nets out. Correlated to equities at a deliberately **low 0.10** (weaker
   than housing's 0.20), reflecting that near-acyclicality; the contemporaneous GDP-growth/equity-return
   link is close to zero. The deterministic central projection is unchanged (it uses the mean); the
   effect is narrower than housing (it only bites for the working years of a still-earning person), but
   it stops a working couple's accumulation looking artificially certain. **Confirm you're happy with
   2.0%/2.5% vol and the 0.10 correlation** (both tunable per set, and opt-in: a null volatility keeps
   salary deterministic, so every pre-existing stored run reproduces unchanged).
8. **Care fees escalate at CPI + 2% real (added 2026-07-18; verified_on 2026-07-18).** The engine draws
   one CPI series and models most costs as a real spread over it; care was the exception left riding flat
   CPI, which understated the tool's headline late-life risk. Care-home fees are ~60–75% staff cost pinned
   to the **National Living Wage**, which government ratchets deliberately above prices, and PSSRU/LSE and
   OBR long-term social-care projections escalate care unit costs on **earnings/productivity (~2% real above
   CPI)**, not CPI. The recent ~10%/yr run-rate (≈CPI+4–5%) is an NLW + employer-NI spike, **not** a standing
   assumption; the defensible standing range is 1.5–3% real. Per the adverse-default rule the shipped value
   is the **most adverse of the plausible standing values, CPI + 2%** (a time-limited "care shock" at CPI+4–5%
   remains an unbuilt option — see docs/PLAN-output-inflation-and-charts.md A1). Applies to the sampled
   self-funder fee, compounded to the year the (late-life) spell falls, mirroring the property-costs bucket.
   **Opt-in / null-safe:** a null rate keeps care flat-real, so every pre-existing stored care run reproduces
   unchanged. User-editable per scenario. **Confirm you're happy with CPI + 2% real.**
9. **Deterministic care-stress parameters (A2, added 2026-07-18; verified_on 2026-07-18).** The "What you can
   afford" screen shows, beside each care-free verdict, an "if significant care is needed" stress:
   **one 4-year nursing spell at £1,800/wk (self-funder)** on the **last-surviving partner**, ending at their
   representative death age, means-tested and CPI+2%-escalated. Per the adverse-default rule these are the
   adverse-but-defensible end: £1,800/wk is LaingBuisson's top-decile / dementia-nursing figure (national
   average is ~£1,600); 4 years is the upper tail (PSSRU mean ~2.5 yr, ~27% of stays exceed 3 yr). **One
   spell on the last survivor, not both partners':** a single significant spell still discriminates a strong
   plan from a weak one, where a both-partners worst case would sink every plan and tell the reader nothing;
   the last survivor is the adverse means-test position (alone → the home is assessable, no partner income to
   share the cost). **These are fixed for now — a per-scenario editor (fee / duration / onset) and a
   probability-weighted "typical" alternative are flagged refinements. Confirm you're happy with the £1,800/wk
   × 4-year single-spell stress.**
10. **Investment charges 0.50% a year (uniform across sets, added 2026-07-31; verified_on 2026-07-31).** Every
   return figure above is **gross of charges** — the FCA COBS 13 basis quotes returns before costs and expects
   charges to be deducted separately — so until now the household was modelled as holding its portfolio for
   free. Cost is the most reliably predictable drag in the whole model: more certain than any return
   assumption, and it compounds every year in the *reassuring* direction. The charge covers the platform /
   administration fee plus the funds' ongoing charges (OCF), and is deducted from **invested** balances only
   (DC pots, ISAs, GIAs). **Cash deposits bear none** — a bank account has no platform or fund fee — and
   neither does the home.
   **Why 0.50%, and why not the most adverse figure.** The evidence: UK workplace DC default arrangements
   averaged **0.48%** member-borne (DWP Pension Charges Survey 2020; non-qualifying schemes 0.53%), with a
   median AMC of **0.28%** on providers' largest default funds (DWP Pension Provider Survey 2024/25, published
   21 Jul 2025); the statutory **0.75%** charge cap binds auto-enrolment defaults but **not** decumulation, so
   it is an upper anchor rather than a ceiling for a retired household; retail DIY runs ~**0.30–0.60%** all-in
   (platform 0.15–0.35%, fund OCF ~0.15–0.25%). 0.50% sits just on the adverse side of that central case.
   This is a **deliberate departure from the usual most-adverse default**: the charge falls on invested wealth
   and not on housing, so it moves the sell-and-invest plans against the stay-put ones. An over-adverse figure
   would therefore be a thumb on the scale of the very comparison the tool exists to make, not a safe margin.
   **Opt-in / null-safe:** a null charge keeps returns gross, so every pre-existing stored run reproduces
   unchanged. User-editable per scenario (the 8th economic assumption). **Confirm you're happy with 0.50%** —
   and note that an *advised* household pays this plus an ongoing advice fee (~0.83%), which is the separate
   cost-of-advice comparison (§11), not modelled here.
11. **Ongoing adviser fee 0.83% a year — a COMPARISON figure, not an assumption (added 2026-07-31;
   verified_on 2026-07-31; lives in `config/advice.php`, not in `AssumptionSet`).** It drives one thing: the
   results page's "what paying for advice would cost" panel (adviser-parity B1), which re-runs the same plan
   with this fee **added on top of** the §10 charge and reports the difference in lifetime pounds, in terminal
   wealth, and in the year the money runs out. **The forecast itself never charges it.** It is deliberately
   outside the assumption set: an assumption set says what the projection assumes about the world, and putting
   an advice fee there would make it look as though the plan were paying one.
   **Why 0.83%, and why nothing else is added.** NextWealth's *Fee Benchmarking Report 2026* (published
   12 Mar 2026; data requests to large UK advice firms plus surveys of 545 advisers and 261 clients) puts the
   average ongoing advice fee at **83bp, up from 77bp in 2025**. That figure is confirmed on NextWealth's own
   page and in two trade reports. A widely-quoted **~180bp "total cost of ownership"** (advice + platform +
   fund) from the same report could **not** be verified against a primary or fetchable secondary source, so it
   is **not shipped**: the advised side is the household's own charge plus the advice fee and nothing else.
   That is the honest construction — the fee is the one component that can be benchmarked, whereas how much
   dearer an advised fund choice is varies far too much between an in-house model portfolio and a
   whole-of-market tracker to assume for a particular household. Building the advised total out of an assumed
   fund uplift would be inventing the larger half of the number.
   **Not modelled:** a one-off / initial advice charge (commonly £1,500–£4,000, or a percentage of the amount
   invested) is charged on top and is stated as unmodelled on the panel. User-editable per scenario — the
   benchmark average is a starting figure and a real quote is better.
12. **Home-ownership costs escalate at CPI + 3% real when no rate is entered (added 2026-09-05;
   verified_on 2026-08-19; lives in `ExpenseProfile::DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS`).** It applies
   to the `while_owning_home` spend bucket only: service charge, ground rent and block levies. Until now a
   blank input meant "rises with CPI", which is the one shape the evidence rules out. The three largest
   components of a block service charge have each compounded faster than prices since 2019: buildings
   insurance (post-Grenfell risk repricing), building-safety compliance (surveys, waking watch, remediation,
   the new regulatory regime), and the communal energy a charge covering water and lighting buys. Over a
   long projection the gap against a CPI escalator is thousands a year of real spend, concentrated in the
   survivor years, which is enough to change which housing plan ranks first.
   **Why 3%.** Per the adverse-default rule the shipped value is the cautious end of the reviewed range:
   CPI + 3% real central, CPI + 1.5% real as the optimistic sensitivity. User-editable per scenario (builder
   step 4), with both figures on the input, and disclosed as an assumed figure on the results page reading
   the constant. An explicit rate, **including an explicit zero**, always wins.
   **⚠️ SOURCING GAP, the first in this document.** The figures are the judgement of the
   property reviewer in the five-discipline expert review of **2026-08-19** (gitignored
   `docs/REVIEW-PANEL-2026-08-19.local.md`; board card 0028). That is a reviewer's opinion, **not a published
   series**. Every other figure above cites a primary or fetchable secondary source; this one does not.
   Board card **0085** carries the work of pinning it to a published statistic (ONS/Hometrack service-charge
   series, ABI buildings-insurance premium data, or an equivalent), which the unattended build loop cannot do
   because it has no web access. **Confirm you are happy with CPI + 3% until that lands.**
13. **One home is modelled over DOUBLE the index house-price volatility (added 2026-09-05;
   verified_on 2026-08-19; lives in `AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE`).** The 9% / 11%
   in the table above is an INDEX figure: it is what a whole market does on average, so the part of the
   risk belonging to one particular home has already been averaged out of it. A household whose net worth
   is one flat is not exposed to index risk. Their flat is re-rated by its block, its lease, its street
   and its condition while the index does nothing, and none of that diversifies away when you own exactly
   one of them. So the sampled index shock is scaled by **2.0** at the point the home consumes it, giving
   an effective **18%** (Set B **22%**) real spread on the primary residence. The central projection is
   unchanged: this widens the fan, it does not move the mean.
   **The companion change is that a per-property growth override now sets the MEAN and keeps the
   variation** (card 0029). It used to replace the sampled path outright, which made every overridden home
   a straight line, and overriding is exactly how somebody says a home is unusual: a park home, a flat in
   a slow block. The homes that most needed a range of outcomes were the ones being given a point estimate.
   User-editable per scenario (builder step 1, "Your home's price swing"), disclosed as an assumed figure
   on the results page reading the constant, and shown beside the index figure in the assumptions panel so
   the two can never be read for each other. An explicit figure always wins.
   **⚠️ SOURCING GAP, the second in this document.** "Roughly double" is the judgement of the property
   reviewer in the five-discipline expert review of **2026-08-19** (gitignored
   `docs/REVIEW-PANEL-2026-08-19.local.md`; board card 0029). It is directionally the standard finding in
   the repeat-sales literature, where idiosyncratic variance dominates index variance, but **no primary
   source was fetched for the multiple itself**, because the unattended build loop has no web access.
   Board card **0086** carries pinning it to a published estimate of UK single-property dispersion.
   **Confirm you are happy with 2.0x until that lands.**
14. **Letting a property costs 25% of gross rent when no rates are entered (added 2026-09-05;
   verified_on 2026-08-19; lives in `Property::DEFAULT_LETTING_MANAGEMENT_BPS` and its two siblings).**
   Three rates, applied only to a home flagged as let: **12% management** (a fully managed agent
   service, VAT included), **8% void** (the weeks it stands empty between tenancies, about a month a
   year on a single property), and **5% maintenance** (repairs, the inventory, the annual gas safety
   certificate and the five-yearly electrical report). Gross rent is the one figure a landlord never
   receives, and until now the model took it gross. The void is lost rent rather than a bill, but the
   arithmetic is the same, so all three come off together.
   **Why it matters more than its size suggests.** Leaving these out does not merely flatter a
   let-to-let plan, it can invert its sign: on the reviewed real case, a quarter of gross rent plus the
   service charge turned a modelled positive contribution into a real cash loss. The same change makes
   the Section 24 finance-cost credit read off rental PROFIT rather than gross rent, which is what the
   statute says, so a mortgaged let is no longer relieved on rent it never kept.
   **Why these three values.** Per the adverse-default rule the shipped figures are the cautious end of
   the reviewed range. Each is user-editable per scenario (builder step 3, shown once the home is
   flagged as let) and disclosed as an assumed figure on the results page reading the constant. An
   explicit rate, **including an explicit zero**, always wins, so a landlord who self-manages sets
   management to nil.
   **⚠️ SOURCING GAP, the third in this document.** The three figures are the judgement of the property
   reviewer in the five-discipline expert review of **2026-08-19** (gitignored
   `docs/REVIEW-PANEL-2026-08-19.local.md`; board card 0030). They sit squarely inside the ranges the
   letting industry quotes, but **no primary source was fetched**, because the unattended build loop has
   no web access. Board card **0087** carries pinning them to published data (a letting-agent fee survey,
   an ARLA/Propertymark void statistic, and a landlord repairs-cost series).
   **Confirm you are happy with 12% / 8% / 5% until that lands.**
15. **A tenancy has to be GRANTED as well as afforded (added 2026-09-05; lives in
   `Housing\Tenancy`).** Four figures, all applying to a sell-and-rent plan only:
   **30x the monthly rent** is the gross ANNUAL income a standard tenant reference asks for
   (`REFERENCING_INCOME_MULTIPLE`); **36x** is the higher bar a homeowner guarantor is referenced at
   (`GUARANTOR_INCOME_MULTIPLE`); **6 to 12 months** is the rent in advance a landlord asks of an
   applicant who fails (`ADVANCE_MONTHS_MIN` / `MAX`); and the **deposit** is capped at **5 weeks'
   rent**, or **6 weeks** at an annual rent of £50,000 or more.
   **Why it matters.** Referencing is an INCOME test and takes no account of capital, so a retired
   household sitting on the whole proceeds of a sale can fail it outright however well the
   money-lasts projection reads. That is a wall, not a cost, and the forecast could not see it. Only
   the deposit changes a projected figure (it is charged as a year-0 one-off); the referencing flag
   changes no number, it states a condition on the plan. The first month's rent in advance is
   deliberately **not** charged on top: a year of a monthly-in-advance tenancy is twelve payments and
   the rent line already charges twelve, so the disclosure names the day-one cash instead.
   **The deposit cap is statute** and is not part of the gap below: Tenant Fees Act 2019 c.4,
   Schedule 1 paragraph 2, verified_on 2026-09-05.
   **⚠️ SOURCING GAP, the fourth in this document,** and it covers the three multiples only. 30x, 36x
   and 6-to-12 months are the judgement of the property reviewer in the five-discipline expert review
   of **2026-08-19** (gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`; board card 0031). They match
   common UK referencing practice, but **no primary source was fetched**, because the unattended build
   loop has no web access. Board card **0091** carries pinning them to the referencing providers' own
   published criteria. **Confirm you are happy with 30x / 36x / 6-12 months until that lands.**
16. **Selling a home costs 4% of the sale price all in, and a taxable disposal costs £750 more
   (added 2026-09-05; verified_on 2026-08-19; lives in
   `HousingProceeds::DEFAULT_SELLING_COST_RATE_BP` and `HousingProceeds::CGT_RETURN_FEE_PENCE`).**
   The 4% is the catch-all the engine charges when the reader itemises nothing. It was **2%**,
   which pays an estate agent and very little else: a freehold-house figure. A leasehold flat also
   pays for a **management pack** (the LPE1 and the landlord's questionnaire, which the buyer's
   solicitor cannot exchange without), a **licence to assign** where the lease needs the landlord's
   consent, and **notice of transfer / notice of charge / deed of covenant** fees the lease charges
   on a sale. Its conveyancing is dearer for the same reason, and the household still has to move.
   The builder ships the itemised version of the same thing (agent 1.5%, leasehold conveyancing
   £2,000, management pack £500, licence and notices £700, removals £1,200, EPC £80), every line
   editable, and an itemised set always wins over the rate.
   **Why it matters.** Selling costs come straight off the NET PROCEEDS, and the net proceeds are
   what the whole buy-versus-rent comparison is built on. Understating them by half flatters every
   plan that sells, by real money, in exactly the comparison the tool exists to make.
   **The £750 is separate and conditional.** A UK residential disposal on which capital gains tax is
   actually due must be reported and paid within **60 days** on its own return, and an accountant
   prepares it. It is charged only when the sale owes CGT, it is itemised on the sale waterfall, and
   it is deliberately **not** deducted from the gain: the cost of computing a tax is not an
   incidental cost of disposal (TCGA 1992 s.38), and excluding it is also what stops the charge
   being circular, since the tax is what decides whether the fee applies at all.
   **There is no tenure field to switch the leasehold lines on**, so per the adverse-default rule
   they ship with figures and a freeholder clears the two that do not apply (a blank line costs
   nothing, and the builder says so). A tenure flag belongs to board card 0026; the gap is card 0093.
   **⚠️ SOURCING GAP, the fifth in this document.** "Nearer 4%" is the judgement of the property
   reviewer in the five-discipline expert review of **2026-08-19** (gitignored
   `docs/REVIEW-PANEL-2026-08-19.local.md`; board card 0032). The itemised figures and the £750
   accountant's fee are this build's own reading of ordinary UK practice, and **no primary source
   was fetched for any of them**, because the unattended build loop has no web access. The 60-day
   deadline itself is statute and is not part of the gap. Board card **0092** carries pinning the
   money figures to published data. **Confirm you are happy with 4% and £750 until that lands.**
17. **The upkeep of a home you would BUY is 1% of its value a year (in use since 2026-07-08;
   verified_on 2026-07-08; lives in `HousingComparison::HOME_MAINTENANCE_RATE_BPS`).** It applies
   only where the reader entered no running cost for the purchase AND the current home has none of
   its own to scale, which is the usual case when the current home is a leasehold flat whose
   maintenance sat inside its service charge. The figure is the widely-quoted UK rule of thumb
   (Checkatrade's 2023 survey put average homeowner maintenance at about 1% of property value a
   year, with older stock at 1.5% to 4%), so 1% is the cautious end of that range.
   **The open question is the BASIS, not the number** (board card 0033). A roof, a boiler and a
   rewire cost about the same in a cheap area as an expensive one, so a percentage of value is a
   proxy for the quality of the stock rather than a driver of the cost, and it understates upkeep
   at the low end, which is exactly where a downsizing purchase sits. A flat annual figure by
   property type and age is the alternative basis, and choosing one needs a published maintenance
   series this session could not fetch. Board card **0094** carries that comparison.
   Documented in the meantime rather than changed: it is disclosed on the results page as an
   assumed figure reading the constant, and an entered running cost always wins.
   **Confirm you are happy with 1% of value until that lands.**
18. **How a defined-benefit pension increases (added 2026-09-05; lives in
   `PensionEscalationBasis` and `DbPension`).** Board card 0035 made both escalation dropdowns
   live: a scheme now revalues on its revaluation basis until normal retirement age and escalates
   on its in-payment basis afterwards, instead of every scheme rising at full CPI for ever.
   The two capped bases are **statute and are sourced**: limited price indexation is 5% for
   pensionable service before 6 April 2005 and 2.5% for service after it (Pensions Act 1995 s.51,
   as amended by Pensions Act 2004 s.278), and it is a floor as well as a cap, so a capped pension
   is not cut when prices fall.
   **⚠️ SOURCING GAP, the sixth in this document.** Two figures beside them are this build's own
   judgement with **no primary source fetched**, because the unattended build loop has no web access:
   - **RPI escalates at CPI** (`PensionEscalationBasis::RPI_OVER_CPI_WEDGE_BPS` = 0). The reasoning
     is that RPI is being aligned with CPIH from February 2030, so a plan of this length spends
     nearly all of its years past the point the two agree, and zero is the cautious reading for
     income the household receives. That alignment was not verified against the UK Statistics
     Authority statement, and the pre-2030 years do carry a real wedge the model does not apply.
   - **A Fixed basis with no rate entered escalates at 3%** (`DbPension::DEFAULT_FIXED_ESCALATION_BPS`).
     3% and 5% are the two rates scheme rules commonly grant and 3% is the lower, which is the
     adverse reading; neither the pair nor the choice is cited.
   Both are disclosed on the results page as assumed figures reading their own constants, and both
   are overridden by what the reader enters (the fixed rate is now a builder input). Board card
   **0095** carries pinning them to published data. **Confirm you are happy with RPI-as-CPI and 3%
   until that lands.**
19. **How long the State Pension triple lock is assumed to last (added 2026-09-05; lives in
   `StatePension\StatePensionUprating`).** Board card 0038. The projector used to raise the State
   Pension by the greater of inflation and **2.5%** with no source, no setting and no control.
   Because inflation is modelled near 2%, that floor binds in most years, so the State Pension
   grew in REAL terms for the whole plan, and the Pension Credit guarantee, uprated by the same
   running factor, rose with it.
   The 2.5% is not an estimated series: it is the named parameter of the triple-lock policy, which
   raises the new and basic State Pension by the highest of average weekly earnings growth, CPI
   inflation and 2.5%. The earnings limb is statutory (Social Security Administration Act 1992
   s.150A); the CPI and 2.5% limbs are Government policy, re-confirmed at each fiscal event.
   **It was not re-fetched from gov.uk when this was written**, because the unattended build loop
   has no web access, so treat the citation as a statement of the policy rather than a verified
   quotation of it.
   Two things about the modelling, both stated on the results page:
   - **The earnings limb is not modelled.** The only earnings series the engine holds is the
     household's own real salary growth, which is an assumption about one couple's pay and not
     about national average weekly earnings, so using it would model one thing with another.
     Leaving it out understates the State Pension, which errs the cautious way. Board card
     **0100** carries closing it.
   - **The default is the full lock for the whole plan**, which reproduces every scenario stored
     before this card byte-identically, and is the OPTIMISTIC branch of contested policy. That is
     the reverse of the standing "adverse default, user-editable" rule, and it is a choice about
     what to assume rather than a figure to look up, so it is Rob's: board card **0099**.
   The reader can now choose the full lock, the lock ending in a year they name (prices alone
   after it), or prices alone throughout.

20. **Attendance Allowance and the Carer's Allowance earnings limit (added 2026-09-06; live in
   `TaxYear\BenefitsParameters`).** Board card 0044. Three figures were added by an unattended
   session with **no web access**, so two of them are stated rather than verified. Neither reaches
   a projection on its own: Attendance Allowance seeds the editable income stream a what-if
   creates, and the earnings limit is quoted in a warning beside the retirement-age lever.
   - **Attendance Allowance 2025/26, £73.90 lower and £110.40 higher a week**, is the published
     pair (gov.uk/attendance-allowance/what-youll-get), written from the building session's own
     knowledge and not re-fetched.
   - **Attendance Allowance 2026/27, £76.70 and £114.60**, is DERIVED, not published: it applies
     the same +3.8% and rounding to the nearest 5p that this file's own 2026/27 Pension Credit
     additions were derived by. Treat it as an estimate of the April 2026 uprating.
   - **The Carer's Allowance earnings limit**, £196.00 a week for 2025/26 and £203.36 for
     2026/27, is likewise stated rather than read off a table. The 2026/27 figure applies the
     government's stated rule of 16 hours at the National Living Wage (16 x £12.71) and the real
     published limit may be rounded differently.
   The LOWER Attendance Allowance rate is what the what-if uses, which is the cautious of the two
   and still qualifies for the Pension Credit severe-disability addition. Board card **0106**
   carries pinning all of it to a published source.
21. **The Support for Mortgage Interest standard rate and capital cap (added 2026-09-06; live in
   `Benefits\SupportForMortgageInterest`).** Board card 0045. Unlike section 20, **both figures
   reach a projection**: a household on Pension Credit Guarantee Credit has its mortgage interest
   met at the rate, on capital up to the cap, which lowers its spending and raises a charge on its
   home. They were added by an unattended session with **no web access**.
   - **The DWP standard interest rate, 2.09% a year.** The rule is the published one: the rate
     tracks the Bank of England monthly average interest rate for loans secured on dwellings, and
     moves only when that average has differed from it by 0.5 percentage points or more. The value
     is the building session's own recollection of the LOW end of the range the rule has produced
     since the 2018 loan scheme began, chosen low by the standing adverse-default rule, because
     understating help never lets a plan bank support that turns out not to be there. The cost of
     erring low is that SMI is compared against equity release on worse terms than it really has.
   - **The eligible capital limit, £100,000** for a pension-age claimant, half the working-age
     figure and unchanged since 2018. Believed correct, never fetched. It is deliberately NOT
     uprated by the projection, like the £10,000 capital disregard beside it, which is what happens
     in life.
   Two modelling calls ride on the rate rather than on a source: the charge rolls up at the standard
   rate rather than at the separate gilt-linked rate DWP charges on the loan, and the interest met
   is capped at the interest actually charged that year, so a rolled-up lifetime mortgage that
   charges no cash interest is met nothing. Board card **0109** carries pinning both figures and
   revisiting both calls.
22. **The Pension Credit near-miss margin, 10% of the guarantee (added 2026-09-06; live on
   `Benefits\PensionCreditResult::NEAR_MISS_MARGIN_BPS`).** Board card 0046. It decides when a
   household awarded nothing is still shown the claim prompt, and it is a JUDGEMENT with no
   published source behind it, because there is no DWP figure for "close": the real test is
   entitlement and only the DWP can settle it. **No projected figure moves with it.** It is sized
   to what this engine knowingly leaves out of the assessment (Savings Credit, every income
   disregard, the housing elements), any one of which can be the whole of a small gap. On the
   2026/27 rates it is about £24 a week for a single pensioner and £36 for a couple. It is
   deliberately NOT a builder control, unlike the modelling defaults above: widening it costs the
   reader a paragraph, and narrowing it can cost them a benefit they were entitled to, so the
   cautious direction is to prompt too often. The margin is quoted in the prompt itself, read from
   the constant that owns it.
23. **The four council tax figures (added 2026-09-06; live on `Benefits\CouncilTax` and
   `Dto\CouncilTaxBand`).** Board card 0047. All four are STATUTORY rules rather than economic
   assumptions, and all four are **STATED, not verified**, because the unattended session that
   added them had no web access. The rules are quoted from the legislation below and the citations
   were NOT fetched, so none carries a verified_on date. All four DO reach a projection. Board card
   **0111** carries pinning them to a primary source.
   - **Single-person discount, 25%** (`SINGLE_PERSON_DISCOUNT_BPS`). Local Government Finance Act
     1992 s.11: https://www.legislation.gov.uk/ukpga/1992/14/section/11
   - **Council Tax Reduction taper, 20% of income above the applicable amount**
     (`REDUCTION_TAPER_BPS`), with maximum reduction for a household on Guarantee Credit and nil
     above the £16,000 capital limit. Council Tax Reduction Schemes (Prescribed Requirements)
     (England) Regulations 2012: https://www.legislation.gov.uk/uksi/2012/2885
   - **Band proportions in ninths, A 6 to H 18** (`CouncilTaxBand::ninths()`). Local Government
     Finance Act 1992 s.5: https://www.legislation.gov.uk/ukpga/1992/14/section/5
   - **Disabled band reduction: charged as the band below, and band A reduced by one ninth of band
     D** (`CouncilTaxBand::reducedNinths()`). Council Tax (Reductions for Disabilities) Regulations
     1992: https://www.legislation.gov.uk/uksi/1992/554

   One modelling call sits beside them and is NOT statutory: the Council Tax Reduction applicable
   amount is the Pension Credit one the engine already computes, rather than the CTR scheme's own
   personal allowances and premiums. The two are built to the same shape from the same uprated
   figures, and a second hand-entered table would be a second definition of one quantity. The card
   itself directed this. Two v1 limits follow it, both flagged in code: non-dependant deductions
   are not modelled, which OVERSTATES the reduction for a household with another adult living
   there, and the pension-age basis is applied only where every living member has reached State
   Pension age, since a younger household falls under its council's own working-age scheme, which
   is not prescribed and differs in every district.
24. **The two pension-age Housing Benefit figures (added 2026-09-06; live on
   `Benefits\HousingBenefit` and `Benefits\CapitalAssessment`).** Board card 0048. Both are
   STATUTORY rules rather than economic assumptions, both are **STATED, not verified** (the
   unattended session that added them had no web access, so neither citation was fetched and
   neither carries a verified_on date), and both DO reach a projection. Board card **0113** carries
   pinning them to a primary source.
   - **Housing Benefit taper, 65% of income above the applicable amount** (`TAPER_BPS`), with the
     maximum award for a household on Guarantee Credit and nil above the £16,000 capital limit.
     Housing Benefit (Persons who have attained the qualifying age for state pension credit)
     Regulations 2006: https://www.legislation.gov.uk/uksi/2006/214
   - **Notional costs of sale, 10% of a property's market value** (`NOTIONAL_SALE_COSTS_BPS`),
     deducted from the value before anything secured on it, when property is assessed as capital.
     Same regulations, the capital-valuation rules.

   The applicable amount used is the Pension Credit one the engine already computes, for the same
   reason and with the same caveat item 23 gives. Four v1 limits follow, all flagged in code and
   all pushing the award the OPTIMISTIC way, which is against the standing rule of defaulting
   adverse: the **Local Housing Allowance cap** on eligible rent is not applied at all (there is
   one rate per broad rental market area, re-set every April, and the engine holds no table), which
   is board card **0114**; ineligible service charges inside a rent are not stripped out;
   non-dependant deductions are not modelled; and whether anybody claims is not modelled. Working-
   age Housing Benefit is deliberately not modelled either, since it is closed to new claims and
   its replacement is the Universal Credit housing element, which card 0048 put out of scope for a
   pension-age tool. That exclusion UNDERSTATES a rent plan with a member below State Pension age,
   and the plan says so on its face.
25. **The 28-day disability-benefit stop in a funded care placement (added 2026-09-06; lives on
   `Benefits\DisabilityBenefitInCare`).** Board card 0050. Attendance Allowance and the CARE
   component of DLA (the daily living component of PIP) stop after 28 days in a care home whose
   fees the local authority meets; the MOBILITY component keeps being paid. The mirror of the same
   split is that the care component, while it IS in payment, counts as income in the local-authority
   financial assessment, and the mobility component is disregarded there. Both are STATUTORY rules
   rather than economic assumptions, both are **STATED, not verified** (the unattended session that
   added them had no web access, so no citation was fetched and neither carries a verified_on
   date), and both DO reach a projection. Board card **0118** carries pinning them to a primary
   source; the rules are regulation 8 of the Social Security (Attendance Allowance) Regulations
   1991 and regulation 9 of the Social Security (Disability Living Allowance) Regulations 1991,
   with the assessment treatment in the Care and Support (Charging and Assessment of Resources)
   Regulations 2014.

   Two v1 limits follow, both flagged in code. Funding status is settled on the capital the year
   OPENS with, through the same self-funder line the charge is built on, so the charge's own
   crossing-year term (capital paid down to the upper limit) is not consulted; and the Pension
   Credit severe-disability addition is dropped for the WHOLE of a funded care year, although the
   first such year keeps 28 days of the benefit itself, because an annual grid cannot pay a
   part-year addition and dropping it is the adverse of the two roundings. The counter also resets
   whenever a spell ends, so a resident who leaves care and returns gets a fresh statutory period,
   which is right for a real break in residence and wrong for a short hospital stay this engine
   cannot see.

26. **The Inheritance Tax downsizing addition (added 2026-09-07; lives on
   `Iht\InheritanceTaxCalculator::downsizingAddition`).** Board card 0053. Where a qualifying
   former residence was disposed of on or after **8 July 2015** and the home left at death is
   smaller or gone, the part of the residence nil-rate band the former home would have used is
   restored, capped at the value of the non-home assets passing to direct descendants, and the
   tapered allowance is the ceiling on the home and the addition together. It is a **STATUTORY
   rule, not an economic assumption**, it is **STATED, not verified** (the unattended session that
   added it had no web access, so nothing carries a verified_on date), and it **DOES reach a
   projection**: every sell plan's Inheritance Tax moves. Board card **0125** carries pinning it to
   a primary source; the rule is Inheritance Tax Act 1984 ss.8FA to 8FE, inserted by Finance Act
   2016 s.93 and Schedule 15, with the gov.uk guidance at
   https://www.gov.uk/guidance/inheritance-tax-residence-nil-rate-band

   Three v1 simplifications follow, all flagged in code. The statute expresses the lost band as
   PERCENTAGES of the maximum band at the disposal date and at death; the engine subtracts pence
   instead, which is the same number and exact here because one frozen band is in force for a whole
   run. Where a plan disposes of more than one home (a year-0 sale followed by a forced sale of the
   home bought with the proceeds) the MOST RECENT disposal is the one used, where the statute lets
   personal representatives choose. And the disposal carries a year, not a date, so the 8 July 2015
   gate is stated rather than resolved; the engine models no disposal before its own base year, so
   the gate can never be the deciding test.

27. **Intestacy on the first death, and the capped spouse exemption** (board card 0054). Until this
   card the model granted the first death an unlimited spouse exemption, on a will nobody was asked
   about, to a survivor whose residence position nobody was asked about either. Two rules now bite,
   and both are **STATUTORY rules, not economic assumptions**, both are **STATED, not verified**
   (the unattended session that added them had no web access), and both **DO reach a projection**:
   every stored plan that models Inheritance Tax moves, because no will is the default answer.
   Board card **0127** carries pinning them.

   The **statutory legacy** is £322,000 for deaths on or after 26 July 2023, from the
   Administration of Estates Act 1925 s.46(1)(i) as amended by SI 2023/758
   (https://www.legislation.gov.uk/uksi/2023/758/made). A surviving spouse or civil partner takes
   the personal chattels, that sum, and half the residue; the issue take the other half, which is a
   chargeable transfer. The **capped spouse exemption** where the recipient is not a UK long-term
   resident stops at the nil-rate band, from IHTA 1984 s.18(2); the election that removes the cap
   is not modelled.

   Five v1 simplifications follow, all flagged in code. Personal chattels are not modelled at all,
   which leaves the spouse's share LOWER and so is the cautious direction. The legacy is FROZEN
   rather than uprated, which is cautious in the same direction. Northern Ireland's own figure
   differs and the engine bundles it with England and Wales. The engine holds no list of children,
   so `homeToDescendants` is what says there are issue to take a share. And the residence nil-rate
   band is not claimed at the first death even where the children take part of the home under
   intestacy, which is adverse on that death and leaves the whole band to transfer to the second.

28. **The care property disregard and the deferred payment interest rate** (board card 0055; lives
   on `Dto\Property::$occupiedByQualifyingRelative` and `Care\DeferredPaymentAgreement`). Both are
   **STATED, not verified** (the unattended session that added them had no web access) and both
   **DO reach a projection**. Board card **0129** carries pinning them.

   The **mandatory property disregard** in a care financial assessment covers a home occupied by
   the resident's spouse or civil partner, by a relative aged 60 or over, by an incapacitated
   relative of any age, or by a child of the resident under 18 (Care Act 2014 and the Care and
   Support (Charging and Assessment of Resources) Regulations 2014, Schedule 2). The engine already
   covered the partner case; the flag covers the rest and defaults FALSE, the adverse answer. The
   DISCRETIONARY disregard for a carer who gave up their own home is not claimed at all.

   The **maximum interest rate on a deferred payment agreement is modelled at 4.65% a year**,
   compounded. The published RULE is the average of the OBR forecast for the 15-year gilt rate plus
   a default component of up to 0.15 percentage points, re-set on 1 January and 1 July each year
   (Care and Support (Deferred Payment) Regulations 2014). The VALUE is not pinned to a
   publication. **Adverse by rule:** that rule has produced roughly 2% to 5% since 2015 and the
   shipped figure is the high end, so a larger debt eats the estate and deferral is shown at its
   least attractive against selling.

   Four v1 simplifications follow, all flagged in code: the authority's set-up, valuation and
   administration fees are not charged; the disposable income allowance is not modelled, so the
   engine defers only the genuinely unfundable part and therefore defers LESS than the real scheme
   allows; the authority's discretion to refuse, and its own equity and security tests, are not
   modelled; and the twelve-week property disregard that normally runs before an agreement starts
   is not modelled.

29. **Permanent entry into care as an equity-release redemption event** (board card 0056; lives in
   `PathProjector::equityReleaseRedeemedByCare()` and the `lifetime_mortgage_rollup` results note).
   **STATED, not verified** (built without web access), and it reaches a projection.

   Two statements sit behind the model. First, that a standard lifetime mortgage falls due when the
   last surviving borrower moves permanently into long-term residential care, alongside death and
   sale. This is the Equity Release Council product standard as it is usually described, and it is
   what `Property::$mortgageRollUpRate`'s own docblock has always said; no contract or Council
   publication is cited. Second, that the twelve-week property disregard and a council deferred
   payment agreement are not normally available on a home already charged to a lender, which is
   stated in the results copy and drives no arithmetic.

   Two v1 simplifications follow, both flagged in code: the engine models a care spell but not its
   PERMANENCE, so any modelled care year is treated as a permanent placement (the adverse reading,
   and the ordinary one since a modelled spell runs to death); and the lender's own notice period
   and grace period, typically some months after the move, are not modelled, so the sale falls in
   the first care year.

   **To verify:** the Equity Release Council product standards, and a sample of lender tariffs for
   the notice period.

30. **The inherited-pension double charge** (board card 0057; lives in
   `InheritanceTaxCalculator::BENEFICIARY_TAXED_FROM_AGE`,
   `InheritanceTaxCalculator::DEFAULT_BENEFICIARY_MARGINAL_RATE_BPS` and
   `DcPension::$nominatedBeneficiary`). **STATED, not verified** (built without web access). The
   age-75 rule and the nomination routing both reach a projection; the assumed beneficiary rate
   drives a reported figure but no projected balance.

   Three statements sit behind the model. First, that an unused pension fund inherited from a member
   who died at or after 75 is taxable as the BENEFICIARY's own pension income at their marginal
   rate, and that the April 2027 change bringing unused pots into the estate for Inheritance Tax
   does not displace that charge. Second, that a pension death benefit is paid at the scheme's
   discretion following the member's expression of wish rather than under the will, so the spouse
   exemption on a pot turns on the nomination and not on marital status. Third, that the Inheritance
   Tax a pot bears is apportioned rateably over the chargeable estate, so the beneficiary is charged
   income tax on the pot NET of that share.

   The default beneficiary rate is 40%, the higher rate. It is a judgement, not a published figure:
   the pot this tool models is large enough that a working-age child drawing it on top of a salary
   lands in the higher band under almost any drawdown pattern, and the standing direction is to
   default adverse. The additional rate is more adverse still but applies to a small minority, so it
   is offered as a choice rather than assumed. The reader can pick 0%, 20%, 40% or 45% in the
   builder, and the assumption is disclosed on the results page whenever they have not.

   Two v1 simplifications follow. The engine charges one rate on the whole pot rather than banding
   the beneficiary's drawdown across their own personal allowance and bands over the years they
   take it, which is the direction of the rate the reader picks. And a pot nominated away from the
   spouse is still INHERITED by the surviving partner inside the projection, so it is taxed as
   leaving the household while its money stays in it; that gap is board card 0133.

   **To verify:** PTM073010 and the Finance Act 2004 provisions behind the age-75 dividing line, and
   the April 2027 legislation's treatment of nominated death benefits.

31. **The park home and the NHS nursing contribution** (board card 0059; lives in
   `Property::MAX_SITE_COMMISSION_BPS`, `Property::qualifiesForRnrb()` and
   `CareAssumptions::FUNDED_NURSING_CARE_WEEKLY_PENCE`). **STATED, not verified** (built without web
   access). All three reach a projection.

   Three statements sit behind the model. First, that the residence nil-rate band needs a qualifying
   residential interest in a dwelling-house, and that a park-home owner holds a chattel plus a pitch
   agreement rather than an interest in the land, so the band is at best unsafe and most likely
   unavailable. The engine refuses it, which is the adverse reading. Second, that the site owner may
   take a commission of up to 10% of the price on a sale, under the Mobile Homes Act 1983 Sch.1
   Pt.1 para 25 and the Mobile Homes (Commission) Order 1983, and that the maximum is what is
   charged in practice. The engine deducts it from the home's value in the estate and in the care
   means test, on the ground that the exit always happens eventually. Third, that NHS-funded Nursing
   Care is £254.06 a week (the 2025/26 standard rate), paid direct to the home for any resident
   assessed as needing a registered nurse including a self-funder, and that it applies to nursing
   care only.

   The FNC rate is held FROZEN in today's money rather than uprated, which is the cautious
   direction: a contribution that does not rise leaves more of the fee with the household. NHS
   Continuing Healthcare is deliberately NOT modelled, because it turns on a health assessment
   nothing here can predict; the results copy says so and says an award would remove the care
   charge entirely, which is the larger of the two facts.

   The 10% deduction is a v1 simplification in one direction the reader should know about: it is
   applied to the home's whole value at the valuation date, not to a sale price a thin market might
   set, so the illiquidity of a park home is stated in copy rather than priced.

   **To verify:** the Mobile Homes Act 1983 commission provisions and the current NHS England FNC
   standard rate; and the RNRB point against IHTM46000 and the definition of a qualifying
   residential interest.

32. **The purchased life annuity: the exempt capital element and the enhanced uplift** (board card
   0060; lives in `Pension\PurchasedLifeAnnuity` and `Dto\AnnuityPurchase::ENHANCED_UPLIFT_BPS`).
   **STATED, not verified** (built without web access). Both reach a projection.

   An annuity bought with money that is not pension money is a purchased life annuity, and only the
   interest element of each payment is taxable: the rest is the buyer's own capital coming back
   (ITTOIA 2005 Part 6 Chapter 7). The capital element is the price divided by the buyer's expected
   remaining life at the age the income starts, and stays FIXED in money for the life of the
   annuity, so an escalating annuity's exempt proportion falls as its payments grow. Two statements
   sit behind the model. First, that using the engine's own ONS cohort life expectancy in place of
   HMRC's prescribed tables is close enough to be useful: those tables are not held here, and no
   figure may be invented, so a real sourced expectancy stands in for a real prescribed one and the
   results copy says the tax is an estimate. Second, that an ENHANCED (impaired-life) annuity pays
   10% more than an ordinary one where the reader gives no quote. The market range runs from a few
   per cent to roughly a third; 10% is its cautious end, chosen because over-stating income secured
   for life is the error this tool must not make.

   Neither figure moves any stored scenario: an annuity can only be bought from an account once a
   reader ticks the new toggle, and no stored account has one.

   **To verify:** the Income Tax (Purchased Life Annuities) Regulations and the prescribed
   expectation-of-life tables behind the capital element, and a current market range for enhanced
   annuity uplifts.

33. **The asset mix, and the per-figure sourcing of the asset classes** (board card 0062; lives in
   `Forecast\AllocationProfile`, `Forecast\PortfolioAllocation` and the sourcing constants on
   `AssumptionSetLibrary`). The mix reaches every projection; the citations reach the screen.

   The mix is four named profiles on one axis, how much of the invested money is in shares:
   defensive 20/80, cautious 40/60 (the default, and what every scenario stored before the card ran
   on), balanced 60/40, growth 80/20. Cash is nil in all four, because a cash holding is entered as
   a cash account and modelled at the cash assumption; putting it inside the mix as well would count
   the same caution twice. The four points, and the choice of a labelled profile over a typed equity
   percentage, are a **judgement**, not a published set: they are the standing shape of a UK
   multi-asset risk-rated range rather than any one provider's.

   A glidepath moves the mix in a straight line to a second profile over a number of years the
   reader states. There is deliberately **no default length**: the lifestyling conventions differ,
   and a length the engine picked would change how much money the plan has.

   Each asset class now carries a source and a verified-on date for its return AND for its
   volatility, separately, because the default set takes the first from the FCA and the second from
   the long-run record. **The URLs are the ones this document already carried and the date is the
   2026-06-24 sign-off, both STATED rather than re-checked** (built without web access). Re-verifying
   them, and re-sourcing the gilt real return against current index-linked gilt yields (a 0.0% real
   return on a 60% bond weight is a large part of the blended figure and is stale in the cautious
   direction), is board card **0137**.

34. **The spending guardrail: where it bites and how hard** (board card 0063; lives in
   `Dto\SpendingGuardrail`). The guardrail is OPT-IN, so neither figure reaches a projection until
   a reader turns it on; once on, a blank figure takes the constant beside it and the results page
   discloses both. **Trigger: a funded ratio of 1.00** (`DEFAULT_TRIGGER_FUNDED_RATIO_BPS`), that
   is, usable wealth below the essential spend the plan still has to fund. A funded ratio of 1 is
   the standard actuarial statement of a fully funded plan, and triggering there rather than above
   it is the adverse of the defensible choices: a guardrail that bites earlier protects the plan
   sooner and so flatters the answer. **Cut: 10% of discretionary spend**
   (`DEFAULT_DISCRETIONARY_CUT_BPS`), the size of the cut in the published capital-preservation
   rule of Guyton and Klinger's decision-rules work, which is the citable ancestor of the
   guardrails in the retail tools. **Both are STATED, not verified** (the session that built the
   card had no web access): the Guyton-Klinger paper has not been read here and no primary citation
   is on file for either figure. Sourcing them, and checking whether a funded-ratio trigger or a
   withdrawal-rate trigger is the better-evidenced form, is board card **0138**.

35. **Inflation persistence and its correlation with real asset returns** (board card 0064; lives
   in `AssumptionSetLibrary::INFLATION_PERSISTENCE` and
   `AssumptionSetLibrary::INFLATION_ASSET_CORRELATIONS`). **STATED, not verified** (built without
   web access). Both reach a projection, and both are disclosed on the assumptions panel and in the
   PDF.

   **Persistence: 0.70.** The share of one year's deviation from mean inflation carried into the
   next, as a first-order autoregressive coefficient. UK annual inflation arrives in multi-year
   episodes rather than as independent annual surprises (1973-75, 1979-81, 2021-23), and a
   first-order coefficient around 0.7 is the standing range for annual UK inflation. It is applied
   at the cautious end of what it does: it widens the fan of the CUMULATIVE price level, which is
   adverse here, because the model runs against nominal tax thresholds frozen for years and so
   understates fiscal drag without it. The innovation is scaled by sqrt(1 - phi^2), so the spread
   of any SINGLE year stays exactly the stated `inflationVolatility`: persistence buys cumulative
   spread and nothing else.

   **Correlations with real returns: -0.30 equities, -0.50 gilts, -0.55 cash.** All negative and
   all by different amounts, which is the point: in a real-return framework an inflation shock is
   worst for the asset whose cash flows are fixed in money. Nominal gilts take the full hit, cash
   takes it too (deposit rates lag prices), and equities are partly real assets and are hit less.
   Together they make a 2022 possible in the model. The figures are judgement rather than a
   published matrix, and are kept short of the extreme end deliberately: past a point an
   over-negative row prices the whole portfolio as one bet on inflation, and past a further point
   describes a correlation structure that cannot exist at all (the augmented matrix stops being
   positive-definite and the decomposition refuses it).

   Every plan's Monte Carlo tail is wider under these, so the `ENGINE_VERSION` bump owes a
   stored-scenario re-run. Sourcing both against a series is board card **0139**.

   **To verify:** an ONS CPIH or RPI annual series long enough to estimate the AR(1) coefficient
   directly, and a published correlation matrix of UK real asset returns against inflation (the
   Barclays Equity Gilt Study and the DMS yearbook both report the inflation sensitivities).

36. **The annuity rate table** (board card 0065; lives in `Pension\AnnuityRateTable`).
   **STATED, not verified** (built without web access). It reaches a projection through the rate
   field on the builder's annuity sub-form, and every figure it produces is visible there and can
   be typed over, so nothing it decides is invisible.

   Before it, the rate was one free-text field coupled to nothing, defaulted to a level joint-life
   quote. Ticking "rises with inflation" bought an index-linked annuity at a level annuity's rate,
   which is roughly a third more income than the market sells, silently and in the flattering
   direction; and the same figure stood while the purchase age moved by fifteen years, which moves
   a real quote by more than half again.

   **Base rates, single life, level, standard health, per £1 of purchase price:** 5.60% at 55,
   6.20% at 60, 7.10% at 65, 8.10% at 70, 9.60% at 75, 11.60% at 80, 14.00% at 85, interpolated
   between and clamped outside. **Index-linked multiple: 0.62** of the level rate at outset.
   **Joint-life reduction: 20%** where the WHOLE income continues to a survivor, scaled linearly by
   the fraction, so the common half-pension option costs 10%.

   Not priced, deliberately, and each of them makes a real quote LOWER than this table: a guarantee
   period, value protection, the spouse's age, the buyer's postcode, and the market moving with
   gilt yields. That is why the sub-form says to enter a real quote.

   **To verify:** a dated snapshot from a live open-market-option quote service (Money Helper's
   annuity comparison tool, or the Hargreaves Lansdown / Legal and General best-buy tables), across
   the age range and for each of the four shapes. Board card **0140**.

37. **The chattels exempt amount and the marginal relief** (board card 0065; live in
   `CgtParameters::$chattelsExemptAmount` and `Tax\ChattelsGain`). These are statutory rather than
   economic, but they were added in the same pass and are **STATED, not verified** against a live
   fetch: £6,000 of proceeds per item or set, and a chargeable gain capped at five thirds of the
   excess above it. TCGA 1992 s262
   (https://www.legislation.gov.uk/ukpga/1992/12/section/262), restated at
   https://www.gov.uk/capital-gains-tax-personal-possessions . The £6,000 has not been uprated
   since 1989, which is why both tax years carry it; confirming that against the current gov.uk
   page is board card **0140**.

- Tenant Fees Act 2019 c.4, Schedule 1 (tenancy deposit capped at five weeks' rent, six weeks where the annual rent is £50,000 or more): https://www.legislation.gov.uk/ukpga/2019/4/schedule/1
- NextWealth Fee Benchmarking Report 2026 (ongoing advice fee 83bp, up from 77bp): https://nextwealth.co.uk/research/fee-benchmarking-report-2026/
- Professional Adviser, "Almost half of clients report increase in advice fees" (12 Mar 2026 — independent confirmation of the 83bp figure): https://www.professionaladviser.com/news/4526864/half-clients-report-increase-advice-fees
- FCA Handbook COBS 13 Annex 2 (projection rates): https://handbook.fca.org.uk/handbook/COBS/13/Annex2.html
- FCA/PwC, "Rates of return for FCA prescribed projections" (2017): https://www.fca.org.uk/publication/research/rates-return-fca-prescribed-projections.pdf
- UBS Global Investment Returns Yearbook 2025 (DMS): https://www.ubs.com/global/en/investment-bank/insights-and-data/2025/global-investment-returns-yearbook-2025.html
- Barclays Equity Gilt Study 2025: https://www.ib.barclays/news-and-events/equity-gilt-study-2025.html
- OBR Economic and Fiscal Outlook, March 2026: https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/
- ONS Private rent and house prices, UK (June 2026): https://www.ons.gov.uk/economy/inflationandpriceindices/bulletins/privaterentandhousepricesuk/june2026
- Jordà, Knoll, Kuvshinov, Schularick & Taylor, "The Rate of Return on Everything, 1870–2015", NBER Working Paper 24112 (housing far less volatile than equities; low equity–housing covariance / diversification gains): https://www.nber.org/papers/w24112
- Champagne, Kurmann & Stewart, "Dissecting Aggregate Real Wage Fluctuations", FRB San Francisco WP 2011-23 (aggregate real wage growth volatility ~0.51× GDP-growth volatility — far smoother than profits): https://www.frbsf.org/wp-content/uploads/wp11-23bk.pdf
- ONS, Average weekly earnings in Great Britain (real regular-pay growth series used to sanity-check the ~2% annual real-earnings volatility): https://www.ons.gov.uk/employmentandlabourmarket/peopleinwork/employmentandemployeetypes/bulletins/averageweeklyearningsingreatbritain/january2026
- King's Fund, Social Care 360 — expenditure and provider fees (care unit-cost drivers): https://www.kingsfund.org.uk/insight-and-analysis/long-reads/social-care-360-expenditure
- PSSRU/LSE (Wittenberg et al.), long-term care expenditure projections (care unit costs escalated on earnings/productivity, ~2% real above prices): https://eprints.lse.ac.uk/88376/1/Wittenberg_Adult%20Social%20Care_Published.pdf
- DWP, Pension Charges Survey 2020 (average member-borne ongoing charge 0.48% in qualifying default arrangements; 0.53% non-qualifying; all below the 0.75% cap): https://www.gov.uk/government/publications/pension-charges-survey-2020-charges-in-defined-contribution-pension-schemes/pension-charges-survey-2020-charges-in-defined-contribution-pension-schemes
- DWP, The Pension Provider Survey 2024/25, published 21 July 2025 (median AMC on providers' largest default funds 0.28%; decumulation charges too incompletely reported to publish): https://www.gov.uk/government/publications/the-pension-provider-survey-202425/the-pension-provider-survey-202425
- Vanguard Investor UK, fees explained (account fee 0.15% capped at £375/yr, £4/mo under £32,000; self-managed fund OCFs 0.06%–0.79%): https://www.vanguardinvestor.co.uk/what-we-offer/fees-explained
- LaingBuisson, "Care of Older People" UK market report — self-funder fee inflation (~10%/yr to Dec-2025, ~20% over two years; NLW + employer-NI driven): https://www.laingbuisson.com/press-releases/older-people-forced-to-pay-nearly-20-more-for-their-care-as-fees-skyrocket-over-the-last-two-years/

⚠️ Confidence flags from the research: Barclays Equity Gilt Study exact figures come from
adviser summaries of the paywalled study; the FCA 2/5/8 + 2% inflation deduction is
corroborated via the FCA/PwC report and secondary sources; the cash-vol override and the
cash correlations are modelling choices, not cited point values.
