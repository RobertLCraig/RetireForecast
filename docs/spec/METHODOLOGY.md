# How RetireForecast works

_Last updated: 2026-07-03._

This page explains **how RetireForecast produces its figures**: the methods behind the tax, pension,
housing and longevity calculations, the assumptions they rest on, and — just as important — **what
the tool does not model**. It is written to be honest about the edges. Every statutory figure the
engine uses carries a source and the date it was last checked against that source.

Two companion pages give the numbers behind two of the biggest inputs: the [economic
assumptions](ASSUMPTIONS.md) (investment returns, inflation, house and salary growth) and the
[mortality data](MORTALITY.md) (how long people are assumed to live).

## The shape of a forecast

- **Everything is in today's money.** The engine works internally in future (nominal) pounds — so
  that frozen tax thresholds bite against rising incomes, exactly as real fiscal drag does — then
  converts every figure back to **real, today's-money** terms for display. Unless a figure is
  labelled otherwise, it is real and net of tax.
- **Money is exact.** All amounts are held as whole pence, never as decimals that can drift. Tax
  rates are held exactly (8.75%, 39.35% and so on). Tax-free cash is rounded in your favour, so tax
  is never understated.
- **No magic numbers.** Each rate and threshold lives in a per-tax-year record with a `gov.uk`
  source link and a "verified on" date. The tool currently holds the **2025-26 and 2026-27** tax
  years, for **England, Wales and Northern Ireland**. Scotland is deliberately refused rather than
  quietly given the wrong bands.
- **One engine, two views.** A single year-by-year projection engine produces both:
  - a **central projection** — one path, using the expected (best-estimate) return, inflation and a
    representative lifespan. This answers *"what happens on the main assumptions?"*
  - a **Monte Carlo** — thousands of paths with randomly varying returns, inflation, lifespans and
    care needs. This answers *"how likely is it, and what is the range of outcomes?"*

  Because both run the same engine, the central case and the simulated distribution stay consistent
  with each other by construction.

## Income tax and National Insurance

UK income tax stacks three kinds of income in a fixed order — **non-savings** (earnings, pensions,
the State Pension, rental income), then **savings** (interest), then **dividends** — through one
shared set of tax bands. The engine reproduces this in a single pass, because each 0% allowance
still uses up band space and pushes the income above it into higher bands:

- The **personal allowance** (£12,570) is set against income in the statutory order, and is tapered
  away by £1 for every £2 of income over £100,000.
- The **basic (20%)**, **higher (40%)** and **additional (45%)** bands are filled in turn.
- The **personal savings allowance** depends on the top band your income reaches (£1,000 at basic
  rate, £500 at higher, £0 at additional), and the **£5,000 starting-rate band for savings** is
  eroded pound-for-pound by non-savings income.
- **Dividends** get their £500 allowance, then are taxed at the dividend rates for each band (from
  2026-27 these rise to 10.75% ordinary / 35.75% upper, with 39.35% additional).

Each person is taxed **individually**, as in the UK system. The tool reproduces HMRC's published
worked examples to the penny.

**National Insurance** is modelled for **employees only**: the main rate (8%) between the primary
threshold and the upper earnings limit, 2% above it, and category-aware rates for reduced, deferred
or nil categories. NI **stops at State Pension age** and is never charged on pension income,
drawdown or the State Pension.

Not modelled: Scottish income-tax bands; employer (secondary) NI; self-employed Class 2/4 NI;
marriage allowance, blind person's allowance, or Gift-Aid/pension band extension.

## Pension withdrawals and the lump-sum tax shock

This is one of the two headline outputs. When you take money from a defined-contribution pension:

- **25% is tax-free** (up to the lump-sum allowance of £268,275, tracked across all your pensions),
  and the rest is taxable.
- On your **first** flexible withdrawal, HMRC applies **emergency "Month 1" tax**: it gives you only
  one-twelfth of your allowances against that single payment and taxes the rest at the top rates —
  so a large one-off withdrawal is **massively over-taxed up front**. The tool shows this
  over-deduction and which form you use to reclaim it: **P55** (pot not emptied), **P50Z** (pot
  emptied, no other income) or **P53Z** (pot emptied, with other income).
- The **true** tax finally due is worked out as the difference your withdrawal makes to a full
  income-tax calculation — so it correctly accounts for the way pension income can push your savings
  and dividends into higher bands.
- Taking taxable pension money flexibly triggers the **money-purchase annual allowance (MPAA)**,
  cutting future pension contributions to £10,000 a year. Taking only the tax-free cash does not.

The tool also models the **annual allowance** (£60,000), its **high-income taper** (down to £10,000)
and **carry-forward** of unused allowance.

A note on the standalone "tax shock" panel: to keep it self-contained it assumes your **other
income** is your salary if you are still working, and nothing once retired — it does **not** layer in
the State Pension or a defined-benefit pension already in payment. The full year-by-year forecast
*does* include those. The emergency over-deduction the panel leads with does not depend on that other
income. The tool models the **size** of the emergency deduction, not HMRC's PAYE tables to the exact
pound.

**Defined-benefit** pensions are paid from their normal retirement age, escalated for inflation, with
optional tax-free **commutation** (a lump sum in exchange for a permanently reduced pension) and a
**survivor** pension. An **annuity** can be bought with part of a pot at a rate you enter (the tool
bakes in no age/rate table), level or inflation-linked, single or joint life.

## The State Pension

- **State Pension age** is derived from date of birth using the legislated timetable (66, rising to
  67 for those born 1960–61, and towards 68 for those born 1977–78).
- Entitlement comes from your own forecast weekly amount, or is estimated pro-rata from qualifying
  years (nothing below 10 years; the full amount at 35). **Deferral** delays the claim: the pension
  is not paid during the deferral period (that income is forgone), and the later, uplifted rate — 1%
  more for every 9 weeks deferred — is drawn for life from the delayed start. So deferring only pays
  off if you live well past that start; a deferred pension still counts as income for Pension Credit
  while paused (you cannot gain Pension Credit by deferring).
- Over the projection the **triple lock** is modelled as a proxy: the pension rises each year by the
  greater of inflation or 2.5%.

Not modelled: pre-1960 birth cohorts (out of the forward-looking scope); any State Pension age rise
beyond 68; the earnings-growth leg of the triple lock (the proxy uses inflation or 2.5% only).

## Selling or downsizing your home

When a home is sold, the tool works down a **sale waterfall**:

> sale price − mortgage repaid − selling costs − any capital gains tax = **net proceeds**

Selling costs default to 2% of the price if you do not itemise them. If you then **buy somewhere
cheaper**, it deducts the purchase price, stamp duty and moving costs; any surplus is invested. If
the cheaper home still costs more than the sale frees and you have set an interest-only (retirement)
mortgage rate, the shortfall is **borrowed** and carries a lifetime interest cost. If you **sell and
rent**, the whole net proceeds are invested and rent is paid each year, growing at its own inflation
rate.

**Stamp duty (SDLT)** is charged band by band (0% to £125k, 2% to £250k, 5% to £925k, 10% to £1.5m,
12% above). The 3%–5% **additional-property surcharge** exists in the engine but is **not applied**
in the buy-vs-rent comparison, which assumes you are replacing your main residence.

**Capital gains tax** on a home is worked out the way HMRC does it — by **occupation**, not mortgage
type. A home you have lived in throughout is fully relieved (**£0 tax**). If it was ever let, relief
is time-apportioned: the years you lived there (plus the final 9 months) are relieved, and the rest
of the gain is taxable. Jointly-owned homes split the gain, and **each owner** gets their own £3,000
allowance and pays their own rate (18% or 24%).

Not modelled: Welsh LTT / Scottish LBTT; the SDLT surcharge on the replacement home; lettings relief;
periods-of-absence ("deemed occupation") rules — a let period other than the final 9 months is
treated as chargeable, entered by hand.

## Means-tested benefits — the downsizing trap

Freeing home equity into savings can reduce means-tested help, which the tool surfaces so it is not a
surprise:

- **Capital tariff:** the first £10,000 of savings is ignored; above that, every £500 (or part) is
  treated as £1 a week of income.
- **The £16,000 cliff:** Housing Benefit and Council Tax Support stop once savings exceed £16,000.
  (Pension Credit itself has no upper capital limit.)
- **Pension Credit** (Guarantee Credit) is calculated **every year of the projection**: the minimum
  guarantee for a single person or couple, less your assessable income and capital tariff. Both
  members must be over State Pension age (a mixed-age couple gets nothing). Assessable capital
  includes the equity of a **let** former home — so selling or letting your home can erode the credit
  dynamically, year by year.

The severe-disability addition applies where the household qualifies — a single disabled pensioner, or a
couple where **both** partners receive a qualifying disability benefit (a non-disabled partner blocks it) —
and a carer addition where a partner cares for a disabled partner. Not modelled: Savings Credit (Guarantee
Credit only); Housing Benefit / Council Tax Support are never *awarded*, only their loss above £16,000 is
flagged; the carer addition is supported by the engine but not yet exposed as a builder input.

## Inheritance tax

Inheritance tax is offered as a **toggle**, to compare spending your pension down against preserving
an estate. When it is on, the forecast values your estate **at each death inside the projection** and
computes the tax due: the nil-rate band (£325,000), the residence nil-rate band (£175,000, tapered
away £1 for every £2 of estate over £2,000,000 and capped at the home passing to direct descendants),
and 40% on the rest. The **April 2027** change that brings unused pensions into the estate applies to
any death from that year on.

The result depends on your **relationship status** (a household input, defaulting to married / civil
partnership):

- **Married or in a civil partnership:** on the first death everything passes to the survivor free of
  tax (the spouse exemption), and both partners' nil-rate bands are available on the second death (the
  transferable band — modelled as two full bands). The tax lands on the second death, when the estate
  passes to direct descendants.
- **Cohabiting (not married):** there is no spouse exemption and no shared band. The deceased's share
  of the estate passing to the surviving partner on the first death is a chargeable transfer, and each
  death has only one set of bands — so the same estate is taxed more heavily. A cohabiting couple also
  can't inherit a State Pension or (usually) a scheme's survivor pension; the forecast flags where those
  may overstate the survivor's income.

The estate at each death is valued from the projection state as **liquid savings + home equity (net of
mortgage) + unused pension** (the pension counting only from April 2027). It is computed in the year's
nominal pounds against the frozen bands (so a growing estate against a frozen band is taxed more over
time, the real fiscal drag) and then shown in today's money. A first death splits the jointly-owned
home 50/50 (immaterial for a married couple, whose first death is exempt).

Not modelled (so this is an illustration of the headline bands, not a full estate computation): lifetime
gifts and the 7-year taper; trusts; business or agricultural relief; the reduced 36% charitable rate;
non-descendant beneficiaries or split legacies; the income tax a beneficiary later pays on an inherited
pension. The headline panel values deaths at the plan's representative (median) ages; a completed Monte
Carlo run also shows the **spread** of IHT across futures (the share leaving any bill, and the median vs
high-end), since it varies with longevity and returns.

## Care costs

Later-life care is a fat-tailed risk, so it is modelled explicitly:

- In the **Monte Carlo**, each person has roughly a **1-in-4** chance of a care spell, of typically
  ~2.5 years (capped at 8), placed at the end of life, at self-funder fees (around £1,300 a week
  residential, £1,600 nursing, from LaingBuisson). Those fees **rise faster than general prices**:
  care is largely National-Living-Wage-pinned staff cost, so the fee escalates at **CPI + 2% real**
  (editable per scenario) to the year the spell falls — a late-life spell therefore costs more the
  later it occurs. The share of futures where care costs the household anything, and the typical and
  high-end bills it bears, are shown as a risk panel.
- Each care year is **means-tested** under the DHSC charging rules (England), assessed on the
  resident's own means: full fees while their own capital is above **£23,250**; capital ignored
  below **£14,250** with a **tariff** (£1 a week per £250) in between; once below the limit they
  contribute their income less the **Personal Expenses Allowance** (£31.80 a week, 2026-27) and
  the local authority pays the balance. The home is disregarded while a partner still lives in
  it, and assessed once the resident is alone (or the home is let). A partner's own income and
  savings are never assessable. The £86,000 lifetime care cap is **not** modelled — it was
  cancelled in July 2024.

The **central (best-estimate) projection stays care-free** — care is a risk, not a certainty, so
folding an averaged amount into the central line would mislead. Instead the "What you can afford"
screen shows, beside each plan's care-free verdict, an **"if significant care is needed" stress**:
the same expected path with one adverse ~4-year nursing spell (~£1,800 a week) placed on the
last-surviving partner, means-tested and escalated like any care cost. So a plain "lasts for life"
is never shown against a silently care-free path, while the ordering still reflects the expected case.

Simplifications: the care-stress uses fixed adverse parameters (not yet user-editable), and applies
one spell rather than both partners'; the probabilistic Monte Carlo care model is unchanged; the care
probability is split by sex but not yet by age; any Pension Credit award is not counted into the care
contribution; the local authority is assumed to pay its share at the same fee rate; deferred-payment
agreements and the 12-week property disregard are below the annual grid.

## How long you might live

Lifespans drive everything, so they are sampled from **ONS 2024-based cohort life tables** (see
[mortality data](MORTALITY.md) for the source and method). Each partner's age at death is drawn from
their one-year mortality probabilities; the central projection uses a representative (median)
lifespan instead of sampling. Above age 100 a smooth statistical tail runs to a hard cap of 110.

Not modelled: partners are sampled **independently** — there is no "broken-heart" correlation between
a couple's deaths; ages below 50 are outside the grid.

## The Monte Carlo simulation

The simulation runs **1,000 paths** for a quick preview and **10,000** for a full run, from a
recorded random seed — so the same inputs and seed reproduce byte-identical results. For each path,
in order, it draws the two lifespans, any care spells, then a year-by-year path of investment returns
and inflation, and runs the full projection.

Investment returns are modelled in **real (above-inflation)** terms across three asset classes —
equities, bonds and cash. Each year draws correlated random returns (mean plus volatility, using the
asset correlations), and inflation is drawn separately. The invested pot earns the blend of its
holdings; the default is a cautious 40% growth / 60% defensive mix. The return, volatility and
correlation figures, and their sources (FCA projection rates with historical volatilities), are set
out in the [economic assumptions](ASSUMPTIONS.md).

Not modelled: house-price and salary growth have **no** year-to-year volatility in the simulation
(they use their expected values); returns are drawn from a normal distribution, so there are no fat
tails or regime shifts; partner deaths are independent.

## The historical stress test

Alongside the Monte Carlo, a **sequence-of-returns backtest** replays each past starting year's
*actual* UK real returns and inflation over your plan, to isolate the risk of a bad run of years
early in retirement (which hurts far more than the same years later). It reports what share of
historical start years your plan would have survived, and names the worst (1929, the 1970s,
2000, 2008). The data is the **Jordà–Schularick–Taylor Macrohistory** database of UK total returns
(1871–2020); start years without enough following data fall back to the expected assumptions, and
the backtest uses representative lifespans so only the market path varies.

## Putting it together: the year-by-year projection

Each projected year the engine:

1. Adds up every living person's income by source — part-year earnings in a retirement year, DB
   pension, State Pension, income streams, planned pension withdrawals, annuities, survivor pensions.
2. Taxes each person individually (income tax in the combined pass above, plus employee NI on
   earnings). Unwrapped savings interest and GIA dividends are taxed each year while the asset itself
   grows at capital only; ISAs are tax-free.
3. Credits any Pension Credit.
4. Meets the household's spending — an essential floor plus discretionary spending, with costs that
   switch off when they should (mortgage costs when the mortgage ends, property costs when the home
   is sold, commuting when work stops), a survivor spending factor, one-off costs, rent and care.
5. Handles mortgage-maturity events (refinance, repay from capital, or a forced sale in place), and
   models the three shapes a mortgage can take: **interest-only** (the balance stays level and the
   interest is a spending line), **equity-release roll-up** (the balance compounds unpaid and is
   repaid from the estate, capped at the home's value), and **repayment / capital & interest** (the
   balance amortises to zero over the term and the instalment stops when it does). A repayment
   instalment is treated as what it is — **fixed in cash terms**, so it costs less in real money each
   year, and **unchanged when one partner dies**, unlike ordinary household spending.
6. Funds any shortfall by the chosen **withdrawal strategy**, grossing pension withdrawals up for
   tax; invests any surplus.
7. Charges capital gains tax on any GIA disposal, and on death passes the estate to the survivor (so
   money is never stranded and mis-read as "running out").
8. Grows the balances into the next year, then takes the year's **investment charges** out of them —
   the platform/administration fee plus the funds' own ongoing charges, on pensions, ISAs and
   investment accounts (cash deposits pay none). The growth rates in the assumptions panel are
   *before* charges, which is how they are published, so the charge is deducted separately and shown
   as its own pounds figure beneath the cashflow table rather than folded into a smaller growth line.

**Fiscal drag** is built in: tax thresholds are frozen to April 2031 (as legislated), then indexed
with inflation. **Withdrawal strategies** range from the default tax-efficient order (non-pension
first, pension last) to "fill your tax-free bands first", and adapt when a household is on Pension
Credit (drawing capital first to avoid clawing the credit back).

The tool also compares your spending against the **PLSA Retirement Living Standards** (Minimum /
Moderate / Comfortable) as a neutral yardstick — it reports which standard your spending reaches, and
never says whether that is "enough".

## What we don't model

An honest list of the current limits (each is flagged in the code):

- **Region:** England, Wales and Northern Ireland only. Scotland is refused; Welsh LTT is treated as
  SDLT.
- **Emergency tax** reflects the size of the over-deduction, not HMRC's PAYE tables to the pound.
- **SDLT** surcharge is not applied to a replacement main residence; no first-time-buyer relief.
- **Capital gains:** no lettings relief; deemed-occupation absences are entered by hand; one rate per
  owner; capital losses are not relieved; the CGT band is judged on non-savings income.
- **Inheritance tax** (when the toggle is on) values the estate at each death inside the projection and
  applies the headline bands (relationship-status aware), but not the fuller estate: no lifetime gifts or
  7-year taper, trusts, business/agricultural relief, the 36% charity rate, non-descendant beneficiaries,
  or the inherited-pension income-tax interaction. The headline panel values deaths at representative
  ages; a completed Monte Carlo run additionally shows the spread of IHT across futures.
- **Care:** the lifetime care probability is sex-differentiated (women's chance is materially higher,
  anchored to the Dilnot/PSSRU ~1 in 4 population mean); age-conditioning of the onset rate and a
  sex split of the duration remain flagged refinements; Monte Carlo only. The in-path means test does
  not count Pension Credit into the contribution, assumes the local authority pays at the same fee
  rate, and skips deferred-payment / 12-week-disregard mechanics (below the annual grid).
- **Mortgages:** no lender or broker **fees**, arrangement/redemption charges or **early-repayment
  charges** are modelled anywhere, so the cost of taking or leaving a deal is understated. On a
  repayment mortgage there is no input for **overpayments** (the overpayment input applies only to an
  equity-release roll-up), and a variable reversion rate is modelled as a single fixed rate for the
  rest of the term rather than a rate that moves.
- **Benefits:** Guarantee Credit only (no Savings Credit); the severe-disability addition follows the
  couple-eligibility rule (both partners must be on a qualifying disability benefit); the carer addition is
  supported by the engine but not yet exposed as a builder input.
- **Monte Carlo:** normal returns (no fat tails); independent partner deaths; the historical data
  ends in 2020. (House-price and salary growth now carry volatility — see the assumptions panel.)
- **Pensions:** the lump-sum-allowance cap on a DB commutation lump sum is not enforced; one smooth
  inflation proxy for DB escalation. **Contribution tax relief** is modelled for "net pay" schemes
  (the contribution comes off gross salary, so relief lands at your own tax rate straight away, and
  National Insurance is unaffected); **relief at source is not modelled yet** and is refused rather
  than quietly treated as no relief; **salary sacrifice** is not modelled at all. We do not yet apply
  the £3,600 relief limit for a non-earner, or the annual allowance / MPAA cap on how much of a
  contribution can be relieved. If a pension's relief method is left unset we model no relief, and say
  so on your results.
- **Investment charges:** one household-wide rate covers the platform/administration fee and the funds'
  ongoing charges together, taken from pensions, ISAs and investment accounts each year (cash deposits
  bear none). There is no per-account or per-pot rate, so a cheap ISA and an expensive old pension are
  charged the same; and the rate does **not** include the cost of financial advice, which an advised
  household pays on top.
- **Other:** a one-off cost triggers on the first-listed person's age only; a person under 50 is out
  of the mortality grid.

## How we check it

- The deterministic engine reproduces **HMRC's published worked examples to the penny**.
- **Reconciliation checks** assert that totals equal the sum of their parts, and **completeness
  checks** that every input that should count actually reaches the result (no income stream silently
  dropped).
- The engine is **framework-free** and holds no live clock or database, which is what makes the
  worked-example tests trustworthy. Statutory figures are versioned per tax year with a source and a
  verified-on date, and freshness commands flag any figure or mortality dataset that has aged.

## This is guidance, not advice

RetireForecast is an **education and guidance** tool. It illustrates the consequences of the figures
and assumptions you enter. It does not tell you what to do, does not make a personal recommendation,
and is not regulated financial advice. Pension and housing decisions are significant and hard to
reverse — for free, impartial guidance see [Pension
Wise](https://www.moneyhelper.org.uk/en/pensions-and-retirement/pension-wise) and
[MoneyHelper](https://www.moneyhelper.org.uk/), or speak to an FCA-regulated adviser.

## Sources

Statutory rates and thresholds are taken from `gov.uk` (income tax, National Insurance, dividends,
stamp duty, capital gains tax, Pension Credit, inheritance tax, care financial assessment), each
stamped with the date it was last verified. The modelling assumptions and their sources are set out
in full in the companion pages:

- [Economic assumptions](ASSUMPTIONS.md) — returns, volatility, inflation, house/rent/salary growth
  (FCA projection rates; Dimson-Marsh-Staunton / Barclays historical volatilities; OBR/ONS).
- [Mortality data](MORTALITY.md) — ONS 2024-based cohort life tables.
- Historical stress-test data — the Jordà–Schularick–Taylor Macrohistory database (UK, 1871–2020).
