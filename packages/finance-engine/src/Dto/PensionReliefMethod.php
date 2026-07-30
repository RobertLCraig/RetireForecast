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
}
