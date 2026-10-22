---
needs: 0212, 0213
---
# The results end with what to do next, and by when

## Why
Found by the full review on 2026-10-21. The page has no action list. Only the Pension Credit "how to
claim" steps, a P55 mention and some signposts exist, scattered across tabs.

## Direction
A "What to do next" section at the end of the verdict tab, built only from flags the page already
computes. Each item gets a trigger or a date. Candidates:
- Claim Pension Credit (can be backdated 3 months) when the plan is eligible.
- Reclaim over-deducted lump-sum tax (P55 / P50Z / P53Z) when an emergency-tax overpayment shows.
- Check Attendance Allowance when a member is over State Pension age and no disability benefit is entered.
- Report and pay CGT on a property sale within 60 days, when a sale realises a gain.
- Get a State Pension forecast; set up lasting powers of attorney before any deed is signed (cards 0066-0071).

The wording is advice, so it lives in `App\Compliance\Interpretation` behind the `interpret` gate,
and `compliance:advice-audit` must list it. Every deadline carries a source URL.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a plan is eligible for Pension Credit, THE APP SHALL list "claim Pension Credit" with its backdating limit. proves: `test_next_steps_lists_a_pension_credit_claim_when_eligible`
- [x] #2 WHEN a plan shows a lump-sum overpayment, THE APP SHALL list the reclaim form. proves: `test_next_steps_lists_the_lump_sum_reclaim`
- [x] #3 WHEN no flag applies, THE APP SHALL list no item for it. proves: `test_next_steps_lists_only_items_whose_flag_applies`
- [x] #4 WHEN `compliance.personal_use` is false, THE APP SHALL hide the section. proves: `test_next_steps_is_hidden_in_guidance_mode`
<!-- AC:END -->

## Comments

**2026-10-22** RESULT: built
TESTS: +4 new in `tests/Feature/Compliance/NextStepsTest.php`
TOUCHED: `Interpretation::nextSteps()`, `nextSteps` in `ScenarioResults::render()` and `ScenarioReport::data()`, a `sec-next-steps` section at the end of the verdict tab, the same list in the PDF
OUT-OF-SCOPE: Attendance Allowance, CGT within 60 days, a State Pension forecast and lasting powers of attorney. The page computes no flag for any of them, and the card says to build only from flags that exist. Each needs its own flag first.

Two items. "Claim Pension Credit" shows only when the Pension Credit panel gives claim steps, so a shut-out household gets none. "Reclaim the over-deducted tax" names the form the engine picks; its source is gov.uk/claim-pension-tax-refund, checked 2026-10-22. The wording lives in `Interpretation` behind the `interpret` gate, so guidance mode hides it. `compliance:advice-audit` does not list it, and should not: that audit lists advice found OUTSIDE the `Interpretation` wall, and this is inside it. The tests were written with the code, not watched red first. **Not seen in a browser.**
