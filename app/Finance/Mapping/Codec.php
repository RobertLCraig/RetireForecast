<?php

declare(strict_types=1);

namespace App\Finance\Mapping;

use DateTimeImmutable;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Lossless primitive (de)serialisation between the engine's value objects and the
 * plain scalars that go into an encrypted JSON payload.
 *
 * The rules that make the round-trip exact: Money is stored as integer pence (GBP
 * is the only currency the app uses), Percent as integer basis points, dates as
 * ISO Y-m-d (time zeroed so the rehydrated DateTimeImmutable compares equal), and
 * floats are cast back to float on the way out so a JSON 1.0 that decodes as int 1
 * does not drift the shape. There is no float anywhere in money arithmetic.
 */
final class Codec
{
    public static function pence(Money $money): int
    {
        return $money->pence;
    }

    public static function penceOrNull(?Money $money): ?int
    {
        return $money?->pence;
    }

    public static function money(int $pence): Money
    {
        return Money::fromPence($pence);
    }

    public static function moneyOrNull(?int $pence): ?Money
    {
        return $pence === null ? null : Money::fromPence($pence);
    }

    public static function bps(Percent $percent): int
    {
        return $percent->basisPoints;
    }

    public static function bpsOrNull(?Percent $percent): ?int
    {
        return $percent === null ? null : $percent->basisPoints;
    }

    public static function percent(int $basisPoints): Percent
    {
        return Percent::fromBasisPoints($basisPoints);
    }

    public static function percentOrNull(?int $basisPoints): ?Percent
    {
        return $basisPoints === null ? null : Percent::fromBasisPoints($basisPoints);
    }

    public static function dateString(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    /** Parse an ISO date with the time component zeroed, so equality is by calendar day. */
    public static function date(string $iso): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        if ($date === false) {
            throw new \InvalidArgumentException("Not an ISO Y-m-d date: {$iso}");
        }

        return $date;
    }
}
