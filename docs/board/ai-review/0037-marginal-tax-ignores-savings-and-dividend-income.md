# Drawing from a pension is taxed as if there were no savings or dividends

## Why
From the expert panel, 2026-08-19 (engineer finding F7). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

The year's main tax pass is correctly combined - non-savings, savings and dividends in one
computation. The drawdown gross-up is not: `marginalTax()` and `grossUpPension()` build their
income from `TaxableIncome::ofNonSavings()`.

Savings and dividend income stacks **on top of** non-savings income. So an extra pension
withdrawal pushes it across band boundaries and shrinks the personal savings allowance. Computing
the marginal cost without it understates the tax on the withdrawal.

The year's reported total tax is then a correct pre-drawdown figure plus a too-small increment, so
the household is left holding cash it would not really have. Nothing reconciles the incremental
tax against a recomputed full-year liability, so it is silent.

This bites exactly the households the tool is for: a couple funding retirement from a mix of
drawdown and invested money.

## Not this card
The combined income-tax pass itself, which is correct.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN extra pension income is drawn, THE APP SHALL compute its tax against the person's full income including savings and dividends.
- [x] #2 THE APP SHALL reconcile each year's total tax against a full recomputation from final taxable income, to the penny.
<!-- AC:END -->

## Tasks
- [x] Thread savings and dividend income into the `fundShortfall` draw closure
- [x] Change `marginalTax` and `grossUpPension` to take a full `TaxableIncome`
- [x] Add a year-level tax reconciliation test; it is missing and would have caught this
- [ ] Re-run every stored scenario

## Comments

**2026-09-05**
RESULT: done
TESTS: +3 new, all green
TOUCHED:
  packages/finance-engine/src/Forecast/PathProjector.php
  packages/finance-engine/tests/Forecast/DrawdownMarginalTaxTest.php
  packages/finance-engine/tests/Forecast/PathProjectorTest.php
  app/Forecast/ScenarioForecaster.php
  docs/board/todo/0098-cgt-band-is-judged-on-pre-drawdown-non-savings-income.md
  docs/HANDOVER.md
OUT-OF-SCOPE: 0098

Both criteria met. The three new tests were watched failing first, each for the reason the card
describes: the reported year-0 tax came in at £7,697.60 against a correct £8,055.20 on the savings
fixture and £8,023.75 against £9,148.75 on the dividend one.

What was built. `projectYear` now keeps each person's cash interest and GIA dividends per person,
read off opening balances in the same pass that already taxes them, and hands both to
`fundShortfall`. `marginalTax` and `grossUpPension` take a `TaxableIncome` instead of an int, so
every pricing of a draw goes through the whole income. The band-filling CAPS are deliberately left
on non-savings income: which band the pension itself should fill is the strategy's own question,
not a tax one, and moving it would change what is drawn rather than what it costs.

The reconciliation forced a second fix I did not expect and did not want to make. `fundShortfall`
took `$taxablePerPerson` by value and each draw closure re-read it, so a strategy that draws pension
in more than one pass restarted every later pass from the PRE-drawdown income: PensionAware twice,
FillBands three times, plus the top-up that funds CGT. Nine of nineteen years failed the
reconciliation on that alone after the savings fix landed. It is the same fault in the same two
functions (a draw priced against an income the person does not have) and criterion #2 cannot hold
while it stands, so I fixed it rather than card it: a new `$drawnTaxable` running total, held apart
from `$taxablePerPerson` so the CGT band split and the means test keep reading the pre-drawdown
figure they were assessed on. Flagging it here because it is wider than the card's title.

One test drifted legitimately and was re-pinned, not weakened:
`PathProjectorTest::FILL_BANDS_LIFETIME_TAX_BEFORE_UFPLS` is 4p lower (10,353,806). Its couple's
interest stays inside the Personal Savings Allowance and its draws stay in the basic-rate band, so
that 4p is the multi-pass fix, not the savings one. The reason is written into the constant's
docblock.

Assumed. That "THE APP SHALL reconcile" is met by a test, per the project's own data-integrity rule
("assert reconciliation invariants in tests") and the card's own task line. A runtime check would
mean a second full income-tax computation per person per year in the 10k-path hot loop, with
nothing sensible to do on a mismatch.

The reconciliation test uses PensionAware, because under FillBands the reported `pension_drawdown`
includes the tax-free quarter (card 0074) and a test cannot recover the taxable split from
`incomeBySource`. I checked FillBands by hand anyway: its residual gap is exactly the tax-free
quarter at the marginal rate in every year I sampled, so it reconciles too once 0074's mis-filing
is allowed for.

`ScenarioForecaster::ENGINE_VERSION` is now `finance-engine/drawdown-marginal-tax-on-full-income`.
Any stored plan that both holds unwrapped savings or shares and draws a pension to meet its spending
paid too LITTLE tax, so its wealth, depletion year and success odds are too favourable; a plan whose
taxable accounts are all ISAs and which never draws is byte-identical.

Not done. **The stored-scenario re-run is owed.** This was built in a worktree, whose `.env` is a
hard link to the live one, so re-running would write to Rob's database while he may be using it, on
the same precedent as cards 0028 to 0036. Nothing here has a screen, so there is nothing new to look
at in a browser.

Raised as 0098: `capitalGainsTax` bands a realised gain against the pre-drawdown, non-savings-only
income, so the household that sells holdings to fund a withdrawal has its gain charged lowest. Same
shape as this card, outside its acceptance, left alone.
