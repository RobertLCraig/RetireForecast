# Pin the Support for Mortgage Interest rate and capital cap to a published source

## Why
Card 0045 built Support for Mortgage Interest into the engine and put two figures on
`Benefits\SupportForMortgageInterest`. Both reach a projection, and neither is read off a published
table:

- **The DWP standard interest rate, 2.09% a year** (`STANDARD_RATE_BPS`). The RULE is published and
  is stated correctly in the docblock: the rate tracks the Bank of England monthly average interest
  rate for loans secured on dwellings, and moves only when that average has differed from it by 0.5
  percentage points or more. The VALUE is the building session's own recollection of the low end of
  the range the rule has produced since the 2018 loan scheme began. It was chosen deliberately low,
  because understating help is the cautious direction, but "deliberately low" is not "correct".
- **The eligible capital limit, £100,000** (`ELIGIBLE_CAPITAL_LIMIT_PENCE`). The pension-age figure,
  half the working-age one, unchanged since the loan scheme began. Confidently believed, never
  fetched.

What it costs: unlike the figures on card 0106, **both of these move the answer.** The rate sets how
much interest is met each year, which lowers spending and raises the charge on the home; the cap sets
how much of the mortgage is eligible at all. A rate that is too low makes SMI look worse than
equity release by more than it really is, which is the exact comparison card 0045 existed to
correct.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0045 could ship and disclose the figures but could not go and check them.

## Links

**Relates to**
- `0045` - introduced both figures, the mechanism, and the disclosure that reads them.
- `0085`, `0086`, `0087`, `0091`, `0092`, `0095`, `0106` - the same shape of gap, from the same
  missing web access. Whoever picks one up can settle several in one research pass.

## Not this card
The mechanism. What is met, how it is capped, how it accrues and how it is repaid are card 0045's
and are built. This card only replaces two numbers and adds their citations.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for the DWP standard interest rate and the pension-age eligible capital limit in docs/spec/ASSUMPTIONS.md section 21, or record there that the search found none. proves: manual
- [ ] WHEN a published figure differs from the shipped one, THE APP SHALL use the published one, and the interest met, the charge on the home and the result note SHALL all move with it. proves: `test_guarantee_credit_meets_the_mortgage_interest_at_the_dwp_standard_rate`
<!-- AC:END -->

## Tasks
- [ ] Fetch the current DWP standard interest rate for SMI from gov.uk and set `STANDARD_RATE_BPS`.
- [ ] Fetch the pension-age eligible capital limit and set `ELIGIBLE_CAPITAL_LIMIT_PENCE`.
- [ ] Delete the "SOURCING GAP" blocks on both constants once pinned, and move ASSUMPTIONS section
      21 out of the sourcing-gap list.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if either figure moves, and note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Both numbers are
constants at the top of `packages/finance-engine/src/Benefits/SupportForMortgageInterest.php`, and
nothing restates them: the projector, the result note and the tests all read the constants. Run
`php artisan test` after; `SupportForMortgageInterestTest` derives its expected pence from the
constants, so a corrected rate does not redden it, but `InputNotesTest` asserts the literal
`2.09% a year` in the note text and will need that one string changed.

Two modelling calls ride on the rate and are worth revisiting in the same pass, because a better
figure may make them unnecessary: the charge is rolled up at the standard rate rather than at the
separate gilt-linked rate DWP charges on the loan itself, and the interest met is capped at the
interest actually charged that year.

## Comments
