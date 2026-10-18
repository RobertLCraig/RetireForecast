# Verify that permanent care redeems a lifetime mortgage, and how long the lender gives you

## Why
Raised by card 0056, which built the rule. That card made permanent entry into residential care by
the last surviving borrower call in a roll-up balance: the home is sold in that year and the lender
is paid first. It is now a modelled event that moves home equity, the care financial assessment and
the estate, and it drives new results copy.

Every statement behind it is **STATED, not verified**. Card 0056 was built unattended, and an
unattended session on this board has no web access, so nothing could be fetched. Written up at
[docs/spec/ASSUMPTIONS.md](../../spec/ASSUMPTIONS.md) section 29.

Three things need a source:

1. That a standard lifetime mortgage falls due on the last borrower's permanent move into long-term
   care, alongside death and sale. This is the Equity Release Council product standard as it is
   usually described, and it is what `Property::$mortgageRollUpRate`'s docblock has always said, but
   no contract or Council publication is cited anywhere in the repository.
2. How long the lender actually gives before the home must be sold. Tariffs typically allow some
   months of notice and grace; the engine sells in the FIRST care year, which is the adverse end.
3. That the twelve-week property disregard and a council deferred payment agreement are not
   normally available on a home already charged to a lender. This one drives no arithmetic, but it
   is asserted to the reader in the results note, which is the strongest claim on that screen.

## Links

**Relates to**
- `0056` - built the rule and wrote the gap up.
- `0129` - the same shape of gap on card 0055's figures.

## Not this card
Changing the rule. If a source says the notice period is material, that is a second card.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a `verified_on` date for each of the three statements in section 29, or a written record of why no authoritative source exists. proves: none
<!-- AC:END -->

## Tasks
- [ ] Fetch the Equity Release Council product standards and at least two lender tariffs.
- [ ] Replace section 29's "STATED, not verified" with the sources and dates, and add each URL to
      the source list at the foot of that file.
- [ ] If the notice period turns out to be material, raise a card for it rather than changing the
      projector here.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. **Needs a session with web access**, so it cannot be
picked up by the unattended loop.

Doc only unless a source contradicts the rule. No test, no figure moves.

## Comments

**2026-10-18** RESULT: blocked
TESTS: +0 new, none run (no code or doc changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card needs the web. Its own Plan says so: "Needs a session with web access, so it cannot be picked up by the unattended loop." This unattended session tried WebSearch once (Equity Release Council lifetime mortgage repaid on long-term care) and the permission was refused. So no Equity Release Council standard and no lender tariff could be fetched, and ASSUMPTIONS section 29 still says STATED, not verified for all three statements.

I did not write section 29 from memory. A source URL with a verified_on date that nobody fetched is the exact invisible-figure fault this card exists to remove.

To finish it: Rob, or an attended session with WebSearch/WebFetch allowed, runs the card from C:\Dev\RetireForecast. Fetch the Council product standards and two or more lender tariffs (for example Aviva, Legal & General, more2life). Then replace the three STATED lines in docs/spec/ASSUMPTIONS.md section 29 with the sources and dates, and add the URLs to that file's source list. If the notice period is material, raise a card for it. Suggest the card gets not_for_the_loop: in its frontmatter so the loop stops picking it up.

**2026-10-18** RESULT: blocked
TESTS: +0 new, none run (no code or doc changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second unattended take, same block as the first. The card's own Plan says it needs a session with web access. This session tried WebSearch (Equity Release Council lifetime mortgage repaid on long-term care) and WebFetch (equityreleasecouncil.com standards page). Both were refused for permission. No Council standard and no lender tariff could be fetched, so ASSUMPTIONS section 29 still says STATED, not verified.

I did not write sources from memory. A URL with a verified_on date that nobody fetched is the invisible-figure fault this card exists to remove.

To finish it: Rob, or an attended session with WebSearch and WebFetch allowed, runs the card from C:\Dev\RetireForecast. Please add not_for_the_loop: to the card's frontmatter so the loop stops picking it up; this is the second take spent on the same refusal.
