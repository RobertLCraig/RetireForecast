# Morning worklist — Rob

_Written 2026-06-25 by the overnight build session. Tick these off; they unblock the spreadsheet
import and a couple of data-model calls. None of this blocks the app running — the wizard and the
RetireForecast CSV import already work end to end (196 tests green)._

## 1. Provide template samples (unblocks IWT / Nischa import)
The import infrastructure is built. The two popular profiles are wired in but **refuse to parse until
I can see their exact layout**. To calibrate them I need a real export of each:

- [ ] **IWT Conscious Spending Plan** — export a copy (filled or blank) as **CSV** (File → Download →
      CSV from the Google Sheet), or the `.xlsx`.
- [ ] **Nischa Intentional Spending Tracker** — same (it's email-gated, so I can't fetch it myself).
- [ ] _(Optional)_ Your own **Pay and Expenditures.xlsx** if you want a bespoke profile for it.

**Where to put them:** drop them in `storage/app/import-samples/` (create it — it's gitignored, so
real figures never get committed), or just paste the **column headers / sheet layout** into chat. I
only need the structure (which row/column holds which bucket), not your real numbers — a blank export
or a redacted one is ideal. **Do not commit real financial figures to the repo.**

## 2. Decisions I need from you
- [ ] **Line-item expense categories.** You chose line-item categories earlier; I deferred the
      data-model change. Today the wizard + import roll everything up to two totals
      (essential / discretionary). Confirm the approach: keep the **app-layer rollup** (categories are
      a UI/storage concern that sum to the engine's two totals — keeps the framework-free engine
      unchanged, my recommendation) **vs** extend the engine's `ExpenseProfile`. This is a
      DATA-MODEL.md change, so it wants your sign-off before I build it.
- [ ] **XLSX support.** CSV import works with zero dependencies today. The IWT/Nischa sheets are
      `.xlsx`/Google Sheets. OK to add **`phpoffice/phpspreadsheet`** (well-established) so users can
      upload `.xlsx` directly instead of exporting to CSV first?
- [ ] **Two earners.** The import maps a single salary to Person 1. If a sheet has two incomes, how
      should it split? (Leave as Person 1 + a note is the current behaviour.)

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

## 4. Still owed (I can pick these up next, not blocked on you)
- The **compare-assumptions overlay** on the results page (a loop over assumption sets → a third
  chart + accessible table).
- A full per-field accessibility sweep + axe/Pa11y in CI.
- Once samples arrive: calibrate the IWT + Nischa profiles and add XLSX parsing.
