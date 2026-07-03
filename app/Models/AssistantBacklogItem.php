<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BacklogItemKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One captured idea on the assistant's work queue (Phase 3). Attributed to the user who captured it,
 * timestamped, and freely deletable — the reversible, no-confirm write the local model is allowed.
 * Ideas are about the tool, not the household's finances, so the fields are plain (not encrypted).
 *
 * @property BacklogItemKind $kind
 * @property string $title
 * @property string|null $note
 * @property string|null $source
 * @property int $user_id
 */
class AssistantBacklogItem extends Model
{
    protected $fillable = ['user_id', 'kind', 'title', 'note', 'source'];

    protected function casts(): array
    {
        return [
            'kind' => BacklogItemKind::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
