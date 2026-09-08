# Two ENGINE_VERSION bumps are missing from the stamp's own log

## Why
Found while building card 0056. `ScenarioForecaster::ENGINE_VERSION` carries a docblock that
records every bump, newest first, so a stored run's stamp can be read back to what changed and
which way the figures moved. Two bumps never got their paragraph:

- `finance-engine/intestacy-on-the-first-death` (card 0054)
- `finance-engine/deferred-care-payment-on-the-home` (card 0055)

The constant was set to the second of those, but the log's newest entry is still
`rnrb-downsizing-addition` (card 0053). So the log skips two entries and, worse, a reader taking
the top entry as the current stamp reads the wrong name.

Both cards were built in parallel worktrees against the same file, which is how the const edit
landed and the docblock edit did not.

What it costs: the log is the only place that says which direction a stored figure moved under an
older stamp. Card 0054's bump makes every plan modelling Inheritance Tax pay MORE and card 0055's
moves every plan reaching an unfundable care year, and neither statement survives anywhere a
reader of the constant will look. The audit trail is the point of the stamp.

## Links

**Relates to**
- `0054` - the intestacy bump.
- `0055` - the deferred care payment bump.
- `0056` - added its own entry above them and found the gap.
- `0053` - the downsizing-addition bump the log's newest entry names, two stamps behind the
  constant.

## Not this card
Any change to the figures. This is the record of changes already shipped.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL record, in the `ENGINE_VERSION` docblock, one entry for each of the two missing bumps, in stamp order, saying what moved and which way. proves: none
<!-- AC:END -->

## Tasks
- [ ] Write the two paragraphs into `app/Forecast/ScenarioForecaster.php`, between the card 0056
      entry and the `rnrb-downsizing-addition` one, newest first.
- [ ] Check no other bump is missing: every distinct stamp name in `git log -S ENGINE_VERSION`
      should appear once in the log.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. Doc only, no test and no figure moves.

The source for both paragraphs is the card's own HANDOVER.md bullet plus its comment thread; both
already say which plans move and in which direction, so nothing has to be re-derived from the code.

## Comments
