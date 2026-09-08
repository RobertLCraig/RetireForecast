<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * Where one figure came from and when that was last checked. The machine-checkable half of the
 * house rule that every figure reaching a projection carries a source URL and a verified-on date:
 * prose in a document cannot be swept, and a figure nobody can date is a figure nobody re-verifies.
 *
 * Board card 0065. The statutory figures had this discipline from the start
 * ({@see TaxYearConfig::$verifiedOn}); the ECONOMIC assumptions, which move the answer far more,
 * sat behind a single prose note per set with no date the freshness command could read.
 *
 * $figure is the property name on the DTO the figure lives on, so a sourcing list can be checked
 * against that DTO by reflection and a figure added without a source is loud rather than silent.
 * $label is what a person reading a report calls it.
 */
final class FigureSource
{
    public function __construct(
        public readonly string $figure,
        public readonly string $label,
        /** A citation carrying a URL, not a description. */
        public readonly string $source,
        /** ISO Y-m-d: the date the figure was last checked against that source. */
        public readonly string $verifiedOn,
    ) {}
}
