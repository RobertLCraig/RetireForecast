# Pension and free-gains draws still take from the first-declared person first

## Why
Found building card 0101. That card made the cash, GIA and ISA draws in
`PathProjector::fundShortfall` take from both holders in proportion. Three other passes in the
same method still walk `$household->persons` in declaration order:

- `$drawPension` and `$drawPensionUfpls` fill the first person's band from their pots before the
  second person's pots are touched. When the year's need fits inside the first person's band, the
  second person draws nothing. Their pots, their Lump Sum Allowance use and their MPAA trigger all
  then depend on typing order.
- `$drawGiaToAea` uses the first person's CGT annual exempt amount before the second's. When the
  need is smaller than the two allowances together, the second person's allowance goes unused that
  year and the first person's GIA shrinks alone.

Each person's band and allowance is their own, so the fix is a rule for splitting ONE need across
two people's headroom, not the pro-rata-to-balance rule 0101 used.

## Links

**Relates to**
- `0101` - fixed the same walk for the cash, GIA and ISA buckets and left these three passes as they were.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a shortfall is met by a pension draw or a free-gains GIA draw and both living people have headroom, THE APP SHALL produce the same forecast whichever order they were entered in. proves: `test_swapping_the_order_leaves_a_pension_funded_shortfall_unchanged`
<!-- AC:END -->

## Tasks
- [ ] Split each pass's need across the living people's headroom without favouring declaration order.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`.
