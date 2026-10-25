# A home left empty for care is charged council tax as if somebody still lived there

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 1 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
Card 0047 #2 made the single-person discount turn on who lives in the home, so a partner in
permanent care leaves the other a single occupant. It left one case alone. When EVERY living member
is in care, nobody lives in the home, and `PathProjector` falls back to the living count: a lone
resident gets 25% off, and a couple both in care pay the full bill.

In England a dwelling left unoccupied by somebody who now lives in a care home is exempt (Class E,
Council Tax (Exempt Dwellings) Order 1992). So the plan charges a bill the household would not
pay, for every year both are in care and the home is not yet sold. That is the cautious direction,
which is why it was not fixed in 0047 without the source fetched.

## Links

**Relates to**
- `0047` - built the occupant count this card would extend.
- `0111` - the other council tax figures still waiting to be verified.

## Not this card
The 0047 occupant rule itself. Selling the home to fund care.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHILE every living member of the household is in permanent care and the home is unsold, THE APP SHALL charge no council tax on it, with the exemption sourced and dated. proves: `test_a_home_left_empty_for_care_is_exempt_from_council_tax`
<!-- AC:END -->

## Tasks
- [ ] Fetch and cite the Class E exemption, with a verified_on date
- [ ] Charge nothing in `councilTaxNominal` when the occupant count is zero

## Comments
**2026-10-07** Raised from card 0047's rework. The session had no web, so the exemption is stated
from the regulation's name and was not fetched.

**2026-10-23** RESULT: partial
TESTS: +1 new, all green (php artisan test; no pest.bat here)
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Benefits/CouncilTax.php
packages/finance-engine/tests/Forecast/CouncilTaxTest.php
app/Forecast/ScenarioForecaster.php
docs/spec/ASSUMPTIONS.md
docs/HANDOVER.md
OUT-OF-SCOPE: none

Built: councilTaxNominal charges nothing when no living member is at home (all in permanent care, home unsold). The old fallback to the living count is gone at both call sites. test_a_home_left_empty_for_care_is_exempt_from_council_tax was watched failing first (200000 charged, 0 expected), for a couple and a lone member. ENGINE_VERSION is now finance-engine/empty-home-in-care-pays-no-council-tax; the re-run is owed for plans where every living member reaches care.
Not met, #1: it asks for the exemption 'sourced and dated'. WebFetch was refused in this session, so Class E (SI 1992/558 art. 3, https://www.legislation.gov.uk/uksi/1992/558/article/3) is cited as STATED, not verified, with no verified_on date, the same way ASSUMPTIONS §23 holds the other council tax rules (card 0111). To finish: fetch that page, confirm Class E covers an owner or tenant now resident in a care home, and add verified_on in CouncilTax.php and ASSUMPTIONS §23.
Assumed: a let-and-rent plan's rented home left empty for care is exempt too (Class E covers tenants).
HANDOVER.md is at its 40 KB limit, so the 0047 bullet got one short clause only.

**2026-10-23** RESULT: partial
TESTS: +1 new, all green (php artisan test; no pest.bat here)
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Benefits/CouncilTax.php
packages/finance-engine/tests/Forecast/CouncilTaxTest.php
app/Forecast/ScenarioForecaster.php
docs/spec/ASSUMPTIONS.md
docs/HANDOVER.md
OUT-OF-SCOPE: none

Built (commit b1336fcf, the first take): councilTaxNominal charges nothing when no living member is at home (all in permanent care, home unsold); the fallback to the living count is gone at both call sites. test_a_home_left_empty_for_care_is_exempt_from_council_tax was watched failing first (200000 charged, 0 expected), for a couple and a lone member. ENGINE_VERSION is finance-engine/empty-home-in-care-pays-no-council-tax; the re-run is owed for plans where every living member reaches care.
Second take re-ran the full suite (green) and pint (clean), and changed no file.
Not met, #1: it asks for the exemption 'sourced and dated'. WebFetch was refused again in this session, so Class E (SI 1992/558 art. 3, https://www.legislation.gov.uk/uksi/1992/558/article/3) stays STATED, not verified, with no verified_on date. To finish, in a session with web: fetch that page, confirm Class E covers an owner or tenant now resident in a care home, and add verified_on in CouncilTax.php and ASSUMPTIONS §23. This is the only thing left; a loop session cannot do it.
Assumed: a let-and-rent plan's rented home left empty for care is exempt too (Class E covers tenants).
Still needs a browser check (Herd serves C:\Dev\RetireForecast, not this worktree).

**2026-10-23** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 1 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-23 manager (m20261023174912-ba17)

**outcome: rob**

The code and the test are done, and the only thing left is a web check that the unattended loop cannot do.

**what the session said**

The code work is done. The one open item is a web check, and the build loop has no web access. If I send the card back to the loop, it will park the card again.

- **Checked:** `CouncilTax.php` (the docblock above `PathProjector::councilTaxNominal`) cites Class E, SI 1992/558 art. 3. It says the citation was not fetched and has no `verified_on` date.
- **Checked:** the test `test_a_home_left_empty_for_care_is_exempt_from_council_tax` is in `packages/finance-engine/tests/Forecast/CouncilTaxTest.php`.
- **Still open:** #1 asks for the exemption to be "sourced and dated". The builder tried twice. Both times the session refused WebFetch, so the builder could not do it.
- **What you do:** in a session that has web access, open https://www.legislation.gov.uk/uksi/1992/558/article/3. Confirm that Class E covers an owner or a tenant who now lives in a care home. Then add a `verified_on` date in two places: `CouncilTax.php` and `docs/spec/ASSUMPTIONS.md` §23.

WHY: The code and the test are done, and the only thing left is a web check that the unattended loop cannot do.

OUTCOME: rob


**2026-10-25** **Research, 2026-10-25 (attended session, web)**
