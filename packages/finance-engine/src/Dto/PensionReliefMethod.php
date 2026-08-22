<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * How a DC scheme gives income-tax relief on the member's own contributions. The methods
 * differ in WHAT they relieve and WHEN, so the engine models the method rather than assuming
 * one: getting it wrong misstates both the pot and the tax bill.
 *
 * - {@see NetPay} — the contribution is taken from GROSS pay before the employer runs PAYE, so
 *   relief is given at the member's full marginal rate immediately. National Insurance is
 *   charged on the pre-contribution pay, so there is NO NI saving (that is salary sacrifice,
 *   which is a different arrangement and is not modelled).
 * - {@see ReliefAtSource} — the contribution is paid out of NET pay and the provider reclaims
 *   basic-rate relief into the pot; a higher- or additional-rate taxpayer recovers the rest
 *   through self assessment, which lands in a LATER year (a real cashflow-timing effect).
 *   NOT YET MODELLED — see docs/build/PLAN-adviser-parity.md A2.
 * - {@see NonEarner}: relief at source restricted to the statutory "basic amount": the route a
 *   member with no relevant UK earnings uses. They pay £2,880 net out of the household's money
 *   and the provider adds 20%, so £3,600 gross reaches the pot
 *   ({@see PensionParameters::$nonEarnerReliefLimit}). Every member under 75 has this floor
 *   whatever they earn, which is why it can be modelled without knowing their pay, but it is
 *   capped AT the basic amount, so an earner wanting relief on more than that needs net pay (or
 *   relief at source, which is unbuilt). Relief stops at 75
 *   ({@see PensionParameters::$reliefMaximumAge}), so the contribution stops with it.
 *
 * A null method on {@see DcPension} means relief is not modelled at all (the pre-2026-07-31
 * behaviour, kept so a stored scenario does not silently shift). That is never true of a real
 * UK pension, so the results page raises it as an input-sanity note rather than leaving the
 * reader to assume relief was included.
 */
enum PensionReliefMethod: string
{
    case NetPay = 'net_pay';
    case ReliefAtSource = 'relief_at_source';
    case NonEarner = 'non_earner';
}
