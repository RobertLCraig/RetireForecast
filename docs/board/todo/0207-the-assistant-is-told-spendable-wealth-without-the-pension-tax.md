# The assistant is told spendable wealth without saying the pension tax is off

## Why
Card 0142 made every on-screen and printed label over usable wealth say "after pension tax". The
assistant's context still names the same figure as only excluding the home:

- `app/Assistant/ScenarioContext.php:100` and `:300`, "Spendable wealth left at the end (excludes the home)".
- `app/Assistant/ComparisonContext.php:57`, the same words for each compared plan.

The assistant repeats these labels to the reader, so its answer can present a net figure as the
whole pot. Found while building card 0142, which kept to the page and PDF labels.

## Links

**Relates to**
- `0142` - reworded the page, PDF and builder labels.
- `0076` - netted the pension tax out of the figure.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE ASSISTANT CONTEXT SHALL label spendable wealth as net of the tax on the pension part. proves: `test_the_assistant_context_says_spendable_wealth_is_after_pension_tax`
<!-- AC:END -->
