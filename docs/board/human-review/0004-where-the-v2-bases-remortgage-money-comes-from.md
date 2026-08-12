# Document where the V2 base's remortgage money comes from

## What I need from you
Tell me where the roughly GBP 49,495 comes from, then enter it at builder step 3 as a one-off
capital receipt in 2026 labelled with the real source, plus a matching one-off cost the same year
(the money goes straight to the lender). <http://retireforecast.test/scenarios/9/edit>

Pass: `php artisan scenarios:audit` still runs clean and the base no longer contains money from
nowhere. An agent cannot supply this: only you know the source.

## Why
The base needs **~GBP 49,495** found from outside (GBP 48,000 to close the gap to the GBP 208k
redemption, plus GBP 1,495 broker fees) and still models it as arriving unshown. Until the source
is named, the base scenario contains money from nowhere.

## Options
1. **Enter it as a one-off capital receipt** at builder step 3, year 2026, with the real source
   as the label, plus a matching one-off cost the same year since the money goes straight to the
   lender.
2. **Adopt the art-sale answer.** Scenarios 47/48 already model the GBP 80k art sale both ways;
   point the base at that rather than inventing a second source.
3. **Change the plan** so the gap does not arise.
4. **Adopt the 50+ interest-only route** (broker revision 2026-08-11, card 0022): it lends
   GBP 199,000, which shrinks the gap from ~GBP 49,495 to **GBP 9,000** but does not close it, so
   this option reduces the amount to source rather than removing the question. It carries its own
   costs — a GBP 199,000 balloon in 2036 and an unquoted rate after the five-year fix — so this
   card cannot be closed by picking it here; it is decided on 0022 and this card follows.

## Recommendation
Option 1, using whatever the real source turns out to be. This is data entry through the UI, not
a code change, and it is the last unexplained figure in the base.

## Decided
<!-- -->
