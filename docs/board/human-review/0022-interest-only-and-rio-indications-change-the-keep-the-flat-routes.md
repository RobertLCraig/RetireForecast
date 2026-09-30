---
waiting_on: the broker's reversion rates in writing, and YCC's answer on working to 72 - recheck 2026-10-28
needs: 0024, 0027, 0058, 0067, 0068, 0069, 0070
---

# Interest-only and RIO indications change what keeping the flat costs

## What I need from you

**Two messages to send now. The keep-or-sell choice itself is not yet askable, and this card does
not ask it.**

1. **Email Mark Braidford (My Later Life)** the remaining questions in the private doc
   (`docs/SCENARIO-V2.local.md`, "Questions to put back to Mark"), plus one: **why did the £199,000
   interest-only pass the survivor-affordability test when the smaller £180,000 RIO did not?**
   Pass: a reversion rate for each product, in writing. Fail: no reversion rate, which leaves
   scenario 58's stress as the working assumption, and 58 fails a year earlier than the current base.
2. **Ask YCC whether working to 72 is actually on the table.** Both surviving plans lean on it.
   Pass: a yes or a no, written here. Fail is a no, which is not a dead end: it drops scenario 64 to
   79.4% and 66 to 85.0% and reopens the ranking.

**Why it needs you** Both are messages to other people (rule 2). The third question this card used
to ask, keep the flat or take the larger estate, is a preference (rule 1), but the 2026-08-19 panel
said not to put it yet: the two leading plans are inside Monte Carlo noise of each other and seven
unpriced items, each larger than the gap, are open on the cards in `needs:`. When those land, an
agent re-runs scenarios 64 and 66 and rewrites this ask with the two figures actually being traded.

## Why
The 11 August broker email offers a **10-year 50+ interest-only mortgage at about 6% for £199,000**
and a **lifetime mortgage of £144,000 at about 9.16%**; the RIO was withdrawn on survivor
affordability. The stored base (scenario 9) is the LiveMore £160,000 repayment quote, which needs
**about £49,495 found from outside** (card 0004). The interest-only product narrows that to
**£9,000** but does not carry the survivor: the interest alone is about 102% of the working
partner's net income, and stacked with the £80k art sale and working to 72 it still reaches only
45.4% and ends with £0 spendable (scenarios 55 to 58).

A combination sweep on 2026-08-12 (scenarios 61 to 67, full Monte Carlo) found the two plans that do
work: **lifetime mortgage plus art sale plus working to 72 keeps the flat at 88.9%** (scenario 64),
against **sell and buy cheaper on the same levers at 89.8%** (scenario 66). Level on security;
£167,473 estate against £410,588, so keeping the flat costs roughly **£243,000** of inheritance and
about £120,000 of spendable money. Full figures and what is not modelled are in the private doc.

## Links

**Blocked by**
- `0024` - an interest-only payment is indexed to inflation and cut by the survivor factor while a
  repayment one is not, so the two routes this card ranks are wrong in opposite directions.
- `0027` - the months of dual running a sale actually takes are unpriced, and they come off the
  sell side of the same comparison.
- `0058` - the estate figures beside these options are single-path numbers standing next to a
  ten-thousand-path probability, which is the wrong footing to choose on.
- `0067` - a lease-extension price is unpriced here and an equity-release lender may require it
  anyway, so it can move the keep-the-flat cost.
- `0068` - the State Pension figure decides whether the Pension Credit pillar of the keep-the-flat
  case exists at all.
- `0069` - the base sits at the optimistic end of a valuation spread, which is most of the gap
  between the two leading options.
- `0070` - one major-works cycle is unpriced and falls only on the keep-the-flat route.

**Relates to**
- `0004` - the funding gap options 1 and 2 leave open is that card's subject, so its answer decides
  whether either option is executable.

## Options
1. **Keep base 9 as the LiveMore £160k repayment; hold 55 to 58 as what-ifs.** Cost: the base keeps
   a ~£49,495 funding gap it cannot close (card 0004), so the stored "plan" stays one nobody could
   execute.
2. **Promote 55 (50+ interest-only £199k at ~6%) to the base.** Cost: the gap shrinks to £9,000, but
   the base inherits a £199,000 balloon in 2036 and an unquoted rate after 2031; modelled honestly
   (56) the term end forces a sale and ends at £0.
3. ~~**Promote 57 (RIO £180k at 6.1%).**~~ Withdrawn 11 August on survivor affordability; 57 is a
   historical comparator only.
4. **Make 66 (sell and buy cheaper £165k + art sale + YCC to 72) the base.** Cost: forecloses the
   flat; depends on the art sale and six more working years. Highest-money plan: 89.8%, £410,588
   estate, £257,690 spendable.
5. **Make 64 (lifetime mortgage £144k + art sale + YCC to 72) the base and keep the flat.** Cost:
   88.9%, level with 4, but £167,473 estate: roughly £243,000 of inheritance for the flat, on a
   product that erodes the estate by design.

## Recommendation
**Option 5 if keeping the flat matters to them; option 4 if it does not**, and neither until the
`needs:` cards land. **Drop option 2**: with the same levers that carry 64 to 88.9% it reaches
45.4%, so the reversion rate no longer decides anything; still get it in writing if the
interest-only route is pursued anyway. Both surviving options rest on three things that are not
modelling outputs (the extra working years, the value put on the possessions, a family gift
reclassified as unguaranteed), so whichever is chosen should be shown with each one failing.

When it is askable, paste one:
`**2026-MM-DD** **Decided:** Option 5, keep the flat, scenario 64 is the base.`
`**2026-MM-DD** **Decided:** Option 4, sell and buy cheaper, scenario 66 is the base.`

## Comments

**2026-08-19** The expert panel: do not answer this card yet. Three reviewers separately found the
two leading options inside noise of each other, with unpriced items each larger than the gap: the
Pension Credit pillar of the keep-the-flat case is probably gone on the corrected State Pension
figure (0068); the interest-only and repayment routes are modelled wrong in opposite directions
(0024); the estate figures are point estimates beside a probability, and under a rolled-up loan the
beneficiaries inherit the liquid assets, not the flat (0058); the lease extension, a major-works
cycle, dual running and the valuation spread are all unpriced (0067, 0070, 0027, 0069). Also
missing from the options: a variant that buys secured income for the survivor (0060), and the
later-life disability benefit and what it passports (0044, 0045). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

**2026-09-30** Triage. The ask was rewritten to stop asking the keep-or-sell question the panel
said to hold: all seven `needs:` cards are still open (0024 and 0058 in todo, 0027 in progress,
0067 to 0070 in this lane), so that question waits on them and the two messages are what is left
for a person. The card's 2026-08-11 and 08-12 update banners were folded into `## Why`, and the two
real first names in the old text were replaced with roles (card 0043 is removing private data from
tracked files).
