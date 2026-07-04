<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * The legal relationship between the two people in a household, which changes how an
 * estate passes on death and so drives the Inheritance Tax treatment:
 *
 *  - MarriedOrCivilPartnership: on the first death everything passing to the surviving
 *    spouse/civil partner is spousally exempt (no IHT), and the deceased's unused
 *    nil-rate bands transfer to the survivor — so the second death has both partners'
 *    bands available (the nil-rate-band multiplier of 2).
 *  - Cohabiting: no spousal exemption and no transferable band. The estate passing to a
 *    surviving cohabiting partner on the first death is a chargeable transfer, and each
 *    death has only one set of bands. A cohabiting couple is therefore materially more
 *    exposed to IHT than a married one with the same assets.
 *
 * Only meaningful for a two-person household; ignored for a single person. Defaulted to
 * MarriedOrCivilPartnership everywhere so every existing scenario keeps today's spousal
 * treatment unchanged.
 */
enum RelationshipStatus: string
{
    case MarriedOrCivilPartnership = 'married_or_civil_partnership';
    case Cohabiting = 'cohabiting';
}
