<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Money;

use InvalidArgumentException;

/**
 * Exact integer arithmetic helpers.
 *
 * Currency maths runs entirely in integer pence, so we never touch PHP floats
 * for money. Division (applying a rate to a pence amount) is the one place a
 * remainder appears, and it is resolved here with an explicit rounding mode.
 */
final class IntMath
{
    /**
     * Divide $numerator by $denominator and round the result to an integer.
     *
     * Correct for positive and negative numerators. $denominator must be non-zero.
     */
    public static function divRound(int $numerator, int $denominator, RoundingMode $mode = RoundingMode::HalfUp): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        // Normalise so the denominator is positive; the sign lives on the numerator.
        if ($denominator < 0) {
            $numerator = -$numerator;
            $denominator = -$denominator;
        }

        $quotient = intdiv($numerator, $denominator);   // truncates towards zero
        $remainder = $numerator - $quotient * $denominator; // sign follows $numerator, |r| < denominator

        if ($remainder === 0) {
            return $quotient;
        }

        $twiceRemainder = abs($remainder) * 2;
        $awayFromZero = $numerator < 0 ? $quotient - 1 : $quotient + 1;

        return match ($mode) {
            RoundingMode::Floor => $numerator < 0 ? $quotient - 1 : $quotient,
            RoundingMode::Ceil => $numerator > 0 ? $quotient + 1 : $quotient,
            RoundingMode::HalfUp => $twiceRemainder >= $denominator ? $awayFromZero : $quotient,
            RoundingMode::HalfEven => match (true) {
                $twiceRemainder > $denominator => $awayFromZero,
                $twiceRemainder < $denominator => $quotient,
                default => $quotient % 2 === 0 ? $quotient : $awayFromZero,
            },
        };
    }
}
