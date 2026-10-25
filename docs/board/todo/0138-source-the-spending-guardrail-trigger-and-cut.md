---
no_outward_effect: "published" names a rule somebody else printed, read here; nothing is published by us
---

# Pin the spending guardrail's trigger and cut to a published rule

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

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

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (nothing changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card needs the web, and this session has none. WebSearch and WebFetch were both refused for permission. The card's own Plan says it needs a session with web access.

No criterion is met. #1 cannot honestly record 'the search found none', because no search ran. #2 depends on #1.

Pointer for the session that has web: docs/research/RESEARCH-competitive-gap-analysis.md (lines 78 and 251) links the Guyton & Klinger 2006 paper PDF at financialplanningassociation.org and summarises it as +/-20% withdrawal-rate bands with +/-10% adjustments. That summary is second-hand and was not read against the paper here. If it holds, two things differ from the constants: the trigger is a withdrawal rate 20% above its starting value, not a funded ratio of 1.00, and the 10% cut is of the whole withdrawal, not of discretionary spend only. Confirm both from the PDF before re-pinning. A withdrawal-rate trigger is a different rule shape, which the card's 'Not this card' says goes to its own card.

Suggest not_for_the_loop: on this card, so the build loop stops picking it up.

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (nothing changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same block. WebSearch and WebFetch were both refused for permission again (WebFetch was tried directly against the Guyton & Klinger 2006 PDF). The card's own Plan says it needs a session with web access.

No criterion is met. #1 cannot honestly say 'the search found none', because no search ran. #2 depends on #1.

The pointer from the first take still holds: docs/research/RESEARCH-competitive-gap-analysis.md links the paper and summarises it second-hand as +/-20% withdrawal-rate bands with 10% adjustments to the whole withdrawal. If the paper confirms that, the trigger is a different rule shape, which 'Not this card' sends to its own card.

This card should carry not_for_the_loop: so the build loop stops picking it up. Rob, or a session with web, has to run it.

**2026-10-19** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-19 manager (m20261019180305-2919)

**outcome: rob**

The work that is left is web research, and the loop cannot reach the web, so a person or a session with web access must do it.

**what the session said**

The code has not changed since the card was written. `Dto\SpendingGuardrail` still holds a 1.00 trigger and a 10% cut. `docs/spec/ASSUMPTIONS.md` section 34 still says nobody has read the Guyton-Klinger paper.

Both criteria are still open, so nothing needs to be unticked. The builder cannot move the card forward. The task needs the web, and the loop refused WebSearch and WebFetch two times. If the card goes back to `todo/`, it will be blocked again in the same way.

You have two jobs:
1. Run this card in a session that has web access. Read the Guyton & Klinger 2006 PDF. The link is in `docs/research/RESEARCH-competitive-gap-analysis.md`. The loop's guess is that the paper's trigger is a change in the withdrawal rate, not a funded ratio. If that is true, record it in ASSUMPTIONS.md section 34. Open a new card for a withdrawal-rate rule. Do not change the rule on this card.
2. Add `not_for_the_loop:` to the card, so that the loop does not take it again.

WHY: The work that is left is web research, and the loop cannot reach the web, so a person or a session with web access must do it.

OUTCOME: rob


**2026-10-25** **Research, 2026-10-25 (attended session, web)**
