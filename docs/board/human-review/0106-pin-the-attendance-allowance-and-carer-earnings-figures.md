---
no_outward_effect: "published" names a DWP table somebody else printed, read here; nothing is published by us
---

# Pin the Attendance Allowance and carer earnings figures to a published source

## Why
Card 0044 put three benefit figures into `TaxYear\BenefitsParameters`, and two of them are stated
from the building session's own knowledge rather than read off a published table:

- **Attendance Allowance 2025/26, £73.90 lower and £110.40 higher a week.** These are the published
  rates, but they were written from memory and never re-fetched from
  gov.uk/attendance-allowance/what-youll-get.
- **Attendance Allowance 2026/27, £76.70 and £114.60.** DERIVED, not published. They apply the same
  +3.8% and rounding to the nearest 5p that the file's own 2026/27 Pension Credit additions were
  derived by. The real April 2026 uprating may differ.
- **The Carer's Allowance earnings limit, £196.00 for 2025/26 and £203.36 for 2026/27.** The
  2026/27 figure applies the government's stated rule of 16 hours at the National Living Wage
  (16 x £12.71). The published limit is likely rounded and the rounding is a guess.

What it costs: neither figure reaches a projection on its own. Attendance Allowance seeds the
editable amount on the income stream the what-if creates, so a wrong rate is a wrong starting figure
the reader may never correct; the earnings limit is quoted in a warning beside the retirement-age
lever, so a wrong limit sends somebody to check the wrong number. Both are visible and both are
disclosed, which is a different fault from being invisible.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0044 could ship and disclose the figures but could not go and check them.

## Links

**Relates to**
- `0044` - introduced all three figures and their disclosures.
- `0085`, `0086`, `0087`, `0091`, `0092`, `0095` - the same shape of gap, from the same missing web
  access. Whoever picks one up can settle several in one research pass.

## Not this card
The mechanism. The start age, the what-if preset, the carer input and the lever warning are card
0044's and are built. This card only replaces numbers and adds their citations.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for the Attendance Allowance rates and the Carer's Allowance earnings limit in docs/spec/ASSUMPTIONS.md section 20, for both tax years, or record there that the search found none. proves: manual
- [ ] WHEN a published figure differs from the shipped one, THE APP SHALL use the published one, and the warning and the what-if that read the constant SHALL move with it. proves: `test_the_working_longer_lever_flags_the_carer_earnings_limit`
<!-- AC:END -->

## Tasks
- [ ] Fetch the Attendance Allowance weekly rates for 2025/26 and 2026/27 from gov.uk and set them.
- [ ] Fetch the Carer's Allowance earnings limit for both years from gov.uk and set it.
- [ ] Delete the "NOT independently verified" warning block on `BenefitsParameters` once both are
      pinned, and move ASSUMPTIONS section 20 out of the sourcing-gap list.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. All six numbers are
call-site arguments in `packages/finance-engine/src/TaxYear/TaxYearRegistry.php`, in the two
`benefitsParameters(...)` calls; the source URLs are the `attendance_allowance` and `carers_allowance`
keys in the same two `sources` arrays. Nothing restates them: the admin Tax year audit page at
`/admin` and the lever warning both read the constant.

**No `ENGINE_VERSION` bump and no stored re-run are owed**, because neither figure enters a
projection. Run `php artisan test` after. Fixtures that read them:
`tests/Feature/Forecast/QuickWhatIfTest.php` and
`tests/Feature/DecisionSupport/ThresholdPresenterTest.php`.

## Comments

**2026-10-13** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card needs web access, and this unattended session has none: WebFetch of gov.uk/attendance-allowance/what-youll-get and gov.uk/carers-allowance/eligibility, and WebSearch, were all refused for permission. The repository holds no published figure to pin from: docs/spec/ASSUMPTIONS.md section 20 still carries only the shipped values (AA 2026/27 GBP 76.70 / 114.60 derived; earnings limit GBP 196.00 / 203.36, the second derived from 16 x NLW). So neither criterion is met: #1 needs the fetched source URL and verified_on date, and #2 needs a published figure to compare against before its test can be watched failing. Nothing was changed or committed. Next step: run this card in an attended session with WebFetch allowed (the card's Plan says so), or have Rob paste the gov.uk rates for both years into the card.

**2026-10-13** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second unattended take, same block as the first. WebFetch of gov.uk/attendance-allowance/what-youll-get and WebSearch were both refused for permission in this session. The repository still holds no published figure: docs/spec/ASSUMPTIONS.md section 20 (lines 432-438) carries only the shipped values, and no docs/research page has the AA or Carer's Allowance rates. So neither criterion is met: #1 needs a fetched source URL and verified_on date, and #2 needs a published figure before its test can be watched failing. Nothing was changed or committed. This card cannot advance in the unattended loop. Next step: run it in an attended session with WebFetch allowed, or have Rob paste the gov.uk AA rates and the Carer's Allowance earnings limit for 2025/26 and 2026/27 into the card. Suggest marking it not_for_the_loop so the scheduler stops re-queuing it.

**2026-10-13** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-13 manager (m20261013112712-84dd)

**outcome: rob**

The two open criteria need gov.uk figures, and the loop cannot get to the web.

**what the session said**

This card is the `rob` outcome. Neither box is ticked, and no review finding disproves anything.

Both takes stopped at the same block. The loop has no web access, and both criteria need figures from gov.uk:
- the Attendance Allowance weekly rates for 2025/26 and 2026/27
- the Carer's Allowance earnings limit for both years

I searched `docs/research/` and no page there has these figures. So the builder has nothing to pin from. If I sent the card back to `todo/`, it would only come back here a third time.

One person must do the work, in one of two ways:
1. Run the card in an attended session that has WebFetch. The card's Plan says to do this.
2. Paste the four gov.uk figures and their URLs into the card. Then the loop can finish it.

Cards 0085, 0086, 0087, 0091, 0092 and 0095 have the same gap. One research pass can close all of them.

WHY: The two open criteria need gov.uk figures, and the loop cannot get to the web.

OUTCOME: rob

