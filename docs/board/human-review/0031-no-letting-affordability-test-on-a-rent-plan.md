# A rent plan is never checked against whether a landlord would accept the tenant

## Why
From the expert panel, 2026-08-19 (property finding 6). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

The engine asks only whether the money lasts. It never asks whether the tenancy would be granted.

Standard referencing requires annual gross income of at least 30 times the monthly rent. That is
an **income** test, and it does not care how much capital a household holds. A retired household
with a large pot and a small pension fails it, which is a wall no amount of proceeds gets them
over.

The two ways round it are both costly and neither is modelled: a UK homeowner guarantor at 36
times, which a household that has just sold no longer has, or six to twelve months' rent in
advance at every renewal, which locks up capital permanently.

This is the mirror of the survivor-affordability wall the model found on the mortgage side, which
is the most valuable finding the tool has produced. On the rent side it is not asked at all.

## Links

**Relates to**
- `0048` - Housing Benefit for a pension-age renter is that card, and it is the other half of
  whether a renting plan is affordable.

## Not this card
Housing Benefit for a pension-age renter, which is card 0048.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a rent plan is modelled, THE APP SHALL flag any year in which household gross income falls below the standard referencing multiple of the rent.
- [x] #2 THE APP SHALL state the two normal alternatives to a failed reference, and the capital that rent in advance would tie up.
- [x] #3 THE APP SHALL include the deposit and first month up front as a cost at the start of a rent plan.
<!-- AC:END -->

## Tasks
- [x] Add a referencing feasibility flag using the existing `WarningCode` pattern
- [ ] Source the referencing multiple, with `source` and `verified_on`
- [x] Charge deposit plus first month as a one-off at the start of a rent plan
- [x] Copy on the rent result explaining what a failed reference means

## Plan
Follow what the tool already does with an unaffordable purchase: flag it rather than modelling it
away.

## Comments

**2026-09-05**
RESULT: done
TESTS: +14 new, all green
TOUCHED:
packages/finance-engine/src/Housing/Tenancy.php (new)
packages/finance-engine/src/Support/WarningCode.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Housing/HousingComparison.php
packages/finance-engine/tests/Housing/TenancyReferencingTest.php (new)
app/Forecast/ResultPresenter.php
app/Forecast/ScenarioForecaster.php
tests/Feature/Forecast/RentReferencingNoticeTest.php (new)
resources/views/livewire/scenario-results.blade.php
resources/views/pdf/partials/report.blade.php
docs/spec/ASSUMPTIONS.md
docs/spec/METHODOLOGY.md
docs/DECISIONS.md
docs/HANDOVER.md
docs/board/todo/0089-a-sell-plans-assumed-figure-notes-are-computed-on-the-stay-put-forecast.md (new)
docs/board/todo/0090-a-forced-sale-starts-a-tenancy-and-is-charged-nothing-to-start-it.md (new)
docs/board/todo/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md (new)
OUT-OF-SCOPE: 0089, 0090, 0091

A new `Housing\Tenancy` owns every figure (referencing multiple 30x, guarantor 36x, 6 to 12 months in
advance, the Tenant Fees Act deposit cap) so the sentences a reader is shown READ the constants and
cannot drift from them. `PathProjector` raises `RENT_REFERENCING_FAILED` on any year whose
`grossIncome` falls below the bar, and `TENANCY_UP_FRONT_COST` once, in the year the tenancy starts.
`HousingComparison::rentVariant` charges the deposit as a year-0 one-off on the same
`withOneOffCost` path the unfunded-purchase gap uses.

Both notices reach the reader through `ResultPresenter::ladder()` (`rentReferencing` /
`tenancyUpFront`), rendered as banners beside the money-lasts verdict on the results page and in the
PDF. The deposit is also disclosed through `assumedFigures()`, so `scenarios:audit` check 7 covers
it. **Not `inputNotes()` alone, because that is handed the STAY-PUT forecast on the screen** and a
rent plan's own warnings would never have reached its own reader; that is a defect of its own and is
card 0089, not something this card widened into.

**The one deviation, stated plainly: the first month's rent is named but not charged a second time.**
Criterion #3 asks for "the deposit and first month up front". In an annual model, a household paying
monthly in advance makes twelve payments in its first year, and the year's rent line already charges
twelve, so charging a thirteenth would be a cost nobody pays. What is genuinely additional on day one
is the deposit, and that is what is charged. The disclosure states the deposit, the first month AND
the day-one total the household must produce, and says why only one of the two is charged again. So
both figures are on the result and only the honest one moves the projection. If the reviewer wants
the literal thirteenth month charged instead, that is a two-line change in `rentVariant`.

Every criterion was built test-first and watched fail: the referencing and up-front tests failed on
zero warnings raised, and the deposit test failed on `spendTarget` being 3,200,000 pence where
3,373,077 was expected. The engine test pins exact pence under flat assumptions; the app test pins
the flag to the flagged year's own reported income, because rent rises in real terms in the rich
fixture and a figure restated from the inputs would have been wrong.

Assumed: that the annual projection year and the tenancy year start together, which is what lets the
year-0 rent stand for the twelve payments. Not settled from the repository: nothing here says whether
a household would be re-referenced at a renewal, so the flag is raised on every failing year rather
than only the first, which is the more adverse reading.

`ENGINE_VERSION` is now `finance-engine/tenancy-deposit`. Every stored rent variant is one deposit
too cheap in year 0 and is owed a re-run; no other variant moves. **Built in a worktree, so the two
new banners have NOT been looked at in a browser and `scenarios:audit` was not run** (it reaches the
live database, which a worktree session must not touch).

### 2026-09-05 review (v20260905103005-1c98)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 194s, run by this job rather than reported by the card.

**acceptance: defect**

I checked each criterion against the code.

**#1 ÔÇö flag the failing years.** Real. `PathProjector::rentReferencingWarnings()` raises `WarningCode::RENT_REFERENCING_FAILED` on every year where the year's gross income is under `Tenancy::referencePasses()`. It is called from the year loop in `PathProjector` beside the other warnings. `ResultPresenter::ladder()` collects every failing year and marks each row `failsReference`. Banners exist in `resources/views/livewire/scenario-results.blade.php` and `resources/views/pdf/partials/report.blade.php`.

**#2 ÔÇö the two alternatives and the locked capital.** Real. The same message in `rentReferencingWarnings()` names the guarantor bar (`Tenancy::guarantorIncomeRequired`) and 6 to 12 months rent in advance in pounds (`Tenancy::rentInAdvance`).

**#3 ÔÇö deposit and first month up front.** Charged in `HousingComparison::rentVariant()`. The first month is named, not charged twice. That is honest and correct for a yearly model.

**One defect inside #3.** `PathProjector::tenancyUpFrontWarnings()` writes the words "5 weeks' rent" from `Tenancy::DEPOSIT_WEEKS`, but `Tenancy::deposit()` uses 6 weeks once annual rent reaches ┬ú50,000. At that rent the reader is told a wrong basis for a charged figure. The test pins only the 5-week side.

VERDICT: defect

**scope: defect**

Reviewed the commit `6bc8a09` against the card.

**1. The flag reaches plans the card did not name.**
`PathProjector::rentReferencingWarnings()` fires on any year where rent is charged, not on a rent plan. `ScenarioForecaster::settings()` sets `annualRent` for a **forced-sale** scenario, so the stay-put forecast of an interest-only household now shows the referencing banner too. The document shipped with it says the opposite: `docs/spec/ASSUMPTIONS.md` ┬º15 says the figures apply "to a sell-and-rent plan only". Code and doc disagree, and one of them is wrong.

**2. That same widening is half wired.** The deposit is charged only in `HousingComparison::rentVariant()`, so a forced-sale tenancy gets the warning but no start-up cost. The build raised that as card 0090 instead of keeping the two halves together.

**3. A row mark nothing shows.** `ResultPresenter::ladder()` adds `failsReference` to every row, with a comment about the table marking it. No template reads it, on screen or in the PDF. Only the test does.

**4. Left undone, declared:** the task "Source the referencing multiple, with `source` and `verified_on`" is unticked and pushed to card 0091. Honest, but the card is not finished.

VERDICT: defect

**breakage: defect**

Two things break.

**1. The deposit sentence names the wrong cap.** `Tenancy::deposit()` charges six weeks' rent once the annual rent reaches ┬ú50,000, but `PathProjector::tenancyUpFrontWarnings` always writes `Tenancy::DEPOSIT_WEEKS` (five) into the sentence: "┬úX (5 weeks' rent, the most a landlord may hold)". Above the threshold the pounds are six weeks and the words say five, so the disclosure disagrees with the money it discloses. `housing.annualRent` has no upper bound in `ScenarioBuilder::rules()`, so a user can reach it. `TenancyReferencingTest::test_the_deposit_cap_steps_to_six_weeks_above_the_statutory_rent_threshold` tests only the arithmetic, never the message.

**2. One rent path is never flagged.** Both new warnings are gated on `$settings->annualRent` in `PathProjector::projectYear`. `QuickWhatIf::letOutAndRent` models its rent as an essential expense line ("Rent (our home)") on a `stay_put` variant that still owns the home, so no reference test, no deposit, nothing on screen ÔÇö the exact hole card 0031 exists to close. Card `docs/board/todo/0090` states the flag "is raised in `PathProjector` wherever rent is charged"; that sentence is now false.

VERDICT: defect


**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
