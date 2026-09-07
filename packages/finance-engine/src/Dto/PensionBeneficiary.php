<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * Who a pension is NOMINATED to — the member's expression of wish.
 *
 * Pension death benefits do not pass under the will. They are paid at the scheme's discretion,
 * following the form the member last completed, which is why a couple can be married, have wills
 * leaving everything to each other, and still have a pot go to a child. From April 2027 that form
 * is an Inheritance Tax document: whether the pot is spouse-exempt on the first death turns on it
 * and not on marital status.
 *
 * There are only two answers here because only two matter to the tax: the surviving spouse or
 * civil partner (exempt), or anybody else (chargeable). A charity is exempt too and is not
 * modelled; a household leaving a pot to one should not use this tool for that part of the plan.
 */
enum PensionBeneficiary: string
{
    case SpouseOrCivilPartner = 'spouse_or_civil_partner';

    case SomeoneElse = 'someone_else';
}
