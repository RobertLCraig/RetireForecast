# Research: published sources for the tenant-referencing multiples and rent in advance

*Researched 2026-10-11 for board card 0091 (pin the tenant-referencing multiples to a published source). All sources read 2026-10-11.*

## The question

`packages/finance-engine/src/Housing/Tenancy.php` ships three unsourced figures, used by the
referencing flag (`PathProjector::rentReferencingWarnings`) and disclosed in `docs/spec/ASSUMPTIONS.md` §15:

| Constant | Shipped | Meaning in the copy |
|---|---|---|
| `REFERENCING_INCOME_MULTIPLE` | 30 | gross ANNUAL income a standard reference asks for, as a multiple of the MONTHLY rent |
| `GUARANTOR_INCOME_MULTIPLE` | 36 | the same bar for a guarantor, on the guarantor's own income |
| `ADVANCE_MONTHS_MIN` / `ADVANCE_MONTHS_MAX` | 6 / 12 | months of rent in advance a landlord asks of an applicant who fails referencing, "asked for again at every renewal" |

The card also asks whether the multiple varies with rent level or region, and whether rent in advance
is really re-asked at every renewal. The deposit cap (Tenant Fees Act 2019 Sch 1 para 2) is statute
and out of scope.

## Findings

### 1. Tenant income multiple: 30x monthly rent is the published standard

- **HomeLet** (the largest UK referencing provider; Barbon Insurance Group, which also runs Let
  Alliance), *Referencing Service Guidelines and Service Level Agreement*, document code
  `HL202407 0823`, p.8 "Income-to-rent ratio": the ratio "must equal: 2.5 x the annual rent (or the
  individual's proportion of the annual rent), or 3 x the rent amount x the tenancy duration if the
  applicant is being referenced on independent means, or 3 x the annual rent if being assessed as a
  guarantor." Its outcome table: tenant ratio 0 to 2.47 = "Acceptable with a guarantor", 2.48 and
  above = "Acceptable". 2.5 x 12 = **30x the monthly rent**; the effective pass line is 2.48 x 12 =
  29.76x. p.11: "A pension income must be 2.5 x the annual rent to be acceptable."
  https://connect.homelet.co.uk/assets/connect/pdf/Referencing_Service_Guidelines.pdf (read 2026-10-11;
  WebFetch is blocked on that host, the PDF was downloaded with a browser user agent and read with
  pdftotext).
- **HomeLet** tenant page restates it as "30 times higher than the monthly rent", example £800 a month
  needs £24,000 a year. https://homelet.co.uk/tenants/tips-for-tenants/tips-for-being-referenced (search
  result snippet, read 2026-10-11).
- **Goodlord**, *Understanding Goodlord's Referencing API* (20 Aug 2024): "Goodlord recommends an
  Affordability Ratio of 2.5 for Applicants and 3 for Guarantors, but this can be adjusted as needed."
  Formula: monthly price share x 12 x ratio; example £1,000 x 12 x 2.5 = £30,000.
  https://portal.goodlord.co/blog/2024/8/20/getting-started-with-goodlord-referencing-api (read 2026-10-11).
- **Direction of travel (not a standard):** Zero Deposit modelled an industry move from 2.5x to 3x
  annual rent (36x monthly) after the rent-in-advance ban; reported as a scenario only.
  https://www.property118.com/guarantor-reliance-surges-as-renters-face-growing-affordability-pressures/
  (search summary, read 2026-10-11). HomeLet's VISTA product (from Feb 2024) adds outgoings and credit
  commitments on top of the ratio. https://homelet.co.uk/letting-agents/knowledge-hub/news/the-future-of-affordability-assessments
  (last updated 04/03/2024, read 2026-10-11).

**Sources do not disagree:** both providers that publish a figure say 2.5x annual = 30x monthly. The
stricter 36x for tenants appears only as a modelled scenario, not as any provider's criterion, so it is
not a defensible default. 30 is already fractionally stricter than HomeLet's own 29.76 pass line,
which satisfies the adverse-default rule.

**Rent level and region:** neither HomeLet nor Goodlord varies the ratio by rent level or region. Goodlord
lets each agent configure it. A single constant is the right shape for the income route.

### 2. Guarantor multiple: 36x monthly rent is the published standard

- HomeLet guidelines (above), p.8: "3 x the annual rent if being assessed as a guarantor"; p.9 guarantor
  criteria: "An annual income of at least 3 x the annual rent." 3 x 12 = **36x monthly**.
- Goodlord API guide (above): ratio 3 for guarantors = 36x monthly.

**Does not support "homeowner":** HomeLet's guarantor criteria are a Tenancy Application Score, a good
credit history (at most one declared CCJ up to £300) and 3x annual rent income. They do not require
the guarantor to own a home, and say that where rent protection is not required "a guarantor can be an
overseas resident but must be employed". No source read requires a UK homeowner guarantor. The copy's
"UK homeowner guarantor" and "a household that has just sold no longer has a homeowner in it" are
therefore **unsourced** (they may be individual agents' practice; none was found published).

### 3. The copy's "it is an INCOME test, the sale money does not count" is contradicted by HomeLet

HomeLet guidelines, p.11-12 "Living on independent means": proof of income may be "most recent
statements [that] show at least 3x the sum of the total rent for the tenancy term" (dated in the last
30 days, lowest balance taken), investment income of £30,000 or more (50% counted), or "A solicitor's
letter dated within the last 30 days showing sufficient equity from a house sale that has been fully
completed and confirms the funds are available". The independent-means outcome table: ratio 3 or
above = "Acceptable". So under HomeLet's published criteria **capital from a completed house sale can
pass referencing**, at 3x the total rent for the tenancy term (36 months' rent in hand for a 12-month
tenancy). Secondary letting-agent sources also cite a smaller savings route (6x monthly rent held for
6 months), but no referencing provider was found publishing that one, so it is not relied on here.

Caveat: the HomeLet document predates the Renters' Rights Act. Since 1 May 2026 new assured tenancies
in England are periodic, so "tenancy term" has no fixed length; how HomeLet now sizes the
independent-means test was **not found**. This finding changes the mechanism, not just a number, so it
belongs on a new card (see below).

### 4. Rent in advance: 6 to 12 months is now unlawful in England

- **Renters' Rights Act 2025 c.26 s.8** inserts Housing Act 1988 s.4B, "Assured tenancy: prohibition
  of rent in advance (except initial rent)": terms of an assured tenancy making rent due in advance
  "are of no effect", except tenancies entered into before commencement, excepted (social/supported/
  homelessness) tenancies, and initial rent in the permitted pre-tenancy period.
  https://www.legislation.gov.uk/ukpga/2025/26/section/8 (read 2026-10-11).
- **s.9** amends the Tenant Fees Act 2019 Sch 1 para 1 so rent payable before the tenancy is entered
  into is a prohibited payment. https://www.legislation.gov.uk/ukpga/2025/26/section/9 (read 2026-10-11).
- **gov.uk, Rent payments and deposits** (landlord guidance): "You can only ask for rent in advance
  during the 'pre-tenancy period'... after you and your tenant have signed the tenancy agreement and
  before the date the tenancy starts"; for monthly rent "the maximum amount you can usually ask for is
  one month's rent", otherwise 28 days. Accepting rent a tenant *offered* in advance is still reportable.
  https://www.gov.uk/guidance/renting-out-your-property-guidance-for-landlords-and-letting-agents/rent-payments-and-deposits (read 2026-10-11).
- **gov.uk, Asking for rent in advance: guidance for local authorities** (updated 8 May 2026): applies to
  assured periodic private tenancies with agreements signed, and payments made, on or after
  **1 May 2026**; during the tenancy a landlord cannot require earlier payment and contrary clauses are
  of no effect (a tenant may still choose to pay early); first breach civil penalty up to £5,000, repeat
  within 5 years up to £30,000. Implements RRA ss.8-9.
  https://www.gov.uk/government/publications/asking-for-rent-in-advance-guidance-for-local-authorities/asking-for-rent-in-advance-guidance-for-local-authorities (read 2026-10-11).
- Industry confirmation that the ban removed the route: HomeLet/Barbon's Mike Dawson cites "the ban on
  rent in advance" as a driver of guarantor use rising from about 9% (Jan 2025) to 18.4% (Jul 2026) of
  accepted applications. https://www.property118.com/guarantor-reliance-surges-as-renters-face-growing-affordability-pressures/
  (search summary, read 2026-10-11).
- **Scotland differs:** "They cannot charge more than 6 months' rent in advance" (mygov.scot, updated
  7 May 2026), resting on Rent (Scotland) Act 1984 s.89 as applied to private residential tenancies.
  https://www.mygov.scot/tenant-new-tenancy (read 2026-10-11).
- **Wales and Northern Ireland: not researched; no source found.**

**Renewal:** the copy's "asked for again at every renewal" has no basis in England now. Assured
tenancies from 1 May 2026 are periodic with no fixed term to renew, and rent cannot be required in
advance during the tenancy. A fresh reference happens at a move, not a renewal. No source was found
supporting re-asking at renewal even before the Act.

**Is 6-12 months sourced anywhere?** No provider or official source was found publishing a 6-12 month
norm. Pre-Act, Zero Deposit data (via Property118) put 21.5% of renters as paying more than one month
in advance, with no typical length. Scotland's statutory ceiling is 6. So 12 has no source at all, and
6 is sourced only as Scotland's legal maximum.

## Recommended figures for the build

The app treats England (and Wales/NI) as the default jurisdiction; these are England figures.

| Constant | Value | Source URL | verified_on |
|---|---|---|---|
| `REFERENCING_INCOME_MULTIPLE` | **30** (unchanged) | https://connect.homelet.co.uk/assets/connect/pdf/Referencing_Service_Guidelines.pdf (p.8, 2.5x annual rent) and https://portal.goodlord.co/blog/2024/8/20/getting-started-with-goodlord-referencing-api (ratio 2.5) | 2026-10-11 |
| `GUARANTOR_INCOME_MULTIPLE` | **36** (unchanged) | same two sources (HomeLet p.8-9, 3x annual rent; Goodlord ratio 3) | 2026-10-11 |
| `ADVANCE_MONTHS_MIN` / `ADVANCE_MONTHS_MAX` | **delete both**, replace with `RENT_IN_ADVANCE_CAP_MONTHS = 1` | https://www.legislation.gov.uk/ukpga/2025/26/section/8 and https://www.gov.uk/guidance/renting-out-your-property-guidance-for-landlords-and-letting-agents/rent-payments-and-deposits (one month, England, tenancies from 1 May 2026) | 2026-10-11 |

Adverse default: 30 and 36 are the published figures and no published figure is stricter. Removing rent in
advance as a workaround is the cautious reading: it makes the flag's "way round" harder, never easier.

## What the build session should change

The build session has no web; everything it needs is above.

1. `Tenancy.php`: add docblock citations to `REFERENCING_INCOME_MULTIPLE` and `GUARANTOR_INCOME_MULTIPLE`
   ("HomeLet Referencing Service Guidelines HL202407, p.8: 2.5x / 3x annual rent; Goodlord API guide
   ratio 2.5 / 3. verified_on 2026-10-11"). Values unchanged.
2. `Tenancy.php`: delete `ADVANCE_MONTHS_MIN` / `ADVANCE_MONTHS_MAX` and add
   `RENT_IN_ADVANCE_CAP_MONTHS = 1` cited to RRA 2025 s.8 (Housing Act 1988 s.4B) and the gov.uk page,
   verified_on 2026-10-11. Keep `rentInAdvance()` only if something still calls it after step 3.
3. `PathProjector::rentReferencingWarnings` copy (AC2: the copy moves with the sourced figures):
   - Replace the rent-in-advance route with the fact: since 1 May 2026 a landlord in England can ask
     for at most one month's rent in advance, so paying months up front is no longer a way round a
     failed reference.
   - Drop "and asked for again at every renewal" (no source; tenancies are periodic now).
   - Drop "homeowner" from "a UK homeowner guarantor" and the sentence "a household that has just sold
     no longer has a homeowner in it" (HomeLet does not require a homeowner guarantor). Keep the
     guarantor at 36x.
   - "the money from the sale does not count towards it, however large it is" is contradicted by
     HomeLet's independent-means route (finding 3). Minimum fix on this card: soften to "a standard
     income reference does not count the sale money; some referencing firms will accept proof of
     funds from a completed sale instead, at around three times the rent for the whole tenancy".
     Modelling that route belongs on a new card.
4. Tests: `packages/finance-engine/tests/Housing/TenancyReferencingTest.php` (the
   `rentInAdvance(... ADVANCE_MONTHS_MIN/MAX)` assertions) and
   `tests/Feature/Forecast/RentReferencingNoticeTest.php` (the "`MIN` to `MAX` months of it" assertion)
   reference the deleted constants; repoint them at the new copy. No `ENGINE_VERSION` bump: the flag
   changes no projected number.
5. `docs/spec/ASSUMPTIONS.md` §15: replace the 6-to-12-months clause with the one-month statutory cap,
   add the URLs and verified_on 2026-10-11 above, and move the referencing multiples out of the
   sourcing-gap paragraph. Record there that no source requires a homeowner guarantor and that Wales/NI
   rent-in-advance rules were not researched. Update the matching sentence in `docs/DECISIONS.md` (the
   0031 entry quoting "6 to 12 months' rent in advance").
6. Raise a new card: "Referencing can be passed on capital (HomeLet independent means: 3x total
   tenancy rent in statements, or solicitor's letter of sale equity)". The flag currently fires on
   households this route would pass; post-RRA sizing of "tenancy term" for periodic tenancies is unknown
   and needs its own research.
