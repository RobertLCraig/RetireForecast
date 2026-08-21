# Confirm the State Pension figure, then make three benefits calls

## What I need from you

Decide how much of the four-item list below you do now, because the first item — the working
partner's State Pension forecast — is the single unverified number that decides whether this
household has a means-tested safety net at all, and the whole benefits layer of the model is
ranked on it. None of the four can be settled from this repository: they are a DWP login, a phone
call, a council tax bill and an award letter.

## Why

From the expert panel, 2026-08-19 (Citizens Advice findings 1, 2, 9 and the closing list, plus
adviser finding 1). Detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

### The four items are not one job

| # | Item | What it blocks | Where the answer comes from | Repo already holds it? |
|---|------|----------------|------------------------------|------------------------|
| 1 | Working partner's State Pension: the exact **weekly amount** and the **qualifying years** | The ranking itself, plus every benefits card | `gov.uk/check-state-pension`, or the DWP award letter — the claim is already lodged, so the letter states it outright | **No.** Flagged as "the one number not verified" in the gitignored `docs/SCENARIO-V2.local.md` |
| 2 | Free benefits check — Age UK **0800 678 1602** | Nothing in the model. It gets the claim, the carer's underlying entitlement and the council tax reductions **on file before they are needed** | Phone, then an appointment (often a home visit) | A desk check exists in the gitignored `docs/BENEFITS-CHECK-V2.local.md`. An adviser's check does not |
| 3 | Council tax **disabled band reduction** on the flat | Nothing in the model. **The only item that pays cash immediately** | The council tax bill, then the council. Needs a qualifying feature: an extra bathroom, a room used for the disabled person's needs, or space to use a wheelchair indoors. **Not means-tested**. Drops the bill a whole band | Rule and source recorded. Whether the flat has a qualifying feature is not |
| 4 | Older partner's disability award split into **care** and **mobility** rates | Card 0050 | The award letter | **Yes.** Both component rates are already recorded in `docs/SCENARIO-V2.local.md`. This is a five-minute confirmation, not research |

### Why item 1 decides everything else

The two rates are £3.30 a week apart, and the model uprates both with the same factor, so the gap
never closes on its own.

| If the working partner's forecast is… | Guarantee Credit | What follows |
|---|---|---|
| **the full new State Pension** (£241.30/wk, 2026/27) | **£0 for life** — above the £238.00/wk single Guarantee Credit line | No Support for Mortgage Interest, no Council Tax Reduction, no Warm Home Discount, no free TV licence at 75, no Cold Weather Payments, no help with NHS costs |
| **short of the full rate** (under £238.00/wk) | A live award | The whole passported chain above switches on, worth several thousand a year |

The tool currently carries the full-rate assumption, so it shows a Pension Credit award of zero and
no passported help. That is one of the two pillars of the argument for keeping the flat — pointing
the wrong way if the assumption is wrong. Pass for item 1 is a printed or screenshotted forecast
with the weekly amount **and** the qualifying years. If the record is short of full, also ask
whether voluntary contributions are still open: the payback is not the pension itself, it is that
dropping below the line re-opens everything else.

### Two corrections to the existing benefits file, while you are at it

- The advice **not** to apply for Attendance Allowance for the older partner is right — the
  protected award is kept.
- The advice to claim Carer's Allowance for underlying entitlement is right but **mistimed**.
  There is an earnings limit, so it fails while the working partner is still earning. The claim to
  lodge is the one that starts the day they retire.

## Options

1. **Item 1 only.** One login, or read the award letter that is already due. Everything else waits.
   *Cost:* the two items with long external clocks (the Age UK appointment, a council tax
   reassessment) do not start, so their wall-clock delay is added on later rather than run in
   parallel. Item 3 keeps not paying.
2. **All four, in one sitting.** Login plus one phone call plus reading two letters and a bill.
   *Cost:* an afternoon of admin, and two of the four (items 2 and 3) move nothing on the board at
   all — they pay in cash and in claim history, not in modelling.
3. **None now — declare the assumption instead.** Leave the model on the full-rate assumption, add
   it as a named, dated open assumption on every keep-the-flat result, and let the board carry on.
   *Cost:* every plan then gets ranked on one unverified number sitting £3.30/wk from flipping a
   multi-thousand-pound-a-year package on or off. If it flips, the ranking work done in the
   meantime is wasted, and this is the sole item the panel said to do "before anything else".

## Recommendation

**Option 2 — all four, in one sitting.** Only item 1 blocks the board, and it is also the fastest
of the four, because the claim is already lodged and the award letter states the figure outright.
The other three cost minutes of your time each, but two of them run on someone else's clock (an
Age UK appointment, a council reassessment), so starting them today is nearly free and waiting is
not. Item 4 has stopped being research: the repo already holds both component rates, so it is now
a check against the letter you are opening anyway.

Do them in this order: **1, 3, 4, 2** — the blocker, then the one that pays, then the confirmation,
then the slow appointment.

Option 3 is the one to avoid. It is the only option that leaves a knife-edge assumption load-bearing
under a ranking you are about to act on.

## Direction
<!-- Steering, appended by ProgressBoard. Append-only: entries are added, never edited or removed. -->

**2026-08-21** bin/decision-prep.ps1 prepared this card unattended and wrote options and a recommendation. The agent returned no report of what it assumed, so what it read and what it could not determine is not recorded here. The run is in storage/logs/decision-prep.log.
