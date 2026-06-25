<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The scenario builder's in-progress form state, persisted so a forecast survives
 * navigation / an accidental leave / a closed tab. One active draft per user; the
 * payload is the raw builder strings (not a Household DTO), encrypted at rest.
 */
class ScenarioDraft extends Model
{
    protected $fillable = ['user_id', 'payload'];

    protected $casts = [
        'payload' => 'encrypted:array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
