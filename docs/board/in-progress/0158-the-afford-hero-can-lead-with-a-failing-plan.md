# The /afford hero can lead with a failing plan

## Why
`AffordabilityAssessment::bottomLine()` builds the hero from `self::lead($cards[0] ?? null)`. When no
plan keeps the essentials paid, `$cards[0]` is a failing plan. The hero then asks "How sure is your
strongest plan?" and can show a green or amber figure for it, above the red "none of these plans keep
the essentials paid" headline. The two panels then say different things about the same plan.

Found by the 2026-08-29 review of card 0010 and named again in its 2026-09-28 manager pass. Card 0010
#3 did not cover it.

## Links

**Relates to**
- `0010` - built the hero; this is the all-failing case it left open.

## Not this card
- The hero's colour bands and the full-run filter. Card 0010 #3 settled both.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN no plan keeps the essentials paid on the expected path, THE APP SHALL NOT title the hero "your strongest plan" or colour it green, and SHALL say plainly that no plan lasts on the expected path. proves: `test_the_hero_does_not_lead_with_a_failing_plan_as_the_strongest`
<!-- AC:END -->

## Plan
Presenter and view only. Read `AffordabilityAssessment::bottomLine()` and `lead()`, and the hero
section of `resources/views/livewire/affordability.blade.php`. Build the all-failing fixture the way
`test_it_separates_plans_that_work_from_plans_that_do_not` in
`tests/Feature/Livewire/AffordabilityTest.php` builds its failing child, then give it a completed full
run with `completedRun()` at, say, 85%. No engine change.

## Comments
