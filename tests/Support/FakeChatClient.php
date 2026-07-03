<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Assistant\AssistantUnavailable;
use App\Assistant\ChatClient;

/**
 * A scripted {@see ChatClient} for testing the assistant's orchestration and guardrails with
 * no running model. It replays a queue of replies (the last repeats once exhausted, so a
 * single reply models a model that keeps making the same mistake), and can simulate an
 * unreachable runtime or a mid-generation failure.
 */
final class FakeChatClient implements ChatClient
{
    /** @var list<string> */
    private array $replies;

    private int $index = 0;

    /** @var list<list<array{role: string, content: string}>> the message sets it was asked with */
    public array $received = [];

    /**
     * @param  list<string>  $replies
     */
    public function __construct(array $replies = [], private bool $available = true, private bool $throwOnChat = false)
    {
        $this->replies = array_values($replies);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function chat(array $messages): string
    {
        $this->received[] = $messages;

        if ($this->throwOnChat) {
            throw new AssistantUnavailable('simulated failure');
        }

        $reply = $this->replies[$this->index] ?? (end($this->replies) ?: '');
        $this->index++;

        return $reply;
    }

    /** How many generations were requested (asserts the retry actually happened / didn't). */
    public function calls(): int
    {
        return count($this->received);
    }
}
