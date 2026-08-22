# Optional refinements to built features

## Why
All flagged v1 limits, recorded in DATA-MODEL "Known divergences" and docs/build/PLAN.md. None
is a correctness gap; each is a known simplification to pick off by value.

## Not this card
Anything that is a correctness gap. There is no OPEN correctness gap from the adviser-parity
sweep remaining.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHERE a refinement below is built, THE APP SHALL remove its corresponding entry from
      DATA-MODEL "Known divergences" in the same change.
<!-- AC:END -->

## Tasks
- [ ] Care flags: age-conditioning of the onset rate
- [ ] Care flags: sex split of care *duration* (probability is already sex-differentiated)
- [x] Means-test v1: Pension Credit counted into the contribution
- [ ] Means-test v1: LA-versus-self-funder fee gap
- [x] CGT deemed-occupation absences
- [ ] Annuitisation retirement-month override

## Direction
**2026-08-22** Built two of the six; the other four are open, each for a reason below.

**Built. Pension Credit is now assessable income for the care charge.** The charging regulations take
income into account unless it is expressly disregarded, and Guarantee Credit is not disregarded. It is
tax-free, so it never reached the taxable figure the means test read: the household banked the award as
income and was never charged it back, though in life it goes to the home. `PathProjector`'s care leg now
adds the household award split per living member to each resident's assessable income. *Assumed:* the
per-head split, because England assesses each resident individually and a couple with one partner in
permanent care is treated as two single people for the credit. Still flagged: that couple award is not
re-computed as two single awards, and the severe-disability addition is not withdrawn on a placement.
Guard: `CareMeansTestedChargeTest` asserts the care year's spend step equals the resident's own income
plus the year's reported credit, less the PEA read from the registry that owns it.

**Built. CGT deemed-occupation absences.** Three new period kinds on the wizard's occupation timeline
(away any reason, away for a job elsewhere in the UK, working abroad). `CgtHistory` carries raw months,
`CgtParameters` carries the statutory caps (36 / 48 / uncapped) and `CgtPrivateResidenceCalculator`
applies them; `HouseholdAssembler` owns the test only a timeline can answer, which is whether the
absence is bracketed by real occupation (the return excused for the two work absences, as the statute
reads). `CgtResult::deemedOccupationMonths` reports what survived the caps and the wizard shows it as
"Away, still counted", so a capped allowance cannot read as an uncapped one. This replaces the old
workaround, where the wizard told the reader to mark a qualifying absence as "main home" and apply the
cap by hand. Defaults of 0 are byte-identical to before.

**Open, needs a figure this repository does not hold.** Age-conditioning of the care onset rate, the sex
split of care *duration*, and the LA-versus-self-funder fee gap each need a sourced modelling number: an
age gradient for onset, a female:male duration ratio, and the local-authority rate as a share of the
self-funder fee (the CMA care-homes market study and LaingBuisson are the likely sources, neither of
which the repo cites for this). This session had no web access, so I could not research them, and a
figure with no source and no verified-on date is indistinguishable from an invented one. Each moves a
headline result, so I did not guess. Next run: research those three, then the code changes are small
(`CareAssumptions` + `CareCostSampler` for the first two, `CareAssumptions` + `CareMeansTest` for the
third), and each wants an editable control with the sourced alternatives.

**Open, and I recommend it is built with card 0036 rather than before it.** The annuitisation
retirement-month override. The repository never specs this item beyond the phrase itself (DECISIONS
2026-07-18 and the session-log archive mention it only as a thing not chosen), so I read it as: an
annuity bought at `atAge` pays a FULL year of income in its purchase year, though the engine's own
retirement convention puts the event on the annuitant's birthday (`PathProjector::workFraction`, birth
month over 12), and there is no input to say which month. The catch is that `processAnnuityPurchases`
runs at the top of the year and `growState` at the end, so the purchase money already forgoes a whole
year of growth: the two errors currently offset. Prorating only the income would make the model doubly
adverse rather than more accurate. A faithful fix needs one convention for a mid-year event on an annual
grid (grow the money for m/12 before it leaves, pay income for the rest), which is the same call card
0036 must make about the retirement year paying part-year salary against a full year of pension. That is
a modelling decision, not a mechanical change, and doing it twice would leave two conventions.

**Acceptance #1 left unticked, deliberately.** It holds for both refinements built, but four tasks
remain, and ticking it would read as a finished card. Two notes for whoever finishes it. First, neither
built item had an entry in DATA-MODEL "Known divergences" to remove; both were flagged in code and in
docs/spec/METHODOLOGY.md "What we don't model", which is where I closed them. Second, that section's own
convention is to KEEP a closed divergence and mark it `CLOSED <date>` with what changed and what remains
open (every entry in it does this), which is the opposite of the criterion's "remove". I followed the
section, not the wording, and added two CLOSED entries. If the criterion means the flag must stop being
stated as a current limit, that is done.

**Not verified in a browser.** Built in a worktree, so Herd serves the main checkout, not this tree. The
CGT wizard's three new period kinds, the "+ Away period" button and the "Away, still counted" readout
still need a look on screen. `.\vendor\bin\pest.bat` does not exist in this project; the suite is
PHPUnit, run with `php artisan test` (1124 pass, 1 expected advice-mode skip). `vendor\bin\pint.bat`
clean, and `php artisan scenarios:audit` clean over all 25 stored scenarios.

**2026-08-22 (second unattended run)** This session had no web access either — `WebSearch` and `WebFetch`
are both refused, and there is no project permission file to widen — so the three refinements that need a
sourced modelling figure are still not built, and nothing was guessed. **This card cannot be advanced by
an unattended session.** Handing it to another one repeats this run. It needs either a session with web
access or the three figures from Rob; until then only the annuitisation item is left, and that one is
sequenced behind card 0036 for the reason logged in DECISIONS 2026-08-22.

**Built: the methodology page no longer contradicts the code.** Checking that acceptance #1 actually held
for the two refinements built earlier today found one place where it did not. `docs/spec/METHODOLOGY.md`
states the care limits **twice** — once in a "Simplifications:" paragraph under "Care costs", and once in
the "Care" bullet under "What we don't model". Yesterday's build updated the second and left the first, so
the public `/methodology` page (and the assistant's own corpus, which is the same file) still told a reader
"any Pension Credit award is not counted into the care contribution" — false since that build, and about a
figure that moves a means-tested result. Rather than restate the list correctly in both places and leave it
to rot again, the duplicate is gone: the "Care costs" section now keeps only what is specific to the
*care-stress* (fixed adverse parameters, one spell not both) and points at "What we don't model" for the
rest, which is the one home for that fact. The DECISIONS entries that carry the old flags are left alone —
that log is append-only and the 2026-08-22 entry already records what it supersedes. No code changed, so no
figure moves.

**What the next run needs, so it does not have to re-derive it.** Each of the three blocked items needs one
number, and the code change after it is small:
- *Age-conditioning of the care onset rate* — an age gradient for entering residential/nursing care. It
  would multiply the sex-differentiated Bernoulli in `CareCostSampler::sampleHousehold()`, which currently
  draws against a flat lifetime probability whatever age the path kills the person at. Must stay calibrated
  to the Dilnot/PSSRU ~1-in-4 population mean that `CareAssumptions` is already anchored to, or the headline
  care-risk share moves for the wrong reason.
- *Sex split of care duration* — a female:male ratio of length of stay, to split `CareAssumptions::$meanDurationYears`
  the way `$probabilityOfCareMale` / `$probabilityOfCareFemale` already split the probability. The class
  docblock's existing source (PSSRU/LSE dp2769) is the likely home for it, but the repo's summary of that
  paper does not carry the split.
- *LA-versus-self-funder fee gap* — the local-authority rate as a share of the self-funder fee, so a funded
  resident's third-party top-up is charged instead of assuming the council buys the same place at the same
  price. It enters `CareMeansTest::annualCharge()`, whose docblock carries the flag.
A user-input route was considered for the third (the precedent is the annuity rate, which is a user input
because a real quote belongs to the household — PLAN.md). It was not taken: with no default it changes no
shipped result, and it would put a control Rob cannot fill in without the same research onto a page that has
not had its browser sign-off yet (card 0001).

**Acceptance #1 still left unticked**, for the same reason as this morning: it holds for both refinements
built, but four tasks remain and ticking it would read as a finished card.

**Not verified in a browser** (worktree, so Herd serves the main checkout). This run changed one prose
paragraph in a doc, so there is nothing new to look at beyond the `/methodology` page rendering. The
assistant's doc index (`php artisan assistant:index-docs`) should be re-run after this METHODOLOGY edit if
the assistant is enabled; it is inert by default and could not be rebuilt here.

**2026-08-22** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with 1 of 1 acceptance criteria still open, so what this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.
