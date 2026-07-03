<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\BacklogCapture;
use App\Enums\BacklogItemKind;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeChatClient;

/**
 * The capture service is the model's one act of authorship (Phase 3): it turns a free-text idea into a
 * structured backlog item. Its contract is proven without a running model: a valid reply is structured,
 * a reply wrapped in prose is still parsed, and — the safety property — an unusable or unreachable model
 * falls back to storing the raw idea as a Task rather than losing it.
 */
final class BacklogCaptureTest extends TestCase
{
    public function test_a_valid_json_reply_is_structured(): void
    {
        $client = new FakeChatClient(['{"kind": "feature", "title": "Model equity release", "note": "Add lifetime mortgages as a housing option."}']);

        $result = (new BacklogCapture($client))->structure('we should let people model equity release');

        $this->assertSame(BacklogItemKind::Feature, $result['kind']);
        $this->assertSame('Model equity release', $result['title']);
        $this->assertStringContainsString('lifetime mortgages', $result['note']);
    }

    public function test_json_wrapped_in_prose_is_still_parsed(): void
    {
        $client = new FakeChatClient(['Sure — here you go: {"kind":"research","title":"Check the LTA rules","note":"Look into the lump-sum allowance."} hope that helps']);

        $result = (new BacklogCapture($client))->structure('look into the LTA');

        $this->assertSame(BacklogItemKind::Research, $result['kind']);
        $this->assertSame('Check the LTA rules', $result['title']);
    }

    public function test_an_unknown_kind_defaults_to_task(): void
    {
        $client = new FakeChatClient(['{"kind":"epic","title":"Big thing","note":"x"}']);

        $this->assertSame(BacklogItemKind::Task, (new BacklogCapture($client))->structure('big thing')['kind']);
    }

    public function test_an_unusable_reply_falls_back_to_a_task_with_the_raw_text(): void
    {
        $client = new FakeChatClient(['I cannot do that.']);   // no JSON object at all

        $result = (new BacklogCapture($client))->structure('add a dark mode');

        $this->assertSame(BacklogItemKind::Task, $result['kind']);
        $this->assertSame('add a dark mode', $result['title']);
    }

    public function test_an_unreachable_model_falls_back_without_calling_it(): void
    {
        $client = new FakeChatClient(available: false);

        $result = (new BacklogCapture($client))->structure('some idea');

        $this->assertSame(BacklogItemKind::Task, $result['kind']);
        $this->assertSame('some idea', $result['title']);
        $this->assertSame(0, $client->calls());
    }
}
