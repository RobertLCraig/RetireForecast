# A real broker quote would firm up scenario 43's BTL rate

## What I need from you

**Ask a broker for a consumer buy-to-let indication, but only if scenario 43 (let out the flat) is
still a live candidate.** If it is not, say so and this card is dropped.

**Pass** is a quoted rate, which replaces the 5.75% market average and gets labelled as a quote.

**Fail** is no quote obtainable, and that is a fine outcome: the average stays, stays labelled as an
average, and the card is dropped rather than left waiting. Recheck 2026-09-01.

**Why it needs you** Chasing a broker is an outside-party job that has to come from you. Worth
knowing before you spend the call: scenario 43 already runs short in 2039, so a firmer rate is
unlikely to change the conclusion. The question this really settles is whether the let-to-let plan
is still on the table at all.

## Why
Not blocking, and not Rob's call to make alone. 5.75% is the sourced market average, not a
quote, and consumer buy-to-let is a narrower market than the average represents.

## Not this card
The park-home depreciation rate, which needs nothing further: no neutral UK index exists, so
-8%/yr ships as an openly-labelled judgement with four sensitivities. Both were researched and
decided, DECISIONS 2026-07-30.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a real quote is obtained, THE APP SHALL use it for scenario 43 and label it as a
      quote rather than a market average.
- [ ] #2 IF no quote is obtainable, THEN the 5.75% average SHALL remain and stay labelled as an
      average, and this card SHALL be dropped rather than left waiting.
<!-- AC:END -->

## Tasks
- [ ] Approach a broker for a consumer BTL indication

## Direction

**2026-08-19 - the expert panel says the rate is not the problem.** The let-to-let scenario is
modelled on **gross** rent: no management fee, no void, no repairs, no compliance, no licensing.
Realistic deductions run to roughly a quarter of gross rent. The service charge is also treated as
household spend when on a let property it is a deductible letting expense, while the full gross
rent is taxed as profit.

Corrected, the property reviewer turns the plan's modelled positive contribution into a real cash
loss - a swing far larger than any plausible move in the interest rate. His words: the rate is not
the problem, the missing costs are.

So the question this card really settles is unchanged - is the let plan still a candidate at all -
but a quoted rate will not rescue it. Card 0030 is the engine fix. Also unmodelled and real: the
proposed minimum energy efficiency standard for lets by 2030, and that the lease usually needs
freeholder consent to sublet and may prohibit it.

Detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md` (property finding 5).

## Comments

**2026-09-30** **Decided:** Dropped under its own acceptance #2: the 5.75% market average stays
and stays labelled as an average, and no broker is called. The card said itself that a quoted rate
would not change the conclusion, and the 2026-08-19 panel found the let plan fails on costs the
model leaves out (management, voids, repairs, the service charge as a letting expense), not on the
rate. That correction is card 0030 (todo) and card 0088 (in progress). If the let plan survives
those two and is still wanted, a new card asks for the quote then. Rob: say so here if you disagree
and it goes back to `todo/` unchanged.
