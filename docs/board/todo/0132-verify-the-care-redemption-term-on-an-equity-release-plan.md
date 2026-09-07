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
