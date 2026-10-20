# The Pension-Credit-aware draw order spreads the claw-back instead of concentrating it

## Why
Card 0077 made a taxable pension draw reduce that year's Pension Credit award, which is what the
means test does. That changed which draw order keeps the most credit, and the fill-the-bands order
has not caught up.

The claw-back is capped: it can take the year's award and no more. So the same total pension money
costs a household far less credit when it is drawn in a few large amounts than when it is drawn in
small amounts spread over many years. The Pension-Credit-aware rule inside `fundShortfall` does the
opposite of that. It defers the pension until the capital is gone, which is right for as long as the
capital lasts, and then draws a little every year for the rest of the plan, losing the whole award in
every one of those years.

Measured on the household in
`PathProjectorTest::test_fill_bands_is_pension_credit_aware_and_leaves_the_pension_intact` (a couple
on £120 a week each of State Pension, £60,000 of cash, a £100,000 pot, spending £30,000): the
Pension-Credit-aware order keeps four full years of credit and then loses six, for £201,131 of credit
across the plan, while the order it is supposed to beat concentrates its draws into five early years
and keeps £213,940. It also ends with more unmet spend, not less.

This is a question about the ORDER, not about the means test, which is why card 0077 recorded it
rather than answering it: the arithmetic of the claw-back is settled and tested, and what a household
should do about it is a separate call.

## Links

**Relates to**
- `0077` - made the draw reduce the award, which is what exposed this.
- `0075` - the draw order is the reader's choice, so whatever this decides is a default, not a rule.

## Not this card
The claw-back itself, which card 0077 built and which is right.

Whether the tool should RECOMMEND a lumpier draw to a reader. That is advice, and it belongs
wherever the interpretation layer decides such things.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a household on Guarantee Credit has to draw a pension across several years, THE APP
      SHALL not lose more of the award than a draw of the same total money would. proves: `test_the_pension_credit_aware_order_does_not_spread_the_claw_back`
<!-- AC:END -->

## Tasks
- [ ] Decide what the order should do once the capital is spent: concentrate the remaining draws,
      or draw the capital and the pension together so the award is lost in fewer years
- [ ] Whatever it decides, keep the year-by-year fixed point card 0077 built; this changes how much
      is drawn and when, not how it is assessed

## Comments
