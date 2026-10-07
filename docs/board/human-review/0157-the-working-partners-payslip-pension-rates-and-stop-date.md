# The working partner's pension contributions, and when they stop work

## What I need from you
From a payslip or the pension scheme booklet:
1. What percentage does the working partner pay into a workplace pension, and what percentage does the employer pay? Or "nil", with the reason (for example, opted out).
2. When do they actually stop work: an age or a date?

Pass: two percentages (or nil with its reason), plus one age or date. A guess is a fail: it goes into every result. If you cannot find a payslip, say so and the gap stays flagged on screen.

**My recommendation:** give the real figures. Most employees are auto-enrolled, so a payment is likely. One payslip answers both.
Paste to answer: `**2026-10-07** **Decided:** Member pays __%, employer pays __% of gross salary. They stop work at age __ (or on __).`

## What you need to know
- No stored scenario records any pension payment for the working partner, even the ones where they still earn. So their pension figures are a floor.
- Some scenarios already treat them as retired. Those are right only if they really have stopped by then.
- Question 2 also answers card 0022's question: both leading plans assume working to 72. If they stop sooner, those plans drop to 79.4% and 85.0%.
- The private benefits notes (`docs/BENEFITS-CHECK-V2.local.md`) give a retirement age that contradicts working to 72. Your answer settles which is right.

## See it
- Pension details at builder step 3: <https://retireforecast.test/scenarios/9/edit>

---
## For the agent (Rob can stop reading here)
Rates given: enter them on the pension at builder step 3, relief method "net pay" (decided, `docs/build/PLAN-adviser-parity.md`, "Decisions resolved" #2), set the retirement age in the still-earning scenarios, re-run. Confirmed nil: set only the stop date; silencing card 0156's flag needs a small new card (the app cannot tell a confirmed nil from a blank). Copy the stop-date answer to card 0022. Related: card 0002 (which found the zero), card 0156 (the on-screen flag).

## Comments
