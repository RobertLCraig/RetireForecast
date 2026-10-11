# Is a park home still an option? A £1,500 grant top-up ends March 2027

## What I need from you
**Send this letter:** `C:\Dev\RetireForecast\docs\outreach\park-home-heat-pump-letter.local.md`. Fill in your name, address and the date, then post or email it to the park it names.

**Pass:**
- the park answers all three questions in writing: (a) may a heat pump and solar panels go on the pitch; (b) does the home have its own electricity meter number (MPAN), or does the site owner resell power; (c) will the roof take panels
- you paste the answers here: `**<date>** Park replied: (a) ___ (b) ___ (c) ___.`

**Fail:** no reply in four weeks, or a reply that skips a question. Say so here; an agent then drafts a chaser or picks the next park.

**Why it needs you:** the letter goes out in your name to a business you may buy from. The extra £1,500 grant for homes on oil or LPG ends in March 2027, so send it by January 2027.

The letter is gitignored because it names the park, which locates you.

## What you need to know
- The Boiler Upgrade Scheme (a government grant for heat pumps) pays £7,500. It runs to 2030.
- A home heated by oil or LPG with no mains gas gets an extra £1,500, but only until March 2027.
- Park homes qualify. Since 28 April 2026 no energy certificate (EPC) is needed.
- Question (b) matters for money: if the site owner resells electricity, solar panels probably cannot earn export income.
- The grant is not in any forecast yet.

## See it
- Grant amounts: <https://www.gov.uk/apply-boiler-upgrade-scheme/what-you-can-get>
- Who qualifies: <https://www.gov.uk/apply-boiler-upgrade-scheme/check-if-youre-eligible>
- Park-home plan: `docs/build/PLAN-park-home.md`, section "Heat pumps and solar"

---
## For the agent (Rob can stop reading here)
Option 1: model the grant on the park-home scenarios using the three answers. Option 2: `git mv` to `discarded/`.

## Comments

**2026-09-30** Checked the scheme's own pages, so the deadline and the amounts above are sourced
rather than remembered. All read 2026-09-30.
- **Grant:** £7,500 towards an air- or ground-source heat pump, plus "until March 2027, you can get
  an additional £1,500" where the property is heated by oil or LPG and has no mains gas. That is
  the £9,000. <https://www.gov.uk/apply-boiler-upgrade-scheme/what-you-can-get>. The top-up
  started in July 2026 (<https://gasengineersoftware.co.uk/blog/boiler-upgrade-scheme-2026-changes/>).
- **What expires is the £1,500, not the £9,000.** The scheme now runs to 2030 (Ofgem, summary of
  updates to BUS installer guidance V5, 24 April 2026: "Scheme extended to 2030").
- **Park homes qualify and the EPC hurdle is gone.** The same Ofgem summary lists "second homes,
  holiday homes, park homes" among eligible property types (paras 3.08 to 3.11) and says that from
  28 April 2026 "properties are no longer required to have a valid EPC" (para 3.16).
  <https://www.ofgem.gov.uk/sites/default/files/2026-04/summary_of_updates_to_bus_guidance_for_installers_V5_20260424143100.pdf>
- Owner must own the property and be replacing a fossil-fuel system; an MCS-certified installer must
  fit it within 120 days of the voucher. <https://www.gov.uk/apply-boiler-upgrade-scheme/check-if-youre-eligible>

**2026-10-11** **Decided:** **2026-10-11** **Decided:** Option 1: write to one named park with the 3 questions.

**2026-10-11** Drafted the letter at `docs/outreach/park-home-heat-pump-letter.local.md` (new folder, gitignored by a new `.gitignore` line because the park's name locates the household). The park is the cheaper site the stored park-home scenarios model; the letter's header says which one and why. The site owner's company could not be found online, so the letter goes to the park manager at the country park's postal address and suggests asking the agent or the council's site licence register for the owner's name. Ask rewritten to "send this letter"; the card stays in `human-review/` until the answers are pasted. Clear-down session, Rob attended.
