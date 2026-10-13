# Attendance Allowance rates and the Carer's Allowance earnings limit, 2025/26 and 2026/27

*Researched 2026-10-13 for board card 0106. Every source below was read on 2026-10-13.*

## Findings

| Figure | 2025/26 | 2026/27 | Shipped | Verdict |
|---|---|---|---|---|
| Attendance Allowance, lower rate, a week | £73.90 | £76.70 | £73.90 / £76.70 | both match |
| Attendance Allowance, higher rate, a week | £110.40 | £114.60 | £110.40 / £114.60 | both match |
| Carer's Allowance earnings limit, a week after tax, NI and expenses | £196.00 | **£204.00** | £196.00 / £203.36 | 2026/27 differs: set it to £204.00 |

## Sources

- 2025/26, all three figures: DWP, *Benefit and pension rates 2025 to 2026*,
  <https://www.gov.uk/government/publications/benefit-and-pension-rates-2025-to-2026/benefit-and-pension-rates-2025-to-2026>.
  AA higher £110.40, lower £73.90; Carer's Allowance earnings limit £196.00 (2024/25 was £151.00).
- 2026/27 Attendance Allowance: <https://www.gov.uk/attendance-allowance/what-youll-get>,
  "Lower rate - £76.70", "Higher rate - £114.60". The page states no year; read in October 2026 it
  carries the rates in force, which are the 2026/27 ones.
- 2026/27 earnings limit: <https://www.gov.uk/carers-allowance/eligibility>, "your earnings are £204
  or less a week after tax, National Insurance and expenses". Same caveat on the year.
- The DWP 2026/27 rates table was not found at the 2025/26 URL pattern
  (`benefit-and-pension-rates-2026-to-2027`, HTTP 404), so the two 2026/27 figures are cited to the
  live gov.uk guidance pages.

## What the build does

- Keep both Attendance Allowance pairs; they are now sourced, not derived.
- Change the 2026/27 Carer's Allowance earnings limit from £203.36 to £204.00. The published figure is
  16 x £12.71 = £203.36 rounded up to the whole pound.
- Point the `attendance_allowance` and `carers_allowance` source URLs at the pages above, with
  verified_on 2026-10-13, in both `benefitsParameters(...)` calls in `TaxYearRegistry.php` and in
  docs/spec/ASSUMPTIONS.md section 20.
