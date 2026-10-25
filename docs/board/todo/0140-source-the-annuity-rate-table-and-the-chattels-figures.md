# Source the annuity rate table and the chattels figures

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
Card 0065 replaced a single free-text annuity rate with a table by age, escalation basis and
joint-life setting, and added the chattels capital-gains rule. Every figure behind both was
**stated, not read off a live page**, because the unattended build loop has **no web access**.

- **The annuity rate table** (`Pension\AnnuityRateTable`). Seven base rates by age for a single
  life, level, standard health, plus an index-linked multiple of 0.62 and a joint-life reduction of
  20% at a full survivor's pension. Written from the standing shape of the UK open-market option as
  the published comparison tables have carried it, not from a dated quote. It reaches a projection
  through the builder's rate field, which the reader can see and type over, so nothing it decides is
  invisible; but a reader with no quote of their own gets these numbers.
- **The chattels exempt amount, £6,000, and the five-thirds marginal relief**
  (`CgtParameters::$chattelsExemptAmount` and `Tax\ChattelsGain`). Both are statute rather than
  judgement, and the statute is cited (TCGA 1992 s262), but neither was checked against the current
  gov.uk page. The £6,000 in particular is believed never to have been uprated since 1989, which is
  exactly the kind of belief that is worth five minutes of checking.

What it costs: the annuity figures decide how good annuitising looks against drawdown, which is one
of the two or three comparisons this tool exists to run. An over-generous table makes secured income
look cheap; an over-mean one buries it.

## Links

**Relates to**
- `0065` - built the table, the tax-free lump sum split and the chattels charge.
- `0136` - the purchased-life-annuity tax split and the enhanced uplift, the same subject.
- `0106`, `0109`, `0111`, `0113`, `0118`, `0125`, `0127`, `0129`, `0132`, `0134`, `0135`, `0137`,
  `0138`, `0139` - the same shape of gap from the same missing web access.

## Not this card
Pricing what the table deliberately leaves out (a guarantee period, value protection, the spouse's
age, the buyer's postcode, gilt yields moving the market). Each of them makes a real quote LOWER
than this table, so leaving them out is the flattering direction and is worth its own card, but it
is a change of shape and not a re-sourcing.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for every figure in
      docs/spec/ASSUMPTIONS.md sections 36 and 37, or record there that the search found none.
      proves: manual
- [ ] WHEN a sourced figure differs from the stated one, THE APP SHALL re-pin the constant to it.
      proves: `test_choosing_an_escalating_annuity_recalculates_the_rate_from_the_table`
<!-- AC:END -->

## Tasks
- [ ] Take a dated snapshot from a live open-market-option quote service (Money Helper's annuity
      comparison tool, or the Hargreaves Lansdown / Legal and General best-buy tables) across the
      age range, for single and joint life and for level and index-linked.
- [ ] Re-pin `AnnuityRateTable::LEVEL_SINGLE_LIFE_BPS`, `INDEX_LINKED_MULTIPLE` and
      `FULL_SURVIVOR_REDUCTION`, and move `VERIFIED_ON` to the snapshot date.
- [ ] Confirm the £6,000 chattels exempt amount against the current gov.uk page and re-stamp the
      CGT block's verified-on date.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if any figure that reaches a stored plan moves, and
      note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Two files hold every
figure: `packages/finance-engine/src/Pension/AnnuityRateTable.php` and
`packages/finance-engine/src/TaxYear/TaxYearRegistry.php`. The builder, the assembler and the
methodology page all READ the table rather than restating it, so moving a constant moves every
sentence about it.

Run `php artisan test` after, then `php artisan figures:freshness`, which now sweeps the economic
assumptions as well as the statutory ones.

## Comments

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card needs live web pages, and this unattended session has none. WebSearch and WebFetch were both refused on permission (tried gov.uk for the chattels page). The card's own Plan already says it needs a session with web access.

Criterion 1 not met: I did not write 'the search found none' into ASSUMPTIONS 36/37, because no search ran. Saying so would be a false record.
Criterion 2 not met: it only applies once a sourced figure exists to compare against. No figure was sourced, so no constant moved and ENGINE_VERSION stays.

Next step: run this card in an attended session with web access, standing in C:\Dev\RetireForecast as the Plan says. Consider not_for_the_loop: true in its frontmatter so the loop stops picking it up.

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same block. This card needs live web pages. WebFetch (gov.uk capital-gains-tax-personal-possessions) and WebSearch were both refused on permission again, so no figure was sourced.

Criterion 1 not met: no search ran, so writing 'the search found none' into ASSUMPTIONS 36/37 would be a false record.
Criterion 2 not met: it applies only once a sourced figure exists to compare against. No constant moved and ENGINE_VERSION stays.

The loop cannot advance this card. Run it in an attended session with web access, in C:\Dev\RetireForecast as the Plan says, or set not_for_the_loop: true in its frontmatter so the loop stops picking it up.

**2026-10-19** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-19 manager (m20261019231442-560d)

**outcome: rob**

The work is to read live gov.uk and annuity quote pages, and only a session with web access can do that.

**what the session said**

Both criteria on card 0140 are still open. No reviewer found a problem in the code, so there is nothing to untick. The only block is that this work needs the web, and the loop has none.

I checked the code. The figures are still the old ones that nobody has checked:
- `AnnuityRateTable` still has `INDEX_LINKED_MULTIPLE = 0.62`, `FULL_SURVIVOR_REDUCTION = 0.20` and `VERIFIED_ON = '2026-09-08'`. Its own docblock says that date is when the figures were written down, not when they were checked.
- `TaxYearRegistry` still sets `chattelsExemptAmount` to £6,000.

If I reopen the card, the loop gets refused on WebFetch and WebSearch a third time. Then the card comes straight back here. This is not a question for the owner either, because the card already says exactly what to do.

To finish it, one person must run it in a session that has web access, from `C:\Dev\RetireForecast` on `master`. Or they can add `not_for_the_loop: true` to its frontmatter so the loop stops picking it up.

WHY: The work is to read live gov.uk and annuity quote pages, and only a session with web access can do that.

OUTCOME: rob


**2026-10-25** **Research, 2026-10-25 (attended session, web)**
