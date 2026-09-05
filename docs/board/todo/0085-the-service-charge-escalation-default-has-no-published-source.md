---
waiting_on: web access, which an unattended session does not have - recheck 2026-10-06
---
# The service-charge escalation the model now charges everyone is one reviewer's opinion

## Why
Every stay-put plan that pays a service charge is now charged **CPI + 3% real** on it for life
whenever the reader leaves the rate blank, which is the common case. On a £3,000 service charge over
a thirty-year projection that is over £4,000 a year of extra spend by the end, all of it landing in
the years a survivor is on their own. It is the kind of figure that decides whether the tool says
keep the flat or sell it.

That 3% has no published source behind it. It is the judgement of the property reviewer in the
five-discipline expert review of 2026-08-19, restated on card 0028 and shipped from there. Every
other economic figure in `docs/spec/ASSUMPTIONS.md` cites a primary or fetchable secondary source
with a `verified_on` date; this one, alone, cites a person's opinion. The document says so in §12,
loudly, which is the honest position but not the finished one.

It came to be this way because the session that built card 0028 could not reach the web. An
unattended card session has no `WebSearch` or `WebFetch`, so it could either ship the reviewer's
figure with the gap flagged or ship nothing at all. It shipped the figure, because leaving the rate
at plain CPI is a number the same review positively rules out.

## Links

**Relates to**
- `0028` - built the default and wrote the gap into ASSUMPTIONS.md §12; its comment thread records
  what the session could and could not settle.
- `0084` - the general problem that a card needing a figure looked up cannot be done by the
  unattended loop. This card is an instance of it, and carries `waiting_on:` for that reason.

## Not this card
Changing how the escalation is applied, disclosed or entered. That machinery is built and tested.
Only the number and its citation are in question here.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL cite a published source, with a URL and a verified_on date, for the default above-CPI growth of home-ownership costs. proves: none, because a citation is prose and no test can tell a real source from an invented one
- [ ] #2 WHEN the sourced figure differs from the shipped one, THE APP SHALL apply the sourced figure and the disclosure SHALL move with it. proves: `test_a_blank_rate_escalates_the_bucket_at_the_engine_default`
- [ ] #3 THE APP SHALL state the sourced optimistic and adverse alternatives on the builder input. proves: `test_the_property_cost_growth_input_offers_its_sourced_alternatives`
<!-- AC:END -->

## Tasks
- [ ] Find a published series for UK block service-charge inflation. Candidates worth trying first:
      the ONS CPI/CPIH housing components, Hometrack / Property Management Association service-charge
      surveys, ABI buildings-insurance premium data, and the government's building-safety remediation
      cost reporting.
- [ ] Set `ExpenseProfile::DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS` from it, keeping the
      most-adverse-of-the-plausible rule, and update its docblock.
- [ ] Rewrite `docs/spec/ASSUMPTIONS.md` §12 with the source URLs and a real `verified_on`, and
      delete its sourcing-gap warning.
- [ ] Update the alternatives on the builder input and in `ScenarioBuilderTest`.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if the figure moves, and re-run stored scenarios.

## Plan
Work in `C:\Dev\RetireForecast` on `master`. Run `php artisan test --testsuite=Engine` first; it must
be green before you start. The figure lives in one place,
`packages/finance-engine/src/Dto/ExpenseProfile.php`, as
`DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS` (integer basis points); the builder label and the
results-page disclosure both read it, so changing the constant moves every screen and both tests
above follow it automatically. Nothing else needs editing to change the number.

**This card needs a session with web access**, which the unattended loop does not have. Run it
attended.

## Comments
