# A pension-age renter never receives Housing Benefit

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 6). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

The engine awards Guarantee Credit and nothing else. In a sell-and-rent plan the household spends
its proceeds down over ten to fifteen years, capital falls below the limit, and a pension-age
renter is then squarely in Housing Benefit territory. The model shows nil for ever.

That is not a neutral simplification. It makes renting look worse than it is in exactly the tail
where a plan is judged to run short, while the buy-outright plans have no equivalent omission. The
tool is currently ranking rent against buy with a thumb on the scale.

Two related gaps in the capital treatment:

- Proceeds from a former home intended to buy another are disregarded for a period. The model
  applies the tariff from day one, so it overstates the hit in the year of a sell-and-rebuy.
- Capital held in a second property is valued at property less mortgage, with no deduction for the
  costs of sale that the rules allow.

## Links

**Relates to**
- `0051` - the mixed-age couple warning, which is the case this card has to refuse an award to,
  is built there.

## Not this card
Universal Credit. Out of scope for a pension-age tool; the mixed-age warning is card 0051.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a pension-age household rents and its income and capital qualify, THE APP SHALL award Housing Benefit on the pension-age basis.
- [ ] #2 WHEN proceeds of a former home are held with the intention of buying another, THE APP SHALL disregard them for the statutory period.
- [x] #3 WHEN capital is held in property, THE APP SHALL deduct the allowed notional costs of sale before assessing it.
- [x] #4 IF Housing Benefit is not modelled for a given plan, THE APP SHALL state on that plan that it is excluded and the plan is therefore understated.
<!-- AC:END -->

## Tasks
- [x] Implement a pension-age Housing Benefit calculation, sourced and dated
- [x] Add the sale-proceeds disregard and the notional sale-costs deduction
- [x] Failing that, add the exclusion notice to every rent plan

## Comments

**2026-09-06**
RESULT: partial
TESTS: +8 new, all green
TOUCHED:
packages/finance-engine/src/Benefits/HousingBenefit.php (new)
packages/finance-engine/src/Benefits/CapitalAssessment.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Forecast/YearResult.php
packages/finance-engine/tests/Forecast/HousingBenefitTest.php (new)
packages/finance-engine/tests/Forecast/PathProjectorTest.php
app/Forecast/ResultPresenter.php
app/Forecast/ScenarioForecaster.php
tests/Unit/Forecast/InputNotesTest.php
tests/Feature/Console/AuditScenariosTest.php
tests/Feature/Forecast/SimulationRunnerTest.php
docs/spec/ASSUMPTIONS.md
docs/DECISIONS.md
docs/HANDOVER.md
docs/board/todo/0113-pin-the-two-housing-benefit-figures.md (new)
docs/board/todo/0114-housing-benefit-ignores-the-local-housing-allowance-cap.md (new)
docs/board/todo/0115-the-care-means-test-values-property-without-the-costs-of-sale.md (new)
OUT-OF-SCOPE: 0113, 0114, 0115

**Criterion 2 is left open, and this is why.** No state in this engine holds the proceeds of a
former home with an intention to buy another, so the fixture cannot build the state the fault
needs and a test for it would pass around the gap rather than through it. `HousingComparison`
applies the whole housing transform BEFORE year 0: `buyVariant` sells, buys and banks the surplus
in one step, so the money that buys the new home is never loose capital for a single modelled day,
and the surplus that IS assessed from year 0 is money kept rather than money held to buy with. The
`rentVariant` and the mid-projection forced sale both hold proceeds, but neither has an intention
to buy: they rent from that year on. The card's premise, that "the model applies the tariff from
day one, so it overstates the hit in the year of a sell-and-rebuy", does not hold against the code
as written. Making it reachable means a purchase that completes some months after the sale, which
is a new modelling capability and not a bug fix, so it is not this card's to add.

Criterion 1 is `Benefits\HousingBenefit`, deliberately the same shape as `Benefits\CouncilTax`
(card 0047): passported to the maximum award on Guarantee Credit, nil above the £16,000 capital
limit, and otherwise the eligible rent less 65% of every pound of weekly income above the
applicable amount. That applicable amount is the Pension Credit one the engine already computes,
the same call card 0047 made and for the same reason. The award comes OFF the rent rather than
into income (DECISIONS 2026-09-06 for why), and `YearResult::housingBenefit()` reports it so the
netted rent line is not an invisible figure. The GROSS rent still drives the tenancy deposit and
the referencing warnings, which are therefore byte-identical.

Criterion 3 is `CapitalAssessment::propertyCapital`: market value, less 10% for the costs of sale,
then less what is secured on it, in that order. Its only caller today is
`PathProjector::meansTestAssessableCapital`, whose let-home branch was the one reachable state.

Criterion 4 is the `housing_benefit_excluded` result note, which fires on a rent plan whose last
member reaches State Pension age after the base year, names that year, and says the plan is
UNDERSTATED until it. Beside it a `housing_benefit` note states what the award does and the four
things that are not in it. The exclusion is real and deliberate: working-age Housing Benefit is
closed to new claims and its replacement is the Universal Credit housing element, which the card
put out of scope.

**Assumed, and flagged rather than guessed past.** Both statutory figures (the 65% taper, the 10%
sale-costs deduction) are STATED and not verified: this session had no web access. Both reach a
projection. ASSUMPTIONS section 24 records them with the SI named and the URL unvisited, and card
0113 carries pinning them.

**A stored re-run is owed.** `ENGINE_VERSION` is `finance-engine/pension-age-housing-benefit`.
Three existing tests moved and each is recorded on its own line in the diff: `PathProjectorTest`'s
rent-floor assertion now reconciles against the award instead of pinning £26,000, and the two
integrity-stamp tests doctored `successProbabilityEssentials` to 1.0, which stopped being a tamper
once the rent plan started meeting its essentials on every path (they now use 0.123, a value 20
paths cannot produce). `MonteCarlo\GoldenMasterTest` did not redden and needs no re-pin: its frozen
household owns its home throughout and holds no let property.

**Not seen in a browser.** Built in a worktree, so the two new result notes have been proved by
test only. The orient hook is still asking for a stale block to be folded out of `docs/HANDOVER.md`
(43 KB); that is not this card's scope and this entry adds one bullet to it.

**2026-09-06**
RESULT: partial
TESTS: +0 new, all green
TOUCHED:
docs/board/in-progress/0048-housing-benefit-is-never-awarded.md
docs/board/todo/0116-the-sale-proceeds-disregard-needs-a-gap-between-selling-and-buying.md (new)
OUT-OF-SCOPE: 0116

Resumed run. The tree opened clean on `d13f0b4`, so the build above is committed and nothing was
part-done. The suite is green from this worktree, and `vendor/retireforecast/finance-engine` is a
real junction here rather than a stale copy, so the engine code under test is the code in this
branch and not the one the worktree was cut from. No code changed on this run.

**Criterion 2 was re-tested against the code, not taken on trust from the entry above, and it stays
open.** `Dto\HousingAction` carries a sale price, a buy price and the costs and no year, month or
date at all, so there is no shape in which a purchase can complete later than its sale;
`HousingComparison::buyVariant` is the only consumer and it sells, buys and banks the surplus in one
step before year 0. That confirms the earlier finding from the DTO rather than from the transform:
the proceeds are not merely disregarded-and-ignored, they never exist as a state the fixture could
build. A test here would have to hand-construct capital the projection cannot produce, which is the
blindness the board has been bitten by five times, so none was written.

What is new is that the gap no longer lives only in this card's prose. Card **0116** carries it, and
opens on the question rather than the build, because whether a plan should be able to sell in one
year and buy in another is a modelling-scope call that is Rob's and not an unattended session's.
The three defects card 0048 raised and did not close (0113, 0114, 0115) are unchanged.

**2026-09-06**
RESULT: partial
TESTS: +0 new, all green
TOUCHED:
docs/board/in-progress/0048-housing-benefit-is-never-awarded.md
docs/board/todo/0117-two-files-fail-the-house-style-check.md (new)
OUT-OF-SCOPE: 0117

Second resumed run. The tree opened clean on `4d02c62`, so both earlier runs are committed and
nothing was part-done. No code changed on this run and no criterion moved.

**Criterion 2 stays open and this run adds no new argument for it.** `Dto\HousingAction` was read
again and it still carries a sale price, a buy price and the costs with no year, month or date, so
the earlier finding holds: there is no shape in which a purchase completes later than its sale, and
so no state in which proceeds are held with an intention to buy. Re-deriving that a third time buys
nothing. The call it waits on is a modelling-scope one that only Rob can make, and card **0116**
carries it.

**What this run did settle.** The engine package at `vendor/retireforecast/finance-engine` is a real
junction to `packages/finance-engine` in this worktree, not the stale copy that has silently passed a
green suite before, so the code under test is this branch's code. The full suite is green from this
directory.

**A style failure was found and carded, not fixed.** `vendor/bin/pint --test` over the whole
repository exits non-zero on `app/Forecast/LumpSumTaxShock.php` and
`packages/finance-engine/src/Pension/TaxFreeCashCalculator.php`. Neither is a file this card touched
and neither is new; the `pint --dirty` convention means a file that drifted once is never looked at
again. Fixing it in passing would be unreviewed work in a card that changed no code, so it is card
**0117** instead. Every file card 0048 did touch passes.

`.\vendor\bin\pest.bat` does not exist in this repository. The runner is PHPUnit through
`php artisan test`, which is what CLAUDE.md documents and what was run.

Still not seen in a browser: the two result notes from the first run are proved by test only, and a
worktree is not served by Herd.

**2026-09-06** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 4 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.
