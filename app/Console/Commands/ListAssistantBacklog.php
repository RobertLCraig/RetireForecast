<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AssistantBacklogItem;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Lists the ideas the assistant captured to the work queue (Phase 3), so a human (or Claude Code) can
 * review them and promote the worthwhile ones into the real backlog (docs/build/PLAN.md) by hand. The model
 * only ever queues here; promotion and building stay a human act. Read-only — it never edits anything.
 */
class ListAssistantBacklog extends Command
{
    protected $signature = 'assistant:backlog
        {--user= : Only this user id}
        {--kind= : Only this kind (research|feature|task)}';

    protected $description = 'List the ideas the assistant captured to the work queue (for a human to promote or clear).';

    public function handle(): int
    {
        $query = AssistantBacklogItem::query()->with('user')->latest();

        if ($this->option('user') !== null) {
            $query->where('user_id', (int) $this->option('user'));
        }
        if ($this->option('kind') !== null) {
            $query->where('kind', (string) $this->option('kind'));
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            $this->info('No captured ideas.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'User', 'Kind', 'Title', 'Note', 'Captured'],
            $items->map(fn (AssistantBacklogItem $i): array => [
                $i->id,
                $i->user?->email ?? (string) $i->user_id,
                $i->kind->value,
                Str::limit($i->title, 60),
                Str::limit((string) $i->note, 60),
                $i->created_at?->format('Y-m-d H:i') ?? '',
            ])->all(),
        );

        $this->info($items->count().' captured idea(s). Promote worthwhile ones into docs/build/PLAN.md by hand; clear them from the panel or the DB when done.');

        return self::SUCCESS;
    }
}
