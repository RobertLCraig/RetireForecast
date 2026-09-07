# Estate planning remainder

## Why
From the expert panel, 2026-08-19 (estate planner findings 10, 13, 14, 15). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

**A park home probably gets no residence nil-rate band.** The band needs an interest in a
dwelling-house. A park-home owner holds a chattel plus a pitch agreement, not an interest in land,
so the band is at best unsafe and most likely unavailable. The engine passes home equity through
regardless of what the home is. The downsizing addition would be the only route back, and that is
card 0053. Separately, the site owner's commission of up to 10% bites on any resale, and the exit
always happens eventually, so a park home's terminal value is overstated and far less liquid than
it reads. A non-resident beneficiary cannot simply inherit it.

**NHS care funding is neither modelled nor mentioned.** Continuing Healthcare is fully NHS-funded
and **not means-tested**; where it applies it removes the entire care bill and with it the estate
risk the care overlay exists to show. Funded Nursing Care is paid direct to a nursing home for any
resident assessed as needing nursing care, **including self-funders**, and reduces the fee by a
meaningful amount every week. The care stress currently charges a gross nursing fee for years.

**The first-death estate hard-codes a 50/50 property split.** There is no double-count - the state
figure is already the household's share - but the split has no input behind it, and there is no
field anywhere for beneficial shares or for how the property is co-owned. It is immaterial while a
couple is married and material the moment they are not.

**Lifetime gifting is absent.** No gifts out, no seven-year taper, no annual exemption, no normal
expenditure out of income. The estate planner's recommendation is to **leave this out** for now: it
is honestly disclosed, and the other cards come first.

## Not this card
Gift with reservation of benefit and deprivation warnings, which are card 0049.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a home is a park home or similar chattel, THE APP SHALL NOT claim the residence nil-rate band against it.
- [x] #2 WHEN a park home is valued at the end of a plan, THE APP SHALL deduct the site owner's commission and say it cannot be left to a non-resident.
- [x] #3 WHEN nursing care is charged, THE APP SHALL deduct the NHS funded nursing care contribution, sourced and dated.
- [x] #4 THE APP SHALL explain that Continuing Healthcare, if awarded, removes the care charge entirely.
- [x] #5 THE APP SHALL record beneficial shares in a property, defaulting to equal shares and disclosing that default.
<!-- AC:END -->

## Tasks
- [x] Add a `qualifiesForRnrb` flag to `Property`, false for a depreciating or park home
- [x] Net the resale commission off a park home's terminal value in the estate and the care means test
- [x] Add the funded nursing care deduction to `CareAssumptions`, sourced
- [x] Add a Continuing Healthcare note to the care panel
- [x] Add beneficial shares, or disclose the 50/50 assumption

## Comments

**2026-09-07**
RESULT: done
TESTS: +12 new, all green
TOUCHED:
- packages/finance-engine/src/Dto/Property.php
- packages/finance-engine/src/Care/CareAssumptions.php
- packages/finance-engine/src/Forecast/PathProjector.php
- packages/finance-engine/tests/Iht/ChattelDwellingEstateTest.php (new)
- packages/finance-engine/tests/Care/FundedNursingCareTest.php (new)
- packages/finance-engine/tests/Care/CareCostSamplerTest.php
- packages/finance-engine/tests/MonteCarlo/GoldenMasterTest.php
- app/Forecast/ResultPresenter.php
- app/Forecast/HouseholdAssembler.php
- app/Forecast/ScenarioForecaster.php
- app/Forecast/WhatIfChanges.php
- app/Livewire/ScenarioBuilder.php
- resources/views/livewire/scenario-builder.blade.php
- tests/Unit/Forecast/ChattelDwellingNoticeTest.php (new)
- tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
- tests/Support/BuilderStateFixture.php
- docs/DECISIONS.md
- docs/spec/ASSUMPTIONS.md
- docs/board/todo/0135-pin-the-park-home-and-nursing-care-figures.md (new)
OUT-OF-SCOPE: 0135

**One fact, two readers, rather than the Task's flag name.** The Task asked for a
`qualifiesForRnrb` flag "false for a depreciating or park home". A flag that means both a fact and
its consequence would drift, so the stored field is `Property::$isChattelDwelling` (the fact) and
`qualifiesForRnrb()`, `saleCommissionRate()` and `netOfSaleCommission()` are the three readers of
it. The field is NULLABLE: an explicit answer wins, and null derives the answer from the home being
modelled as losing value, which is the only signal this engine has ever had for a park home (a
negative real growth override is how one is entered, on the owned home and on a bought one alike).
That derivation is the adverse answer and it is DISCLOSED as such: the `chattel_dwelling` note opens
by saying we read it that way and why. A builder select and a share input were added beside it, so
neither figure is one only the engine can set.

**The commission is netted in the estate and the care means test, not in the wealth line.**
`PathProjector::netHomeValue()` is the one home of it and both readers go through it. The wealth
line still reports the gross value on purpose: a household still living in the home has not paid
the commission, and a wealth figure that pretends they have understates what they hold while they
hold it. The `home_depreciates` note used to end "The 10% sale commission is not included in these
figures", which is now false; that sentence is gone.

**The downsizing addition survives a park home deliberately.** Selling a house to buy one is
exactly the disposal the addition exists for, and it is decided by what was SOLD, so refusing the
band on the home at death leaves the addition intact. That is the route back the card's Why names.

**Continuing Healthcare is disclosed, not modelled**, and criterion #4 is met that way. It turns on
a health assessment nothing here can predict, so any modelled probability would be invented. The
care disclosure now says in terms that every care figure in the plan is the position if CHC is NOT
awarded, which is the adverse reading, and that an award removes the charge entirely.

**Watched failing before the code, in every case.** The three estate criteria failed on the
arithmetic (a £350,000 band claimed, a £0 commission, a £0 difference between a 70/30 split and a
half), after the DTO fields existed so the fixture could build the state; the FNC deduction was
watched red through a temporary revert of `nursingAnnual()`; the three copy criteria were watched
red through a `git checkout HEAD` of `ResultPresenter.php` and restored.

**Two consequences of the figures moving.** `ENGINE_VERSION` is
`finance-engine/park-home-and-nhs-nursing-contribution` and the **stored-scenario re-run is owed**.
`MonteCarlo\GoldenMasterTest` reddened and was re-pinned: terminal wealth rises at p10, p25 and p90
with both success probabilities unmoved, which is the nursing contribution and nothing else.
`PIN_REVISION` was already today's date from card 0055's re-pin, so its companion test could not
demand a DECISIONS entry; one is written anyway, and card **0131** already covers that class of
missed-log defect.

**What I could not settle from the repository.** All three new rules are **STATED, not verified**
(this session has no web): the 10% commission and the instrument behind it, the £254.06 FNC weekly
rate and which tax year it belongs to, and the RNRB refusal itself, which is arguable rather than
settled. Written up at [docs/spec/ASSUMPTIONS.md](../../spec/ASSUMPTIONS.md) section 31 and carded
as **0135**.

Built in a worktree, so **the two new builder inputs, the chattel note and the two new care
disclosures have not been seen in a browser.**
