# The working partner's payslip pension rates, and when they stop work

## What I need from you

**Two answers, from a payslip or the scheme booklet.**

1. While the working partner still works: what percentage do they pay into a workplace pension, and
   what percentage does the employer pay? Or say "nil" and why (for example, opted out).
2. When does the working partner actually stop work: an age or a date?

---

**Pass** is two percentages (or a nil with its reason), plus one age or date.

**Fail** is a guess. A guessed rate goes into every result that follows. If you cannot find a
payslip, say so here and the scenarios stay as they are, with the gap flagged on screen (card 0156).

**Why it needs you.** Both are facts about one person's job. No reading or research reaches them.

## Why
Card 0002 asked whether the working partner really pays nothing into a pension, because no stored
scenario records any payment. The answer was that several scenarios model the partner as already
past retirement, so they earn nothing and pay nothing in, and that you are unsure how right that is.

That explains the zero only in the "already retired" scenarios. The scenarios where the partner
still earns still show nothing paid in, and nobody has checked a payslip. So question 1 is still
open. Question 2 follows from it: the "already retired" scenarios are only correct if the partner
really will have stopped by then, and the date they stop decides how many years of payments count.

## Links

**Relates to**
- `0002` - its answer explained the zero only for the already-retired scenarios and left the
  payslip rates and the real stop date unknown.
- `0156` - flags the missing payment on screen meanwhile; it is built whatever you answer here.

## Options
1. **Give the rates and the stop date.** Costs finding one payslip. An agent then enters the rates
   on the pension at builder step 3, sets the relief method to "net pay" (already decided, see
   `docs/build/PLAN-adviser-parity.md`, "Decisions resolved" #2), and sets the retirement age in
   the still-earning scenarios. Stored scenarios are re-run, so figures move up.
2. **Confirm nil, with the reason, and give the stop date.** Costs the same look. Nothing about
   pensions changes; only the stop date is set. The flag from card 0156 keeps showing, because
   the app cannot yet tell a confirmed nil from a blank. Silencing it needs one more small card.
3. **Leave it unknown.** Costs nothing now. Every pension figure for the working partner stays a
   floor, and the flag from card 0156 keeps saying so.

## Recommendation
Option 1. Most employees are auto-enrolled, so a real payment is likely, and every pension figure
for the working partner is low until it is entered. One payslip settles both questions.

Paste, with the figures filled in:

`**2026-10-04** **Decided:** Option 1. Member pays __%, employer pays __% of gross salary. They stop work at age __ (or on __).`

## Comments
