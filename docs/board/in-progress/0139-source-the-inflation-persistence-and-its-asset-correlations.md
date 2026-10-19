# Source the inflation persistence and its correlation with real returns

## Why
Card 0064 gave the Monte Carlo's inflation draw a memory and a place in the correlation matrix.
Both figures behind it were **stated, not read off a live page**, because the unattended build loop
has **no web access**.

- **The persistence, 0.70** (`Assumptions\AssumptionSetLibrary::INFLATION_PERSISTENCE`). The share
  of one year's deviation from mean inflation carried into the next, as a first-order
  autoregressive coefficient. Argued from the shape of the UK record (inflation arrives in
  multi-year episodes) and from "around 0.7 is the standing range for annual UK inflation", which
  is memory of the literature rather than an estimate off a series. No series has been fitted here
  and no citation is on file.
- **The correlations, `[-0.30, -0.50, -0.55]` against equities, gilts and cash**
  (`Assumptions\AssumptionSetLibrary::INFLATION_ASSET_CORRELATIONS`). The ORDERING is argued from
  first principles and is not in doubt: an inflation shock is worst for the asset whose cash flows
  are fixed in money. The three MAGNITUDES are judgement.

What it costs: unlike the guardrail's figures, these reach every stored scenario the moment it is
re-run, because they are on all three shipped assumption sets. They set how wide the fan opens and
therefore every success probability, percentile band and capacity-for-loss reading in the tool.
Too low and the tool understates the tail it exists to show; too high and it prices the whole
portfolio as one bet on inflation.

## Links

**Relates to**
- `0064` - built the AR(1) draw, the matrix row and their disclosure.
- `0137` - re-sources the asset classes' own returns and volatilities, in the same file.
- `0106`, `0109`, `0111`, `0113`, `0118`, `0125`, `0127`, `0129`, `0132`, `0134`, `0135`, `0136`,
  `0138` - the same shape of gap from the same missing web access.

## Not this card
Changing the SHAPE of the model (a regime-switching inflation process, a stochastic mean, a
term-structure of inflation expectations). If the research says an AR(1) is the wrong form, raise
that as its own card rather than widening this one.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for both figures in docs/spec/ASSUMPTIONS.md section 35, or record there that the search found none. proves: manual
- [ ] WHEN the sourced figures differ from the stated ones, THE APP SHALL re-pin the constants to them. proves: `test_the_shipped_sets_all_carry_a_persistence_and_asset_correlations`
<!-- AC:END -->

## Tasks
- [ ] Estimate the AR(1) coefficient from an ONS CPIH or RPI annual series long enough to be
      meaningful, or find it published.
- [ ] Find a published correlation matrix of UK REAL asset returns against inflation. The Barclays
      Equity Gilt Study and the DMS/UBS yearbook both report inflation sensitivities.
- [ ] Re-pin both constants, or record in ASSUMPTIONS.md section 35 that no primary source was found.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if either figure moves, re-pin
      `MonteCarlo\GoldenMasterTest` and write its DECISIONS entry, and note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. One file holds both
figures: `packages/finance-engine/src/Assumptions/AssumptionSetLibrary.php`, and all three shipped
sets read the same two constants, so moving a constant moves every set and every sentence about it.
`ResultPresenter::assumptionsPanel` reads the set rather than restating the figures.

Run `php artisan test` after. Expect `MonteCarlo\GoldenMasterTest` red beside any change to either
figure: re-pin it, bump its `PIN_REVISION`, and add the DECISIONS entry its companion test demands.

## Comments

**2026-10-19** RESULT: blocked
TESTS: +0 new, suite not run (no code or doc changed)
TOUCHED: none
OUT-OF-SCOPE: none

Blocked on web access, as the card's own Plan predicts ("Needs a session with web access"). In this unattended session both WebSearch and WebFetch were refused at the permission prompt, so no ONS CPIH/RPI series could be fetched to fit the AR(1) coefficient and no Barclays Equity Gilt Study or DMS/UBS yearbook page could be read for the inflation-to-real-return correlations.

I checked the repository for a local copy of either: none. docs/research/ mentions DMS and the Barclays study only as licence-blocked sources (RESEARCH-stress-test-and-official-sources.md:58); no inflation series or correlation table is on file.

Criterion 1 not met: I did not write "the search found none" into ASSUMPTIONS.md section 35, because no search ran; recording that would claim a negative result that was never looked for. Criterion 2 not met: with nothing sourced there is nothing to compare 0.70 and [-0.30, -0.50, -0.55] against, so the constants, ENGINE_VERSION and the Monte Carlo golden master are untouched.

To advance: run this card in an attended session with web access (or grant WebSearch/WebFetch to the loop). Without that the loop will block on it every take; it wants not_for_the_loop: in its frontmatter, which I may not add myself.
