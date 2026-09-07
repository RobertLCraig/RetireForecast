# An inherited pension pot is taxed twice and the tool shows one tax

## Why
From the expert panel, 2026-08-19 (estate planner finding 8). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

From April 2027 an unused pension pot enters the estate for inheritance tax. The calculator adds it
and charges 40%. It never shows the **beneficiary's income tax** on drawing the inherited fund
where the member died at or after 75, which is taxed as the beneficiary's own pension income. The
inheritance tax change does not displace that charge.

For most households modelled here both deaths fall after 75, so a pot passing to a working-age
child suffers inheritance tax and then income tax - an effective rate around two thirds.

The methodology says the inheritance tax toggle exists to compare spending a pension down against
preserving an estate. Showing only one of the two taxes makes preserving the pot look roughly twice
as attractive as it is, in the direction that discourages spending it. That is the exact comparison
the toggle was built for.

**The nomination is missing too.** Pension death benefits pass under the scheme's discretion
following the member's expression of wish, not the will - and from April 2027 that form is an
inheritance tax document. There is no nomination input anywhere in the builder, so a pot nominated
to a child rather than to a spouse is still modelled as spouse-exempt at the first death.

## Not this card
Whether to spend the pot. That is a decumulation question, cards 0060 and 0063.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN an unused pension passes on a death at or after 75, THE APP SHALL show the beneficiary's income tax alongside the inheritance tax.
- [x] #2 THE APP SHALL let the assumed beneficiary tax rate be edited, defaulting to the adverse rate and disclosed as an assumed figure.
- [x] #3 THE APP SHALL record who each pension is nominated to, and use that rather than marital status when applying the spouse exemption.
<!-- AC:END -->

## Tasks
- [x] Add the beneficiary income-tax line to the IHT panel, gated on death at or after 75
- [x] Add a `nominatedBeneficiary` field per pension; route the exemption off it
- [x] Source the treatment with `source` and `verified_on`

## Comments

**2026-09-07**
RESULT: done
TESTS: +18 new, all green
TOUCHED:
app/Forecast/HouseholdAssembler.php
app/Forecast/ResultPresenter.php
app/Forecast/ScenarioForecaster.php
app/Livewire/ScenarioBuilder.php
docs/DECISIONS.md
docs/spec/ASSUMPTIONS.md
docs/board/in-progress/0057-an-inherited-pension-is-taxed-twice-and-the-tool-shows-one-tax.md
docs/board/todo/0133-a-pot-nominated-away-from-the-spouse-is-still-inherited-by-them.md
docs/board/todo/0134-pin-the-inherited-pension-double-charge.md
packages/finance-engine/src/Dto/DcPension.php
packages/finance-engine/src/Dto/PensionBeneficiary.php
packages/finance-engine/src/Forecast/ForecastSettings.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Iht/IhtOutcome.php
packages/finance-engine/src/Iht/IhtResult.php
packages/finance-engine/src/Iht/InheritanceTaxCalculator.php
packages/finance-engine/src/Support/WarningCode.php
packages/finance-engine/tests/Forecast/InheritanceTaxForecastTest.php
packages/finance-engine/tests/Forecast/InheritedPensionForecastTest.php
packages/finance-engine/tests/Iht/InheritedPensionTaxTest.php
resources/views/livewire/scenario-builder.blade.php
resources/views/livewire/scenario-results.blade.php
resources/views/pdf/partials/report.blade.php
tests/Feature/Livewire/ScenarioBuilderTest.php
tests/Support/BuilderStateFixture.php
tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
tests/Unit/Forecast/IhtPanelTest.php
OUT-OF-SCOPE: 0133, 0134

`InheritanceTaxCalculator` now computes both charges. The second one is charged on the pot NET of
the Inheritance Tax the pot itself bears, apportioned rateably over the CHARGEABLE estate (an
exempt part of an estate bears none of the tax), which is what produces the "around two thirds"
effective rate the finding names: 40% then 40% on the 60% left is 64%. It rides `IhtResult` as
`beneficiaryIncomeTax` beside `unusedPensionPassing` and the rate it was charged at, and
`IhtOutcome::$beneficiaryIncomeTax` sums the two deaths. It is deliberately never added into `tax`
or `total`: the two charges fall on different people in different years, and the panel only sums
them under a label that says so.

The nomination is `Dto\PensionBeneficiary` on `DcPension`, read through `nominatedToSpouse()`, and
the pot array in `PathProjector` carries it so the first death can total the spouse-nominated part.
`computeFirstDeath` now holds the pension OUT of the will and intestacy split entirely, because a
death benefit does not pass that way, and exempts it only to the extent it is nominated to the
survivor.

**What I assumed, and it is the load-bearing call on this card:** an unanswered nomination reads as
NOT the spouse. That is the standing adverse-default direction and it matches `Person::$hasWill`
from card 0054, but it is not the common answer in the world, and it means **every stored plan that
models Inheritance Tax and holds a DC pot now pays more at the first death** until its nominations
are ticked. `ENGINE_VERSION` is `finance-engine/inherited-pension-taxed-twice` and the
**stored-scenario re-run is owed**. It is disclosed in the strongest terms the assumed-figure list
carries. DECISIONS 2026-09-07 records the reasoning, and Rob can reverse it in one line if he
disagrees. `GoldenMasterTest` did not redden and needs no re-pin: its fixture models no Inheritance
Tax.

The first death charges the beneficiary's income tax only on the part LEAVING the household. A pot
nominated to the survivor is inherited by somebody the projector goes on modelling, and it already
taxes every withdrawal they make from it, so restating it here would count the same tax twice.

**What I could not settle from the repository:** every rule behind this is **STATED, not verified**
(no web access in an unattended session): the age-75 dividing line, that the April 2027 change does
not displace the income-tax charge, that a nominated death benefit falls outside the will, and the
rateable apportionment. Written up at docs/spec/ASSUMPTIONS.md section 30 and carded as **0134**.
The 40% default rate is a judgement with no published answer and needs no source; it is editable in
the builder (0%, 20%, 40%, 45%).

**Two things this did not do.** Card **0133**: `settleEstates` still hands a pot nominated to a
child to the surviving partner, so it is taxed as leaving the household while its money stays in it.
That is a projection change, and this card was scoped to the tax. And the engine charges ONE rate on
the whole pot rather than banding the beneficiary's drawdown over the years they take it, which is
the direction of the rate the reader picks; flagged in ASSUMPTIONS section 30 rather than carded,
because it is what "assumed marginal rate" means.

Built in a worktree, so the **new builder rate select, the per-pension nomination select, the
results panel and the PDF lines have not been seen in a browser**.
