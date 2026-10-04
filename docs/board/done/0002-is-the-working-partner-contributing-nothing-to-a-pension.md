# Is the working partner really contributing nothing to a pension?

## What I need from you

**Check a payslip or scheme booklet: does the employed partner pay into a workplace pension, and at
what member and employer rate?**

**Pass** is two percentages, or a confirmed nil with the reason for it.

**Fail** is a guess. An assumed rate puts an unverified number into every result that follows, and
the current stored value of zero is itself the unverified assumption this card exists to settle.

**Why it needs you** It is a fact about somebody's employment rather than anything the model can
derive, so no amount of auditing reaches it.

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

**2026-08-18** Several scenarios were created that simulate the working person being beyond retirement age and therefore no longer contributing to a pension. (Unsure how correct this might turn out to be.)

**2026-10-04** RESULT: done
TOUCHED: 0156 (todo) - flag on the result page and PDF any person earning a salary with nothing paid into a pension; the already-retired scenarios the answer describes are already covered by the no_salary note, so only the still-earning ones were silent.
TOUCHED: 0157 (human-review) - the answer did not give the payslip rates, so this asks for the member and employer percentages (or a confirmed nil) and the date the working partner really stops work, which decides whether the already-retired scenarios are right.
