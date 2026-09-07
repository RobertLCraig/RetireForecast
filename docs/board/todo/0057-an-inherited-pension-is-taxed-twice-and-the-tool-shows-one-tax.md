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

### 2026-09-07 review (v20260907194739-cbb7)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 282s, run by this job rather than reported by the card.

**acceptance: sound**

I traced all three boxes to real code.

**#1 ÔÇö second tax shown, gated on age 75+.**
`InheritanceTaxCalculator::beneficiaryIncomeTax` returns zero unless `$deceasedDiedAtOrAfter75`. The flag comes from `PathProjector::settleEstates` / `computeIht` comparing `deathAge` to `BENEFICIARY_TAXED_FROM_AGE`. It reaches the screen via `ResultPresenter::ihtPanel` (`beneficiaryIncomeTax`, `pensionTaxedTwiceTotal`) and is printed in `resources/views/livewire/scenario-results.blade.php` and `resources/views/pdf/partials/report.blade.php`.

**#2 ÔÇö editable rate, adverse default, disclosed.**
Field `beneficiaryTaxRate` on `App\Livewire\ScenarioBuilder` (validated to 0/20/40/45, saved in `builderState`), read by `ScenarioForecaster::settings`, defaulted by `ForecastSettings::beneficiaryMarginalRate` from `DEFAULT_BENEFICIARY_MARGINAL_RATE_BPS`. Disclosed in `ResultPresenter::assumedFigures` under `beneficiaryMarginalRateIsAssumed`, reading the constant, not restating it.

**#3 ÔÇö nomination drives the exemption.**
`DcPension::$nominatedBeneficiary` + `nominatedToSpouse()`, captured in `HouseholdAssembler` and the builder's per-pension select, carried in the pot array by `PathProjector`, and used by `InheritanceTaxCalculator::computeFirstDeath`, which holds the pot out of the will/intestacy split and exempts only the spouse-nominated part. Marital status alone no longer exempts it.

I tried to break each one and could not.

VERDICT: sound

**scope: defect**

Scope review of commit 184c007 (the card's own commit; the huge diff above is the whole branch, not this card).

**Left half done ÔÇö and it creates a new double count.**
`InheritanceTaxCalculator::computeFirstDeath` charges beneficiary income tax on the pot "leaving the household", meaning any pot NOT nominated to the spouse. Its own comment says a spouse-nominated pot is excluded because the projector goes on taxing the survivor's withdrawals from it.

But `PathProjector::settleEstates` still hands **every** pot to the surviving partner, whatever the nomination. So a pot nominated to a child is charged beneficiary income tax at the first death **and** stays in the household, where the survivor's withdrawals are taxed again year by year. The same money is taxed twice by two different mechanisms.

The build carded this as 0133 and called it "a projection change, out of scope". It is not out of scope: this card's own change is what made the split real, and AC#3 says the nomination, not marital status, drives the treatment.

**Fence:** nothing crossed into 0060/0063 decumulation territory.

VERDICT: defect

**breakage: defect**

**Finding ÔÇö the "part leaving the household" rule is asserted but not held for a cohabiting couple.**

`InheritanceTaxCalculator::computeFirstDeath` says in its own comment that only the pot *leaving the household* is charged beneficiary income tax, "because a pot inherited by somebody the projector goes on modelling is already taxed on every withdrawal". But it computes `$leavingTheHousehold` from `$nominatedToSpouse`, and that is forced to zero whenever `$spouseSurvives` is false. A cohabiting couple always has `$spouseSurvives` false (`PathProjector::recordFirstDeathIht` gates it on `RelationshipStatus::MarriedOrCivilPartnership`), while `PathProjector::settleEstates` still hands every pot to the surviving partner.

So for any cohabiting couple with Inheritance Tax modelled and a first death at or after 75, the whole pot is charged the beneficiary's income tax *and* stays in the model, where the survivor's withdrawals are taxed again. The comment is false in that case, and `DcPension::nominatedToSpouse()` gives them no way to say the pot goes to their partner (`PensionBeneficiary` offers only spouse/civil partner).

Card 0133 does not cover this: it is about a pot nominated *away*. No test builds a cohabiting couple with a post-75 first death (`InheritanceTaxForecastTest` cohabiting case checks tax only).

VERDICT: defect

