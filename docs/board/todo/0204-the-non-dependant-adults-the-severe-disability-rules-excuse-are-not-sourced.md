---
not_for_the_loop: needs web to read the regulations, which an unattended session cannot reach
---
# The adults the severe-disability rules excuse are not listed from a source

## Why
Card 0121 added `Household::$nonDependantAdults`: any other adult who normally lives in the home
blocks the Pension Credit severe-disability addition. The regulations excuse some adults from that
count. The builder help text says only "the rules excuse some adults, so check with a benefits
adviser", because the unattended session that built it had no web and could not read the list.
So the reader has to guess which adults to count, and a wrong guess moves a real award either way.

Found while building card 0121.

## Links

**Relates to**
- `0121` - added the count and its builder field.

## Not this card
The registered-blind route into the addition, which stays on card 0121.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL name, beside the "other adults" builder field, which adults the rules excuse, from a cited source with a verified-on date. proves: none
<!-- AC:END -->

## Plan
Read the State Pension Credit Regulations 2002, Schedule I, on legislation.gov.uk. Put the list in
the help text in `resources/views/livewire/scenario-builder.blade.php` and the source in
`docs/spec/ASSUMPTIONS.md`.

## Comments
