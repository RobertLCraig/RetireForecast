# Mortality data — ONS cohort life tables

_Last updated: 2026-06-24_

The stochastic joint-life model samples each partner's age at death from one-year mortality
probabilities q(x) by age and sex (see [DECISIONS.md](../DECISIONS.md) — "Mortality model:
embed ONS cohort life tables").

## Source (sourced and committed)
- **Dataset:** ONS *Past and projected period and cohort life tables, 2024-based, UK* —
  "Mortality rates (qx), principal projection, United Kingdom". Published 2026-05-15.
- **Geography:** United Kingdom. **Units in source:** per 100,000 (converted to decimal
  probability in our data file).
- **URL:** https://www.ons.gov.uk/peoplepopulationandcommunity/birthsdeathsandmarriages/lifeexpectancies/datasets/mortalityratesqxprincipalprojectionunitedkingdom

## What is committed
`packages/finance-engine/resources/mortality/ons-2024-period-qx.json` — the **period** q(x)
grid for ages 50–100 and calendar years 2025–2074, both sexes, as decimal probabilities,
extracted directly from the ONS workbook.

## How cohort q(x) is obtained (the key method)
ONS cohort sheets are indexed by year of birth from 1981, so a person alive today (born
~1955–65) has no cohort column. The correct, ONS-consistent construction is the **diagonal of
the period table**: a person aged `x` in year `y` experiences `period_q[x, y]`, then
`period_q[x+1, y+1]`, and so on. This embeds future mortality improvement, i.e. it *is* the
cohort curve. Verified against the ONS cohort sheets cell-by-cell by the research step.

## Open items for the build (todo: CohortLifeTable + JointLifeSampler)
- **Ages above 100 / years beyond 2074** are outside the ONS grid and need a documented tail
  (e.g. Gompertz/Kannisto extrapolation, or a capped q ceiling), clearly marked non-ONS.
- Engine stays I/O-free: generate a PHP data class from the JSON at build time rather than
  reading the file at runtime.

## Sanity anchors (ONS)
- Cohort life expectancy at 65 (UK, 2024-based): ≈ 19.8 yrs (male), 22.5 yrs (female).
- Period life expectancy at 65 (UK National Life Tables 2022–2024): 18.7 (male), 21.2 (female).
