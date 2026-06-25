<?php

declare(strict_types=1);

namespace App\Import;

/**
 * A reader for one recognised budget-spreadsheet layout. Each profile knows how to turn
 * its own format's contents into a partial {@see ImportResult}; an unrecognised file is
 * refused with a reason ({@see ImportException}) rather than imported as guesswork.
 *
 * Third-party templates (IWT, Nischa) ship as profiles too, but stay {@see isAvailable()}
 * false until a real sample export is on hand to map their exact cells — so the registry
 * can list them as "coming soon" without pretending to parse a layout it has not seen.
 */
interface ImportProfile
{
    /** Stable key used in the form select and the registry lookup. */
    public function key(): string;

    /** Human name shown in the spreadsheet-type chooser. */
    public function label(): string;

    /** One line of guidance about which spreadsheet this reads. */
    public function description(): string;

    /** False for a profile registered but not yet calibrated against a real sample. */
    public function isAvailable(): bool;

    /**
     * Read the uploaded file's contents into a partial form state.
     *
     * @throws ImportException when the contents do not match this layout, or the profile is unavailable
     */
    public function parse(string $contents): ImportResult;
}
