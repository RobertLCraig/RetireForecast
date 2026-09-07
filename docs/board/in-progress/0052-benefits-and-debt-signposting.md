# Running short on a mortgage is presented as belt-tightening

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 15). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

The model reports unmet spend and moves on. When the unmet spend is a mortgage payment, the
real-world event is possession proceedings, not a smaller weekly shop. Nothing in the tool says so.
Nothing distinguishes a missed council tax payment - the other priority debt, which carries
liability orders and attachment of benefits - from a missed grocery bill. There is no
debt-priority framing anywhere.

There is also no route to help. `sources-and-contacts.blade.php` has three columns: pensions and
money, later-life mortgages, and capital gains. There is no benefits column and no debt column, so
a household whose plan fails is pointed at the investment world and nowhere else.

The reviewer called this the cheapest gap to close in the whole report, because it is copy rather
than engine, and the gap that would embarrass the tool most in front of a caseworker.

Worth adding at the same time: a household facing an unaffordable mortgage in later life can ask
the lender for forbearance under its regulatory duties, and a court has powers to suspend
possession where arrears can be cleared over a reasonable period. Neither is in the tool's
vocabulary.

## Not this card
Modelling arrears, possession or a debt-management plan. This card is framing and signposting.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a plan has unmet spend and a live mortgage, THE APP SHALL state that the shortfall is a secured-debt shortfall and what that means. proves: `test_a_shortfall_on_a_live_mortgage_is_named_a_secured_debt_shortfall`
- [x] #2 THE APP SHALL show a benefits and debt column of contacts wherever a plan has unmet spend or a mortgage. proves: `test_the_benefits_and_debt_contacts_column_is_off_until_it_is_asked_for`, `test_the_results_page_frames_a_secured_shortfall_and_offers_the_debt_contacts`
- [x] #3 THE APP SHALL name mortgage and council tax as priority debts when it reports a shortfall. proves: `test_a_shortfall_names_mortgage_and_council_tax_as_priority_debts`
<!-- AC:END -->

## Tasks
- [x] Add the benefits and debt column to `sources-and-contacts.blade.php`
- [x] Add the secured-shortfall wording to `ResultPresenter`, so the PDF inherits it
- [x] Add the priority-debt note to the unmet-spend output

## Comments

**2026-09-07**
RESULT: done
TESTS: +6 new, all green
TOUCHED: app/Forecast/ResultPresenter.php, app/Livewire/ScenarioResults.php, app/Livewire/ScenarioCompare.php, app/Export/ScenarioReport.php, resources/views/components/sources-and-contacts.blade.php, resources/views/livewire/scenario-results.blade.php, resources/views/livewire/scenario-compare.blade.php, resources/views/pdf/partials/report.blade.php, tests/Feature/Forecast/PriorityDebtSignpostingTest.php
OUT-OF-SCOPE: none

`ResultPresenter::priorityDebtGuidance()` is the one home for the framing, and it rides the
ladder array as `priorityDebt`, so the screen and the PDF read the same words beside the same
unmet-spend table. It is read at the FIRST year the plan cannot fund its spending, and secured
is decided by whether that same year still owes a mortgage (`YearResult::mortgageBalance()`),
so a renter or an outright owner is never told a home is at stake. A secured shortfall gets two
extra points the unsecured one does not: the lender's duty to consider forbearance, and the
court's power to suspend possession where arrears can be cleared over a reasonable period.
Both cases name mortgage and council tax as priority debts, with what each can do (possession;
a liability order and deductions from benefits, wages or a pension).

The contacts component grew a fourth column, `showBenefitsDebt`, defaulting OFF and switched on
by results, compare and the PDF wherever the plan runs short or carries a mortgage. Four columns
wrap two-up before going four-up, so a narrow screen is not asked for four.

Not settled from the repository, and STATED not verified because this session had no web: every
phone number and URL in the new column (Citizens Advice, National Debtline, StepChange, Turn2us,
Shelter), plus the two legal citations in the secured copy (FCA MCOB 13, Administration of
Justice Act 1970 s.36). They are signposting, not figures, so nothing in a projection moves on
them, but somebody should dial one before a public release.

No `ENGINE_VERSION` bump and no stored re-run is owed: this is copy, and no figure moves.

Built in a worktree, so the new amber panel and the new contacts column **have not been seen in
a browser**.

Worth knowing for the next session in a Blade file: a `{{-- comment --}}` containing the literal
text `@php...@endphp` is NOT invisible to Blade's raw-block regex. One in the PDF partial paired
with a later `@endphp` and silently swallowed most of the printed report, with no error at all.
`ScenarioPdfTest::test_the_pdf_carries_every_section_the_results_page_shows` is what caught it.
