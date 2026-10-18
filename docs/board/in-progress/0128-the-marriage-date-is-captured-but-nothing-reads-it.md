# The marriage date is captured and nothing reads it

## Why
Card 0054 added the date of the marriage or civil partnership to the builder, because it decides
which State Pension inheritance rules a survivor falls under: the rules turn on whether the couple
were married before 6 April 2016, and the old and new schemes give a survivor materially different
amounts. That card's criterion was to CAPTURE it, and it does.

Nothing consumes it. The forecast still models no State Pension inheritance for anybody: each
person's State Pension stops on their death, which is stated in the cohabiting-couple note but is
just as wrong for a married one. For a couple where one has a large protected payment or a
pre-2016 additional State Pension, the survivor's secure income is understated for the whole of
their remaining life, and the survivor's years are exactly where a plan runs short.

The field, the builder input and the disclosure exist, so the work is the rule and its sourcing.

## Links

**Relates to**
- `0054` - captured the date this card would read.

## Not this card
The cohabiting case. A cohabiting partner cannot inherit State Pension at all, and the results page
already says so.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a married person dies, THE APP SHALL pay the survivor whatever State Pension they can inherit under the scheme the marriage date puts them in. proves: `test_a_survivor_inherits_state_pension_under_the_pre_2016_rules`
- [ ] THE APP SHALL state on the results page which scheme it applied and why. proves: `test_the_state_pension_inheritance_note_names_the_scheme`
<!-- AC:END -->

## Tasks
- [ ] **Needs web to source the two inheritance schemes, so probably not an unattended card.**
- [ ] Read `Household::$marriageDate` in the projector's survivor settlement.
- [ ] Every figure with a `source` URL and a `verified_on` date.
