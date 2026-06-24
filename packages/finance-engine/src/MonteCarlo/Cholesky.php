<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use InvalidArgumentException;

/**
 * Cholesky decomposition of a symmetric positive-definite matrix (here, an asset
 * correlation matrix). Returns the lower-triangular L such that L·Lᵀ = A, which
 * turns independent standard-normal draws u into correlated draws z = L·u.
 */
final class Cholesky
{
    /**
     * @param  list<list<float>>  $matrix  square, symmetric, positive-definite
     * @return list<list<float>> lower-triangular factor
     */
    public static function decompose(array $matrix): array
    {
        $n = count($matrix);
        $l = array_fill(0, $n, array_fill(0, $n, 0.0));

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j <= $i; $j++) {
                $sum = 0.0;
                for ($k = 0; $k < $j; $k++) {
                    $sum += $l[$i][$k] * $l[$j][$k];
                }

                if ($i === $j) {
                    $diag = $matrix[$i][$i] - $sum;
                    if ($diag <= 0.0) {
                        throw new InvalidArgumentException('Matrix is not positive-definite; cannot decompose.');
                    }
                    $l[$i][$j] = sqrt($diag);
                } else {
                    $l[$i][$j] = ($matrix[$i][$j] - $sum) / $l[$j][$j];
                }
            }
        }

        return $l;
    }

    /**
     * Apply the lower-triangular factor to a vector of independent draws, returning
     * the correlated vector z = L·u.
     *
     * @param  list<list<float>>  $l
     * @param  list<float>  $u
     * @return list<float>
     */
    public static function apply(array $l, array $u): array
    {
        $n = count($l);
        $z = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            $sum = 0.0;
            for ($k = 0; $k <= $i; $k++) {
                $sum += $l[$i][$k] * $u[$k];
            }
            $z[$i] = $sum;
        }

        return $z;
    }
}
