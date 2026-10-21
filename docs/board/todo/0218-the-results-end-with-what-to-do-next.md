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
- [ ] #1 WHEN a plan is eligible for Pension Credit, THE APP SHALL list "claim Pension Credit" with its backdating limit. proves: `test_next_steps_lists_a_pension_credit_claim_when_eligible`
- [ ] #2 WHEN a plan shows a lump-sum overpayment, THE APP SHALL list the reclaim form. proves: `test_next_steps_lists_the_lump_sum_reclaim`
- [ ] #3 WHEN no flag applies, THE APP SHALL list no item for it. proves: `test_next_steps_lists_only_items_whose_flag_applies`
- [ ] #4 WHEN `compliance.personal_use` is false, THE APP SHALL hide the section. proves: `test_next_steps_is_hidden_in_guidance_mode`
<!-- AC:END -->
