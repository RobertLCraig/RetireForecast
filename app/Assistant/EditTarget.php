<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Forecast\BuilderStateDelta;
use App\Forecast\WhatIfChanges;

/**
 * One field the assistant is allowed to change, and the only currency it may deal in
 * (guardrail C2). Each is a REAL dot-path into the base's builder form-state that already
 * resolves there, carrying the label the what-if diff will show it under, its type, and the
 * value it holds today — so the reader sees what is being changed from and to, never a
 * figure they cannot see ("no invisible figures").
 *
 * Built by the app in {@see ScenarioEditVocabulary}, never by the model: the model may only
 * pick a path off the menu it is handed.
 */
final readonly class EditTarget
{
    /**
     * @param  string  $path  dot-path into the builder form-state ({@see BuilderStateDelta})
     * @param  string  $type  money | rate | int | enum — what a value must coerce to
     * @param  string  $currentValue  the raw form-state value today (form-state is strings)
     * @param  list<string>  $options  the accepted values, for an enum target
     */
    public function __construct(
        public string $path,
        public string $label,
        public string $type,
        public string $currentValue,
        public array $options = [],
    ) {}

    /** The current value as the reader sees it elsewhere (£ / % / a readable enum label). */
    public function currentDisplay(): string
    {
        $segments = explode('.', $this->path);

        return WhatIfChanges::formatValue((string) end($segments), $this->currentValue);
    }

    /** The menu line the model selects from: the id it must copy, what it is, its type, its value now. */
    public function menuLine(): string
    {
        $options = $this->options === [] ? '' : ' (one of: '.implode(', ', $this->options).')';

        return "{$this->path} | {$this->label}{$options} | {$this->type} | now {$this->currentDisplay()}";
    }
}
