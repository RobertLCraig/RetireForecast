# Demo couple's anonymised figures

## What I need from you

**Give me a set of obviously fictional figures for a demo couple, to enter through the builder.**
Ages, incomes, pots, a property. Not urgent: nothing is blocked until a demonstration is actually in
prospect.

**Pass** is a set nobody could mistake for the real couple, entered through the UI rather than
seeded into the repo.

**Fail** is a set that is merely anonymised. Plausibly real is the failure mode here, because a
demonstration then shows a stranger something that reads like somebody's actual finances.

**Why it needs you** The standing rule is no hardcoded client data in the repository, so the figures
cannot be invented from anything already in it. "Obviously fictional" is also a judgement about what
looks real to someone who knows you, which is not a judgement anything here can make.

## Why
The repo must contain no hardcoded client data, so the demo figures are entered through the UI
rather than seeded. Until they exist, any demonstration runs on the real couple's numbers.

## Options
1. **Supply anonymised figures** and enter them through the builder. Obviously fictional, per
   the project's own success criteria.
2. **Demo on the real scenario** and never show it to anyone. Works until it does not.

## Recommendation
Option 1, and it is only worth doing when a demonstration is actually in prospect. This is not
blocking anything today.

## Comments

**2026-09-30** **Decided:** Out of date. An obviously-fictional demo couple has existed since
2026-06-28, five weeks before this card was written: `App\Demo\DemoScenario` ("Sam Sample
(fictional)", born 1962, and "Jo Sample (fictional)", born 1960), seeded onto a "Demo user
(fictional)" account by `php artisan db:seed --class=Database\Seeders\DemoScenarioSeeder`.
DECISIONS 2026-06-24 allows exactly this ("any first-run sample must be obviously fictional"): the
no-client-data rule forbids the real couple, not an invented one, so seeding it rather than typing
it into the builder costs nothing and is already done. Nothing is left to decide. Rob, if you want
a different demo set, edit `DemoScenario::baseState()` and re-seed.
