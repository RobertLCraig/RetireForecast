<?php

declare(strict_types=1);

namespace App\Import;

/**
 * One row of the import reconciliation panel: a figure the import put into the form, set
 * beside an independent figure for the same quantity read from the sheet itself, so the
 * user can confirm nothing was double-counted or dropped before they save.
 *
 * The data-layer integrity rule (CLAUDE.md / DECISIONS 2026-06-25) requires every
 * imported/aggregated total to be surfaced, and a disagreement between two views of the
 * same quantity to be a *visible* failure, not a silent one. {@see $imported} is the value
 * that went into the form (the source of truth the builder now holds); {@see $stated} is
 * the sheet's own independent figure for the same thing — a TOTAL row, or the sum of the
 * line items the importer did not use as its primary — and is null when the layout offers
 * no second figure to cross-check, in which case the figure is surfaced for the user to
 * eyeball against their own spreadsheet.
 *
 * Both figures are pounds strings (e.g. "24600.00"); equality is judged in exact pence so
 * formatting can never mask or invent a mismatch.
 */
final class ReconciliationLine
{
    public function __construct(
        public readonly string $label,
        public readonly string $imported,
        public readonly ?string $stated = null,
        public readonly ?string $detail = null,
    ) {}

    /** True when nothing contradicts the imported figure: there is no independent figure, or it agrees to the penny. */
    public function reconciles(): bool
    {
        return $this->stated === null
            || MoneyText::toPence($this->imported) === MoneyText::toPence($this->stated);
    }

    /** True only when the sheet carries an independent figure AND it disagrees — a visible failure to surface loudly. */
    public function mismatch(): bool
    {
        return $this->stated !== null && ! $this->reconciles();
    }

    /**
     * A primitive shape for a Livewire public property (the value object itself cannot be
     * held there). The booleans are baked in so the view never re-derives them.
     *
     * @return array{label: string, imported: string, stated: string|null, detail: string|null, reconciles: bool, mismatch: bool}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'imported' => $this->imported,
            'stated' => $this->stated,
            'detail' => $this->detail,
            'reconciles' => $this->reconciles(),
            'mismatch' => $this->mismatch(),
        ];
    }
}
