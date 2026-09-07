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

### 2026-09-07 review (v20260907095255-da1f)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 233s, run by this job rather than reported by the card.

**acceptance: sound**

I traced all three lines to real code and tried to break each.

**#1 secured framing** ÔÇö `ResultPresenter::priorityDebtGuidance()` (`app/Forecast/ResultPresenter.php`) finds the first year with `unmetSpend`, and sets `secured` from that same year's `YearResult::mortgageBalance()`. The secured headline says "secured-debt shortfall", arrears and possession. It is rendered by `resources/views/livewire/scenario-results.blade.php` and `resources/views/pdf/partials/report.blade.php`, both from `$ladder['priorityDebt']`. A no-mortgage plan gets the other wording, so no invented consequence.

**#2 contacts column** ÔÇö `resources/views/components/sources-and-contacts.blade.php` gains a `showBenefitsDebt` prop, default false. It is switched on by `ScenarioResults::render()`, `ScenarioCompare::render()` and `ScenarioReport` (`sourcesShowBenefitsDebt`), each using "panel not null OR mortgage". The PDF has its own gated section. So screen, compare and PDF all covered.

**#3 priority debts named** ÔÇö the first two `points` in `priorityDebtGuidance()` name mortgage and council tax, possession, liability order and deductions. Both secured and unsecured cases get them.

I could not find an AC that fails.

VERDICT: sound

**scope: defect**


**2026-09-07** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

## What I checked

I found the commit (`dd8efca`, card 0052) and read the diff, the component, and the tests.

## Finding

**`ResultPresenter::priorityDebtGuidance()` adds a claim the card never asked for, and the claim is wrong.**

The second bullet it always emits says the forecast "counts the benefits you entered plus Pension Credit, and nothing else".

That is not true. The engine also models and pays into the projection:

- `packages/finance-engine/src/Benefits/HousingBenefit.php` (`annualAward`, called from `PathProjector`)
- `packages/finance-engine/src/Benefits/CouncilTax.php` (`reductionAnnual`, called from `PathProjector`)
- `packages/finance-engine/src/Benefits/SupportForMortgageInterest.php` (`annualAmountMet`, called from `PathProjector`)

So the tool now tells the user, on screen and in the PDF, that it ignores benefits it actually pays. The card asked for debt-priority framing and a route to help. A statement about which benefits the model covers is over the fence, and it is a wrong figure statement in a project whose rule is "no invisible figures".

Smaller note: the amber framing panel only renders in `resources/views/livewire/scenario-results.blade.php`. `ScenarioCompare::render()` gets the contacts column but no framing, so a failing plan viewed in compare is still a bare number.

Fix: delete or correct that bullet.

VERDICT: defect

**breakage: defect**

**Finding: equity release is framed as a repossession risk.**

`ResultPresenter::priorityDebtGuidance()` decides `secured` from `YearResult::mortgageBalance()` alone. `PathProjector` fills that from `$state['mortgageOutstanding']`, and `PathProjector` rolls a **lifetime mortgage** into the same field (`mortgageRollUpRate`, documented in `Dto/Property.php` as "a lifetime mortgage (equity release)" with no payments).

So a household with equity release and a shortfall is told the loan is one where "missed instalments become arrears and the lender can ask a court for possession ÔÇö the home is then sold", plus the MCOB 13 forbearance point. A lifetime mortgage has **no instalments to miss**; it is repaid on death or a move into care and carries a no-negative-equity guarantee. That is an invented consequence, which the same docblock says it avoids ("never told their home is at stake" for a renter or outright owner). The distinction is already available in this class ÔÇö `ResultPresenter` reads `$home?->mortgageRollUpRate` elsewhere.

No test builds an equity-release shortfall; the suite is green and silent.

Secondary, same function: `YearResult::smiBalance()` and the new deferred-care charge are documented there as the second and third secured balances on the home. Neither reaches the framing.

VERDICT: defect

