<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which housing decision a scenario models. The same household is run under each
 * variant on identical seeds, so they are directly comparable. This is an app-level
 * concept: the engine itself takes a Household plus a HousingAction and does not
 * name the variants.
 */
enum ScenarioVariant: string
{
    case BuyOutright = 'buy_outright';
    case Rent = 'rent';
    case StayPut = 'stay_put';
}
