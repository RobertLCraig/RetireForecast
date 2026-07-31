# Spreadsheet import: the line-item expense-category decision

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
