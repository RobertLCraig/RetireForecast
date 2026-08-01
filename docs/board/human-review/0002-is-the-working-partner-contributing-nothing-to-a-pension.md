# Is the working partner really contributing nothing to a pension?

## What I need from you
Check a payslip or scheme booklet and tell me whether the employed partner pays into a workplace
pension, and at what rate (member and employer). An agent cannot settle this: it is a fact about
their employment, not something the model can derive, and assuming a figure would put an
unverified number into a result.

Pass: a member and employer percentage, or a confirmed nil with the reason.

## Why
Found 2026-07-31. **No stored scenario records any DC contribution, member or employer, on any
of the 14.** An employee in a workplace scheme normally contributes under auto-enrolment
(typically ~5% member + 3% employer), so if this is simply not entered, the forecast understates
their pension and now its tax relief too. Deliberately not fixed by assuming a figure.

## Options
1. **It is correct: they contribute nothing.** No change. The forecast stands.
2. **It was never entered.** Add the real contribution at builder step 3 and set the pension's
   relief method to **net pay** (recorded answer, PLAN-adviser-parity "Decisions resolved" #2),
   or the results page will say relief is not being modelled.
3. **Unknown, so model a range.** Enter auto-enrolment minimums as a sensitivity rather than a
   fact, clearly labelled as an assumption.

## Recommendation
Option 2 if the contribution exists, and it probably does. This is a factual question about the
real couple, not a modelling call, so it should be answered from a payslip rather than reasoned
about. Until it is answered, treat every pension figure for the working partner as a floor.

## Decided
<!-- -->
