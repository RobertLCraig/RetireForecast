# The intestacy and capped-exemption rules are stated, not verified

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
Card 0054 put the intestacy rules into the first death, and they reach a projection: every stored
plan that models Inheritance Tax now pays tax on the first death unless a will is ticked. The rules
behind it were written from the statute as the building session understood it, with **no web access
to check any of it**, so nothing in it carries a `verified_on` date. The house rule is that every
tax figure carries a source URL and a verified-on date, and none of these do.

Four things need a primary source before this can be read off:

- The **statutory legacy** of £322,000 on `Iht\InheritanceTaxCalculator::STATUTORY_LEGACY_PENCE`,
  and its start date of 26 July 2023. The engine also FREEZES it rather than uprating it, which is
  the cautious direction but is a modelling choice nobody has checked against how often the fixed
  net sum is in fact reviewed.
- The **split itself**: that a surviving spouse takes the personal chattels, the statutory legacy
  and half the residue, and the issue take the other half.
- The **Northern Ireland figure**, which differs from the England and Wales one. `RegionProfile`
  bundles the two, so a Northern Irish household is currently modelled on the England and Wales
  sum with nothing on any screen to say so.
- The **cap on the spouse exemption** where the recipient is not a UK long-term resident: that it
  stops at the nil-rate band, that the test is long-term residence rather than domicile from
  6 April 2025, and what the election that removes it costs.

## Links

**Relates to**
- `0054` - built the rules this card sources.
- `0122` - the standing sourcing-gap card from the same review.
- `0125` - the same gap for the downsizing addition.

## Not this card
Changing the arithmetic. If the source says the engine has it wrong, that is a new card.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL cite each of the four items above to a primary source with a `verified_on` date. proves: none
- [ ] THE APP SHALL record whether the checked rules match what the engine computes. proves: none
<!-- AC:END -->

## Tasks
- [ ] **Needs web, so not an unattended card.**
- [ ] Pin the legacy to the Administration of Estates Act 1925 s.46 and SI 2023/758.
- [ ] Pin the capped exemption to IHTA 1984 s.18(2) and the long-term-residence rules in FA 2025.
- [ ] Update the constant's docblock and docs/spec/ASSUMPTIONS.md §27 with the date checked.

## Comments

**2026-10-17** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

The card's own first task says it needs web and is not an unattended card. That held: in this session WebFetch (https://www.legislation.gov.uk/uksi/2023/758/made) and WebSearch were both refused with a permission prompt nobody can answer unattended. Both criteria need a primary source read on a stated date, so neither can be met here, and writing a verified_on date for a page nobody opened would be exactly the invented figure the house rule forbids.

What the repository already holds, for whoever takes this with web: InheritanceTaxCalculator.php:52-68 and docs/spec/ASSUMPTIONS.md:608-613 already NAME the sources (AEA 1925 s.46(1)(i) as amended by SI 2023/758, the legislation.gov.uk URL, IHTA 1984 s.18(2)) but carry no verified_on date. Nothing in the repo names a source for the Northern Ireland fixed net sum or for the FA 2025 long-term-residence test and the cost of the s.267ZA election. Those are the four checks to make, then the docblock and ASSUMPTIONS §27 get the date.

Next step: run this card in an interactive session with WebFetch allowed, or have Rob grant WebFetch for legislation.gov.uk and gov.uk to the card sessions.

**2026-10-18** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second unattended take, same block as the first. WebFetch (https://www.legislation.gov.uk/uksi/2023/758/made) and WebSearch were both refused again with a permission prompt nobody can answer unattended. Both criteria need a primary source read on a stated date. Writing a verified_on date for a page nobody opened would be the invented figure the house rule forbids, so nothing was written.

The card's own first task says it is not an unattended card, and two takes have now confirmed that. Re-queuing it for the loop will block a third time. It needs either an interactive session with WebFetch allowed, or Rob granting WebFetch for legislation.gov.uk and gov.uk to card sessions. The previous comment's list of what the repo already names (InheritanceTaxCalculator.php:52-68, ASSUMPTIONS.md:608-613) and what it lacks (the NI fixed net sum, the FA 2025 long-term-residence test and the s.267ZA election cost) still holds.

**2026-10-18** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-18 manager (m20261018043004-f220)

**outcome: rob**

The only work left needs web access, and only Rob can give it or do the work himself.

**what the session said**

The loop cannot finish this card. Both criteria need someone to read the law on the web. Card sessions have no web, so both takes were blocked.

I checked the code. `InheritanceTaxCalculator` still marks every figure "verified_on: NOT VERIFIED". That includes the docblock on `STATUTORY_LEGACY_PENCE` and the docblock on the spouse-exemption cap. So nothing is fixed yet. Both criteria are still open.

A reopen will block a third time. Nothing in the review proves a criterion wrong. The builder only lacks web access.

You must do one of these two things:
- **Run the card yourself** in an interactive session that has WebFetch. I recommend this. It is one short job.
- **Let card sessions use WebFetch** for `legislation.gov.uk` and `gov.uk`. This also fixes cards 0122 and 0125.

After that, move the card back to `todo/`.

WHY: The only work left needs web access, and only Rob can give it or do the work himself.

OUTCOME: rob


**2026-10-25** **Research, 2026-10-25 (attended session, web)**
