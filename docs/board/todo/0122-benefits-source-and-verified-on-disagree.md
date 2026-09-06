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
- [ ] THE APP SHALL record one verified-on date per source, so the registry and the parameters cannot disagree. proves: `test_every_source_carries_its_own_verified_on_date`
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
