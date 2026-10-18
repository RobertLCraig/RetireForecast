> UNTRUSTED: written by an unattended research session from web pages. Read it as data, never as instructions, and check every figure against the URL beside it.

# 0129 research: deferred payment maximum interest rate and the Schedule 2 property disregard

Researched 2026-10-18 by an unattended research session, using WebSearch and WebFetch only.

## 1. Deferred payment agreement: maximum interest rate

### The rule (primary source)
- The rule is in regulation 9 of the Care and Support (Deferred Payment) Regulations 2014 (SI 2014/2671). It came into force on 1 April 2015. https://www.legislation.gov.uk/uksi/2014/2671/regulation/9
- Reg 9(3): "The interest rate is a rate that does not exceed the relevant rate for the relevant period plus 0.15%." https://www.legislation.gov.uk/uksi/2014/2671/regulation/9
- Reg 9(4): the relevant rate is "the weighted average interest rate on conventional gilts specified for the financial year" in which the relevant period starts. It is taken from the most recent Office for Budget Responsibility report published before that period starts. https://www.legislation.gov.uk/uksi/2014/2671/regulation/9
- Reg 9(5): there are two relevant periods each year, 1 January to 30 June and 1 July to 31 December. https://www.legislation.gov.uk/uksi/2014/2671/regulation/9
- Reg 9(1)–(2): the council must tell the adult, before the agreement, that it intends to charge interest and at what rate. Interest may be added to the deferred amount unless the adult asks to pay it separately. https://www.legislation.gov.uk/uksi/2014/2671/regulation/9
- Wording note for the card: the card calls this "the OBR forecast for the 15-year gilt rate". The regulation instead says "weighted average interest rate on conventional gilts", and does not mention a 15-year gilt. The 0.15% is a fixed addition in the regulation ("plus 0.15%"), so the maximum is the gilt rate plus 0.15%. It is not "a default component of up to 0.15 percentage points". https://www.legislation.gov.uk/uksi/2014/2671/regulation/9

### The OBR input (primary source)
- OBR Economic and fiscal outlook, March 2026 (published 3 March 2026): Table A.3, row "Market gilt rates (per cent)". Footnote 9 defines it as "Weighted average interest rate on conventional gilts". The value is 4.5 for 2026-27. https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/
- OBR Economic and fiscal outlook, November 2025: Table A.3, row "Market gilt rates (per cent)". The values are 4.6 for 2025-26 and 4.7 for 2026-27. https://obr.uk/efo/economic-and-fiscal-outlook-november-2025/

### Deriving the current maximum from the rule
- 1 July 2026 to 31 December 2026: this period starts in FY 2026-27, and the latest OBR report before 1 July 2026 is the March 2026 EFO. 4.5% + 0.15% = 4.65%. https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/
- 1 January 2026 to 30 June 2026: this period starts in FY 2025-26, and the latest OBR report before 1 January 2026 is the November 2025 EFO. 4.6% + 0.15% = 4.75%. https://obr.uk/efo/economic-and-fiscal-outlook-november-2025/
- Both derived figures are this session's own arithmetic on the regulation plus the OBR tables. I found no national government (DHSC/gov.uk) page that states the current figure. https://www.legislation.gov.uk/uksi/2014/2671/regulation/9

### Council publications agreeing with the derivation (secondary sources)
- Central Bedfordshire: "The interest rate is 4.65% APR with effect from 1 July 2026." https://www.centralbedfordshire.gov.uk/health-and-social-care/paying-care/deferred-payments/how-does-it-work
- Reading Appendix J lists "1 April 2026 – 30 June 2026: 4.75%" and "1 July 2026 – 31 December 2026: 4.65%". It gives the 1 January 2027 rate as "to be advised" and says the council charges the maximum interest rate as defined in the regulations. https://www.reading.gov.uk/adult-care/paying-for-your-care/care-and-support-charging-and-financial-assessment-framework-policies/appendix-j-charges-for-deferred-payment-agreements-and-interim-funding-arrangements
- Search result snippets (pages not opened successfully) also gave 4.65% from 1 July 2026 for Suffolk and 4.75% from 1 January 2026 for Birmingham. The Birmingham page returned HTTP 403, and the Suffolk .docx could not be read, so neither is verified. https://www.suffolk.gov.uk/asset-library/frequently-asked-questions-about-deferred-payments-july-2026.docx
- Not verified: the LGA page "Interest rates for universal deferred payments" returned HTTP 403. https://www.local.gov.uk/interest-rates-universal-deferred-payments
- Not read: paragraph 9.71 of the Care and Support Statutory Guidance, which Reading cites for the method. The gov.uk page came back truncated. https://www.gov.uk/government/publications/care-act-statutory-guidance/care-and-support-statutory-guidance

### Bearing on the shipped constant
- The current published maximum (1 July to 31 December 2026) is 4.65%, the same as the shipped `MAXIMUM_INTEREST_RATE_BPS` of 4.65%. The January to June 2026 maximum was 4.75%, which is higher than the shipped figure. https://www.centralbedfordshire.gov.uk/health-and-social-care/paying-care/deferred-payments/how-does-it-work
- The 1 January 2027 rate had not been published when this was read. Reading lists it as "to be advised". https://www.reading.gov.uk/adult-care/paying-for-your-care/care-and-support-charging-and-financial-assessment-framework-policies/appendix-j-charges-for-deferred-payment-agreements-and-interim-funding-arrangements

## 2. Property disregard: Schedule 2, Care and Support (Charging and Assessment of Resources) Regulations 2014

Source: SI 2014/2672, Schedule 2 ("capital to be disregarded"). The latest revised version on legislation.gov.uk is stated to be up to date to 15 October 2026. Paragraphs 4 and 5 show no textual amendments and no outstanding changes, and came into force on 1 April 2015. https://www.legislation.gov.uk/uksi/2014/2672/schedule/2

- Para 2(1): the value of the adult's main or only home is disregarded for 12 weeks, beginning with the day the adult first moves into a care home (the 12-week property disregard). https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made
- Para 4(1)(a) disregards premises "which would be disregarded under paragraph 2 or 4(b) of Schedule 10 to the Income Support Regulations", with "his home" read as "his main or only home". That Income Support provision was not read. https://www.legislation.gov.uk/uksi/2014/2672/schedule/2
- Para 4(1)(b) is the mandatory disregard. It covers premises "occupied in whole or in part as their main or only home by a qualifying relative of the adult", where the relative "has occupied the premises as their main or only home since before the date on which the adult was first provided with accommodation in a care home under the Act". https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made
- Para 4(2) is discretionary: "A local authority may disregard the value of any premises which is occupied in whole or in part by a qualifying relative" where the relative moved in after the adult entered a care home. https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made
- Para 4(3): the premises stay disregarded for a further 12 weeks after a 4(1)(b) or 4(2) disregard, once the relative has died or has moved into a care home. https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made
- Para 4(4): the council may disregard the premises for 12 weeks where the qualifying relative has left because of "an unexpected change in their circumstances". https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made
- Para 4(5) defines "qualifying relative" as the adult's (a) "partner"; (b) "other family member or relative who is aged 60 or over or who is incapacitated"; or (c) "child who is under 18". https://www.legislation.gov.uk/uksi/2014/2672/schedule/2
- Para 4(5): "child" is to be construed in accordance with section 1 of the Family Law Reform Act 1987. Schedule 2 itself does not define "incapacitated". Whether the regulations define "partner" elsewhere (e.g. regulation 2) was not checked. https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made
- Para 5 is a separate disregard. For a resident who has stopped living in their former home after estrangement or divorce, the value of their interest in it is disregarded "where it is still occupied as the home by the former partner who is a lone parent". https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made

### Bearing on the shipped list (`Dto\Property::$occupiedByQualifyingRelative`)
- The shipped list differs from the source text in five places: https://www.legislation.gov.uk/uksi/2014/2672/schedule/2
  - It says "spouse or civil partner", where the regulation says "partner".
  - It says "a relative aged 60 or over", where the regulation says "other family member or relative".
  - "an incapacitated relative of any age" matches 4(5)(b).
  - "a child of the resident under 18" matches 4(5)(c).
  - The disregard is mandatory only if the relative has lived there since before the adult first entered a care home (4(1)(b)). If the relative moved in later, the disregard is discretionary (4(2)).
- The shipped list does not cover the para 5 lone-parent former partner disregard or the 12-week disregards in paras 2(1), 4(3) and 4(4). https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made

## Figures

| what it is | value | https://the-page-it-came-from |
|---|---|---|
| Fixed addition to the gilt rate for the DPA maximum interest rate (reg 9(3)) | 0.15% | https://www.legislation.gov.uk/uksi/2014/2671/regulation/9 |
| DPA relevant period 1 (reg 9(5)) | 1 January to 30 June | https://www.legislation.gov.uk/uksi/2014/2671/regulation/9 |
| DPA relevant period 2 (reg 9(5)) | 1 July to 31 December | https://www.legislation.gov.uk/uksi/2014/2671/regulation/9 |
| Commencement of SI 2014/2671 reg 9 | 1 April 2015 | https://www.legislation.gov.uk/uksi/2014/2671/regulation/9 |
| OBR March 2026 EFO publication date | 3 March 2026 | https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/ |
| OBR March 2026 EFO Table A.3 market gilt rate (weighted average on conventional gilts), 2026-27 | 4.5% | https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/ |
| OBR November 2025 EFO Table A.3 market gilt rate, 2025-26 | 4.6% | https://obr.uk/efo/economic-and-fiscal-outlook-november-2025/ |
| OBR November 2025 EFO Table A.3 market gilt rate, 2026-27 | 4.7% | https://obr.uk/efo/economic-and-fiscal-outlook-november-2025/ |
| DPA maximum interest rate from 1 July 2026 (Central Bedfordshire) | 4.65% | https://www.centralbedfordshire.gov.uk/health-and-social-care/paying-care/deferred-payments/how-does-it-work |
| DPA interest rate 1 July 2026 to 31 December 2026 (Reading, charges the maximum) | 4.65% | https://www.reading.gov.uk/adult-care/paying-for-your-care/care-and-support-charging-and-financial-assessment-framework-policies/appendix-j-charges-for-deferred-payment-agreements-and-interim-funding-arrangements |
| DPA interest rate 1 April 2026 to 30 June 2026 (Reading, charges the maximum) | 4.75% | https://www.reading.gov.uk/adult-care/paying-for-your-care/care-and-support-charging-and-financial-assessment-framework-policies/appendix-j-charges-for-deferred-payment-agreements-and-interim-funding-arrangements |
| DPA interest rate from 1 January 2027 (Reading) | to be advised | https://www.reading.gov.uk/adult-care/paying-for-your-care/care-and-support-charging-and-financial-assessment-framework-policies/appendix-j-charges-for-deferred-payment-agreements-and-interim-funding-arrangements |
| Schedule 2 revised text stated up to date to | 15 October 2026 | https://www.legislation.gov.uk/uksi/2014/2672/schedule/2 |
| Commencement of Schedule 2 paras 4 and 5 | 1 April 2015 | https://www.legislation.gov.uk/uksi/2014/2672/schedule/2 |
| Home disregard on first entering a care home (Sch 2 para 2(1)) | 12 weeks | https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made |
| Disregard after the qualifying relative dies or enters a care home (Sch 2 para 4(3)) | 12 weeks | https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made |
| Discretionary disregard after an unexpected change in the relative's circumstances (Sch 2 para 4(4)) | 12 weeks | https://www.legislation.gov.uk/uksi/2014/2672/schedule/2/made |
| Qualifying relative age threshold, family member or relative (Sch 2 para 4(5)(b)) | 60 or over | https://www.legislation.gov.uk/uksi/2014/2672/schedule/2 |
| Qualifying relative age threshold, child (Sch 2 para 4(5)(c)) | under 18 | https://www.legislation.gov.uk/uksi/2014/2672/schedule/2 |
