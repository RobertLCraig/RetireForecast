# Spreadsheet import: the line-item expense-category decision

## What I need from you

**Two answers, in order: is the `.xlsx` import wanted at all, and if so which option below?**

**Pass** is a yes or no, plus an option number if yes. Nothing else is blocked on this, so **"no" is
a real answer** and closes the card outright.

**Fail** is "yes, decide the mapping later". The choice decides the storage shape, so deferring it
past the first import means migrating data rather than choosing a column.

**Why it needs you** The three options differ on what you would want to do with an imported budget
afterwards rather than on anything measurable, and whether the import is wanted at all is a question
about your own workflow.

## Why
The `.xlsx` import path is blocked on one data-model call: how line-item expense categories map
into the model. The IWT CSP question needs re-verifying against a real export at the same time.

## Options
1. **Map to the existing expense categories**, dropping anything that does not fit. Simplest,
   lossy, and the loss is invisible once imported.
2. **Carry the source category as a free-text label** alongside the mapped one, so an import can
   always be traced back to the row it came from.
3. **Defer the import entirely.** Nothing currently depends on it.

## Recommendation
Option 2 if the import is wanted at all, because an import you cannot audit against the source
spreadsheet is worse than no import. But settle first whether it is wanted: no other card is
blocked on it.

## Decided
<!-- -->

## Comments
<!-- The card's thread, appended by ProgressBoard. Append-only: entries are added, never edited or removed. An entry beginning **Decided:** is an answer, and that is what a decision card exits on. -->

**2026-08-26** **Decided:** No. The xlsx import is not wanted, which the card itself says is a real answer that closes it outright: nothing else is blocked on it. The category data model question below falls away with it and option 2 is not taken - it was conditional on the import being built.
