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
