# The benefits source points at an eligibility page, and two verified-on dates disagree

## Why
Every tax figure in this engine is supposed to carry a source URL and a verified-on date, and both
of the benefits ones are wrong in a way a reader cannot see.

**The URL is the wrong page.** `TaxYearRegistry` records
`'benefits' => 'https://www.gov.uk/pension-credit/eligibility'` for both tax years. That page says
who can claim. It does not carry the Standard Minimum Guarantee, the severe-disability addition, the
carer addition or the capital rules, which are the figures the key is the source for. A reader
following it to check a number will not find the number.

**The dates disagree.** The registry stamps the whole 2026/27 config `verifiedOn: '2026-06-27'`.
`BenefitsParameters`' own docblock says the capital rules were verified on 2026-06-27 and the
Guarantee Credit figures on **2026-06-30**. Both cannot be the stamp on the same figures. The
registry's date is per tax year, so it cannot express a per-source date, which is the underlying
reason and the thing to fix.

## Links

**Relates to**
- `0051` - found both in the expert-panel review.
- `0044` - the two benefits figures already flagged as stated but not independently verified.

## Not this card
Any change to a figure's VALUE. This card is about what the figures say about themselves.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL point the benefits source at the page that actually carries the rates. proves: `test_the_benefits_source_is_the_rate_table`
- [x] THE APP SHALL record one verified-on date per source, so the registry and the parameters cannot disagree. proves: `test_every_source_carries_its_own_verified_on_date`
<!-- AC:END -->

## Tasks
- [ ] Find the rate-table page and check it carries every benefits figure the engine holds. **Needs
      web, so not an unattended card.**
- [ ] Move `verifiedOn` from one date per tax year to one date per source key, or give the benefits
      key its own, and delete the second date from the docblock so there is one home for it.
- [ ] Sweep the other source keys for the same "eligibility page, not the rates" mistake while the
      lid is off.

## Plan
`packages/finance-engine/src/TaxYear/TaxYearRegistry.php` (the `sources` map and `verifiedOn` on both
tax years) and `packages/finance-engine/src/TaxYear/BenefitsParameters.php`. No figure moves, so no
`ENGINE_VERSION` bump and no stored re-run is owed.

## Comments

**2026-10-16** RESULT: partial
TESTS: +1 new, all green
TOUCHED: packages/finance-engine/src/TaxYear/TaxYearConfig.php
packages/finance-engine/src/TaxYear/TaxYearRegistry.php
packages/finance-engine/src/TaxYear/BenefitsParameters.php
packages/finance-engine/tests/TaxYear/TaxYearSourcesTest.php
OUT-OF-SCOPE: none

#2 built. TaxYearConfig now takes verifiedOnBySource (one Y-m-d per source key, constructor throws if its keys differ from sources). The old per-year verifiedOn is still a public property, but DERIVED as the oldest per-source date, so it cannot disagree and every existing reader (figures:freshness, the audit page, taxyear_config_version on stored runs) keeps working. Its value stays 2026-06-27, so no stored stamp moves and no re-run is owed. The dates live in TaxYearRegistry::verifiedOnBySource(); the 2026-06-30 date is gone from both the BenefitsParameters and the registry benefitsParameters docblocks. test_every_source_carries_its_own_verified_on_date was watched failing first on 'BenefitsParameters states 2026-06-30, which the registry does not record'.

Assumed: a key whose figures were read on different days carries the OLDEST date (it is the one due for a re-check first). So 'benefits' is 2026-06-27, not 2026-06-30. attendance_allowance and carers_allowance are 2026-10-13, from the registry's own 'read 2026-10-13' comments. Every other key is 2026-06-27, from the registry header's go-live pass claim; some carry later spot checks in their docblocks (NI letters 2026-07-03, ISA 2026-07-31, care PEA 2026-07-08), which the oldest-date rule leaves out, and those docblock dates were not removed (the card named only the benefits docblock).

#1 NOT met. WebFetch was refused in this unattended session, as the card's task warned. The benefits URL is unchanged. The likely target is the DWP 'Benefit and pension rates' publication or gov.uk/pension-credit/what-youll-get, but I could not check that either carries the capital rules, so I did not guess. The sweep task (other eligibility pages) also needs web: 2026-27 carers_allowance points at gov.uk/carers-allowance/eligibility, which the comment says carries the earnings limit, so it may be correct. Needs an attended session with web. Not seen in a browser (no UI changed).
