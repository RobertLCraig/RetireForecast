# Where does the missing £49,495 in the base plan come from?

## What I need from you
Say where the roughly £49,495 comes from, in one line. Or say "nowhere yet": then this card waits on card 0022, which may change the plan so the gap shrinks or goes.
**My recommendation:** name the real source if there is one. An agent then enters it in the app as money in and the same amount out to the lender, in 2026.
Paste to answer: `**2026-10-07** **Decided:** The £49,495 comes from <source>.` or `**2026-10-07** **Decided:** No source yet; wait for 0022.`

## What you need to know
- The base plan (scenario 9) repays the old mortgage with a new £160,000 repayment loan. The redemption figure is £208,000.
- That leaves £48,000 short, plus £1,495 broker fees. Total about £49,495.
- Today the plan just assumes this money turns up. Nothing shows where from. So every result for the base plan rests on money from nowhere.
- Two answers already exist elsewhere. The £80,000 art sale is modelled in scenarios 47 and 48. The 50+ interest-only mortgage (card 0022) lends £199,000, which cuts the gap to £9,000 but does not close it.
- The trap: entering the money in without the matching payment out to the lender would invent £49,495 to spend.

## See it
- Base plan builder, step 3: <https://retireforecast.test/scenarios/9/edit>
- Card 0022 (which mortgage route): `docs/board/human-review/0022-interest-only-and-rio-indications-change-the-keep-the-flat-routes.md`

---
## For the agent (Rob can stop reading here)
After the answer: at builder step 3 on scenario 9, add a one-off capital receipt in 2026 labelled with the real source, plus a matching one-off cost the same year (the money goes straight to the lender). Never the receipt alone. Then run `php artisan scenarios:audit`; it must not report a new problem. If the answer is "wait for 0022", add `needs: 0022` and move this card to `todo/`.

## Links

**Relates to**
- `0022` - picks the mortgage route; its interest-only loan would cut this gap to about £9,000.

## Comments
