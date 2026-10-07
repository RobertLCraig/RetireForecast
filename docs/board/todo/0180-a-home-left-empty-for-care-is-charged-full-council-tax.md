# A home left empty for care is charged council tax as if somebody still lived there

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
