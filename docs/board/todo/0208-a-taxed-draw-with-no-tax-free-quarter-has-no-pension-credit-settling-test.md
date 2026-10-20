# A taxed draw with no tax-free quarter has no Pension Credit settling test

## Why
Card 0145 found that the Pension Credit award loop in `PathProjector::projectYear` never settled when
every pound drawn is assessed. A survivor on Guarantee Credit drawing their own pot under the
TaxEfficient order (no tax-free quarter) bounced between two answers for eight passes and kept the
UNREDUCED award: in the 0145 fixture, 2028 kept £7,539 of credit beside a taxed draw of £997.

Card 0145 fixed the loop for its own inherited draw, and the same line now fixes the taxed case. But
no test pins the taxed case. A later change to the step could break it again and stay green.

## Links

**Relates to**
- `0145` - found it and changed the step.
- `0077` - built the award loop.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL settle the Pension Credit award on a taxed draw that has no tax-free quarter. proves: `test_a_taxed_draw_with_no_tax_free_quarter_settles_the_pension_credit_award`
<!-- AC:END -->

## Plan
The fixture in `packages/finance-engine/tests/Forecast/InheritedPensionDrawPensionCreditTest.php`
with the pot owned by `p2` and `DrawdownStrategy::TaxEfficient` builds the state: assert 2028 pays the
award its own draw leaves. Watch it fail by putting back the old step (secant whenever the two gaps
differ, plain step otherwise).

## Comments
