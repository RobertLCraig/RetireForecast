<?php

declare(strict_types=1);

namespace App\Models;

use App\Finance\Mapping\HouseholdMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RetireForecast\FinanceEngine\Dto\Household as HouseholdDto;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

/**
 * A saved household. Maps to and from the engine's {@see HouseholdDto}, the single
 * source of truth for the shape. All sensitive detail (people, pensions, accounts,
 * income, expenses, property) is held in one encrypted-at-rest payload; only name
 * and region are clear structural columns.
 *
 * @property string $name
 * @property RegionProfile $region
 * @property array $payload
 * @property int|null $user_id
 */
class Household extends Model
{
    protected $fillable = ['user_id', 'name', 'region', 'payload'];

    protected function casts(): array
    {
        return [
            'region' => RegionProfile::class,
            'payload' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(Scenario::class);
    }

    public function toDto(): HouseholdDto
    {
        return HouseholdMapper::hydrate($this->name, $this->region, $this->payload);
    }

    public static function fromDto(HouseholdDto $dto, ?int $userId = null): self
    {
        $model = (new self)->fillFromDto($dto);
        $model->user_id = $userId;

        return $model;
    }

    public function fillFromDto(HouseholdDto $dto): static
    {
        $this->name = $dto->name;
        $this->region = $dto->region;
        $this->payload = HouseholdMapper::payload($dto);

        return $this;
    }
}
