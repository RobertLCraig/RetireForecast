# Pension Credit assesses earnings gross, with no earnings disregard

## Why
`PathProjector::pensionCreditAward` builds its assessable income out of `$taxablePerPerson`, which
is the GROSS figure. Pension Credit does not work that way. Earnings are assessed **net** of income
tax, National Insurance and half of any occupational or personal pension contribution, and then a
small weekly **earnings disregard** comes off what is left.

The engine applies neither, so a household with any earnings at all is assessed on more income than
the DWP would assess it on, and is therefore **under-awarded**. That is the safe direction, but it
is still wrong, and it bites exactly the household this tool is about: one partner still working
while the other has retired.

It also weakens card 0046's near-miss warning, whose message already says the engine "applies no
income disregards" and so the real gap can be smaller. That sentence is an apology for this defect.

## Links

**Relates to**
- `0051` - found it in the expert-panel review and left it here; it needs sourced figures.
- `0046` - the near-miss warning that exists partly because of this gap.

## Not this card
Non-earned income. This is the earnings rule only.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a member has earnings, THE APP SHALL assess them net of income tax, National Insurance and half of any pension contribution. proves: `test_pension_credit_assesses_earnings_net`
- [ ] THE APP SHALL apply the weekly earnings disregard before the guarantee is compared. proves: `test_the_earnings_disregard_comes_off_assessable_earnings`
- [ ] THE APP SHALL carry each disregard figure with its source URL and verified-on date. proves: none
<!-- AC:END -->

## Tasks
- [ ] Fetch the single, couple and higher earnings disregards with a source. **Needs web, so not an
      unattended card.**
- [ ] Add them to `BenefitsParameters` beside the guarantee figures.
- [ ] Assess earnings net in `pensionCreditAward`, taking the tax and NI the year already computed
      rather than recomputing them, so one quantity keeps one definition.
- [ ] Bump `ENGINE_VERSION` and re-run every stored scenario: any plan with earnings in a Pension
      Credit year moves, and moves UP.
- [ ] Revisit the near-miss warning copy, which currently apologises for this.

## Plan
`packages/finance-engine/src/Forecast/PathProjector.php` (`pensionCreditAward`) and
`packages/finance-engine/src/TaxYear/BenefitsParameters.php`. The projector already holds the year's
per-person tax and NI, so the netting should read those. Run `php artisan test` and
`php artisan scenarios:audit`.

## Comments
