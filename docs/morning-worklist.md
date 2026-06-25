# Morning worklist — Rob

_Written 2026-06-25; updated through the .xlsx / personal-workbook session. None of this blocks the
app running — the wizard, CSV/`.xlsx` import and the personal-workbook import all work (212 tests green)._

## 1. Template imports
- [x] **Your "Pay and Expenditures" workbook** — **now live and verified on the real file.** Upload the
      `.xlsx`, pick the scenario tab (Flat A, Rental B, RC…), choose "Pay & Expenditures". It fills
      essential spending (£24,600/yr from the Flat A tab), gross salary (£30,000), and pulls income
      (State Pension £190.00/wk, DLA tax-free, the partner pension as an annuity). **After importing,
      go to the Pensions & income step and set a start age for each imported income, and split DLA/SP
      onto the right person** — the sheet has no ages or person split, so those are intentionally left
      blank and flagged.
- [x] **IWT Conscious Spending Plan** — live (calibrated from the published structure). Still worth a
      quick check against your real 2023 export, since CSP ships in a few versions.
- [ ] **Nischa Intentional Spending Tracker** — **deprioritised** (your call). It's a 50/30/20 formula
      dashboard; layout captured for later. No action needed unless you want it sooner.

## 2. Decisions still needed
- [ ] **Line-item expense categories.** Today the wizard + every import roll everything into two totals
      (essential / discretionary) — and your sheet has no per-line essential/discretionary flag, so it
      all imports as **essential**. Confirm the approach for line items: keep the **app-layer rollup**
      (categories sum to the engine's two totals — keeps the framework-free engine unchanged, my
      recommendation) **vs** extend the engine's `ExpenseProfile`. A DATA-MODEL.md change, so it wants
      your sign-off. _(Resolved this session: **xlsx support** — added `phpoffice/phpspreadsheet`;
      **two earners** — income lands on Person 1, flagged to split.)_

## 3. Try it out (no input from me needed)
```powershell
Set-Location "C:\Dev\RetireForecast"
npm run build            # public/build is gitignored
php artisan serve        # then visit http://retireforecast.test or http://127.0.0.1:8000
```
- [ ] `/scenarios/create` — step through the new **wizard** (the stepper lets you jump around freely).
- [ ] Try the **Import from a spreadsheet** panel with a CSV in this format (monthly amounts):
      ```csv
      section,label,monthly_amount
      essential,Mortgage,1500.58
      essential,Council Tax,167.00
      discretionary,Netflix,15.00
      salary,Gross salary,2500.00
      ```
      It fills spending + salary, lands you on the Spending step, and lists what still needs entering.
- [ ] Run a forecast and check the new **lump-sum tax-shock panel** at the top of the results page.

- [ ] Check the new **compare-assumptions overlay** on the results page — a sensitivity table showing
      the best-estimate outcome under each sourced set (FCA / DMS / OBR).

- [ ] Upload your **Pay and Expenditures.xlsx** (it's already in `docs/`, gitignored), pick a tab, and
      check the imported totals on the review screen, then set income start ages on the Pensions step.

## 4. Still owed (I can pick these up next, not blocked on you)
- The **line-item expense categories** build (once you confirm the approach in §2).
- A full per-field accessibility sweep + axe/Pa11y in CI.
- Re-verify IWT CSP against your real 2023 export; the Nischa 50/30/20 profile if/when you want it.
- Phase 2 step 5: the demo preset (your anonymised couple, entered via the UI), perf tuning, PDF export.
