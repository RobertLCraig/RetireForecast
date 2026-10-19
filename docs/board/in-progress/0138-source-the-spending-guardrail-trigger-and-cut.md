---
no_outward_effect: "published" names a rule somebody else printed, read here; nothing is published by us
---

# Pin the spending guardrail's trigger and cut to a published rule

## Why
Card 0063 built one funded-ratio guardrail: below a multiple of the essential spend the plan still
has to fund, discretionary spend is cut by a set percentage until wealth recovers. Both figures
behind it were **stated, not read off a live page**, because the unattended build loop has **no web
access**.

- **The trigger, a funded ratio of 1.00** (`Dto\SpendingGuardrail::DEFAULT_TRIGGER_FUNDED_RATIO_BPS`).
  Argued from the actuarial definition of a fully funded plan, not from a published decumulation
  rule. Nothing on file says a funded-ratio trigger is the better-evidenced FORM either: the
  best-known published guardrails (Guyton-Klinger, Kitces' ratcheting rules) trigger on the
  WITHDRAWAL RATE moving away from its starting value, which is a different test that bites at
  different times.
- **The cut, 10% of discretionary spend** (`Dto\SpendingGuardrail::DEFAULT_DISCRETIONARY_CUT_BPS`).
  Attributed to the capital-preservation rule in Guyton and Klinger's decision-rules work, from
  memory of the literature. **The paper has not been read here** and no citation is on file.

What it costs: the guardrail is opt-in, so neither figure reaches a stored scenario yet. The moment
one is turned on, the two decide how often the household is modelled cutting back and by how much,
which is the whole of the survival probability the guardrail buys. A guardrail is the one setting
in this tool that makes a plan look BETTER, so an unsourced one is the most flattering kind of
invisible figure.

## Links

**Relates to**
- `0063` - built the guardrail, its two constants and their disclosure.
- `0106`, `0109`, `0111`, `0113`, `0118`, `0125`, `0127`, `0129`, `0132`, `0134`, `0135`, `0136`,
  `0137` - the same shape of gap from the same missing web access.

## Not this card
Adding a second rule shape (a withdrawal-rate guardrail beside the funded-ratio one). If the
research says the published rules trigger on the withdrawal rate, raise that as its own card rather
than widening this one.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for both figures in docs/spec/ASSUMPTIONS.md section 34, or record there that the search found none. proves: manual
- [ ] WHEN the published rule uses a different trigger or a different cut, THE APP SHALL re-pin the constant to it. proves: `test_the_guardrail_cuts_discretionary_spend_while_the_plan_is_underfunded`
<!-- AC:END -->

## Tasks
- [ ] Read Guyton and Klinger's decision-rules paper and confirm the size of the capital-preservation
      cut, and what it is a cut OF.
- [ ] Find whether a funded-ratio trigger is published anywhere with a pinned multiple, or whether
      the citable rules all trigger on the withdrawal rate.
- [ ] Re-pin both constants, or record in ASSUMPTIONS.md section 34 that no primary source was found.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if either figure moves AND any stored scenario has
      the guardrail turned on; note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. One file holds both
figures: `packages/finance-engine/src/Dto/SpendingGuardrail.php`. Nothing restates them: the
projector, the builder's two placeholders and `ResultPresenter::assumedFigures()` all read the
constants, so moving a constant moves every sentence about it.

Run `php artisan test` after. `Forecast\SpendingGuardrailTest` reads the constants rather than
restating them, so a corrected figure keeps it green; the disclosure test in
`Feature\Forecast\SpendingGuardrailNoticeTest` does the same.

## Comments
