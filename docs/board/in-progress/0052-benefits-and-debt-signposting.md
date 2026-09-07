# Running short on a mortgage is presented as belt-tightening

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 15). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

The model reports unmet spend and moves on. When the unmet spend is a mortgage payment, the
real-world event is possession proceedings, not a smaller weekly shop. Nothing in the tool says so.
Nothing distinguishes a missed council tax payment - the other priority debt, which carries
liability orders and attachment of benefits - from a missed grocery bill. There is no
debt-priority framing anywhere.

There is also no route to help. `sources-and-contacts.blade.php` has three columns: pensions and
money, later-life mortgages, and capital gains. There is no benefits column and no debt column, so
a household whose plan fails is pointed at the investment world and nowhere else.

The reviewer called this the cheapest gap to close in the whole report, because it is copy rather
than engine, and the gap that would embarrass the tool most in front of a caseworker.

Worth adding at the same time: a household facing an unaffordable mortgage in later life can ask
the lender for forbearance under its regulatory duties, and a court has powers to suspend
possession where arrears can be cleared over a reasonable period. Neither is in the tool's
vocabulary.

## Not this card
Modelling arrears, possession or a debt-management plan. This card is framing and signposting.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a plan has unmet spend and a live mortgage, THE APP SHALL state that the shortfall is a secured-debt shortfall and what that means.
- [ ] #2 THE APP SHALL show a benefits and debt column of contacts wherever a plan has unmet spend or a mortgage.
- [ ] #3 THE APP SHALL name mortgage and council tax as priority debts when it reports a shortfall.
<!-- AC:END -->

## Tasks
- [ ] Add the benefits and debt column to `sources-and-contacts.blade.php`
- [ ] Add the secured-shortfall wording to `ResultPresenter`, so the PDF inherits it
- [ ] Add the priority-debt note to the unmet-spend output
