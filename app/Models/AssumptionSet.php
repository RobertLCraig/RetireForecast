<?php

declare(strict_types=1);

namespace App\Models;

use App\Finance\Mapping\AssumptionSetMapper;
use Illuminate\Database\Eloquent\Model;
use RetireForecast\FinanceEngine\Dto\AssumptionSet as AssumptionSetDto;

/**
 * Persisted, admin-editable economic assumption set. Maps to and from the engine's
 * {@see AssumptionSetDto}, which stays the single source of truth for the shape.
 * Not personal data, so the figures sit in a plain JSON column.
 *
 * @property string $name
 * @property string $source_note
 * @property bool $is_default
 * @property array $payload
 */
class AssumptionSet extends Model
{
    protected $fillable = ['name', 'source_note', 'is_default', 'payload'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'payload' => 'array',
        ];
    }

    /** Keep at most one default: marking one default clears the flag on the rest. */
    protected static function booted(): void
    {
        static::saved(function (self $set): void {
            if ($set->is_default) {
                static::where('id', '!=', $set->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function toDto(): AssumptionSetDto
    {
        return AssumptionSetMapper::hydrate(
            $this->name,
            $this->source_note,
            $this->is_default,
            $this->payload,
        );
    }

    public static function fromDto(AssumptionSetDto $dto): self
    {
        return (new self)->fillFromDto($dto);
    }

    public function fillFromDto(AssumptionSetDto $dto): static
    {
        $this->name = $dto->name;
        $this->source_note = $dto->sourceNote;
        $this->is_default = $dto->isDefault;
        $this->payload = AssumptionSetMapper::payload($dto);

        return $this;
    }
}
