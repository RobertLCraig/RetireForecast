# A care-forced sale charges rent to someone living in a care home

## Why
Found while building card 0090.

A lifetime mortgage falls due when the last surviving borrower goes into permanent care, and the
projector sells the home that year (`PathProjector::equityReleaseRedeemedByCare`, card 0056). From
that year on the home is gone, so the rent line (`$settings->annualRent`, charged wherever the
household no longer owns a home) starts charging rent. But nobody is renting. The only person left
lives in the care home and already pays the care fee for it.

So that plan pays for two homes every year until the end: the care fee and a rent. Its wealth,
depletion year and estate are all too low. It is silent: no note says rent is being charged.

It came about because the rent line was written for the maturity forced sale, where the household
does move into a rented home. The care trigger was added later and reuses the same sale, and the
rent condition never learned the difference. Card 0090 kept the tenancy deposit off this route for
the same reason, but left the rent alone because the rent is not its subject.

## Links

**Relates to**
- `0090` - charges the tenancy deposit on a maturity forced sale only, and names this gap.
- `0056` - added the care trigger for the equity-release sale.

## Not this card
The tenancy deposit (card 0090). A couple where one partner goes into care and the other stays at
home: that sale does not fire, because it needs the LAST borrower in care.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a home is sold because its last surviving borrower went into permanent care, THE APP SHALL charge no rent for any year that person is in care. proves: `test_a_care_forced_sale_charges_no_rent_while_the_resident_is_in_care`
<!-- AC:END -->

## Tasks
- [ ] Find where the care years are known in `PathProjector::projectYear` and gate the rent on them.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`; stored plans with a care-forced sale and a rent
      figure need re-running.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The rent charge is in
`packages/finance-engine/src/Forecast/PathProjector.php` (search `$settings->annualRent !== null && ! $ownsHome`);
the care-forced sale is `$saleForcedByCare` in the same method. The equity-release care tests are
the fixture to extend (search the engine tests for `equityReleaseRedeemedByCare` or card 0056). Run
`php artisan test --testsuite=Engine` from PowerShell, then `php artisan test`.

## Comments
