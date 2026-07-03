<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The public /methodology page renders docs/METHODOLOGY.md — the single source that is also the
 * assistant's methodology corpus. These pin that the page is reachable without auth and renders the
 * doc, and that the doc stays wired into the assistant corpus (so "how does it compute X?" keeps a
 * home to retrieve from).
 */
final class MethodologyPageTest extends TestCase
{
    public function test_the_page_is_public_and_renders_the_methodology_doc(): void
    {
        $this->get(route('methodology'))
            ->assertOk()
            ->assertSee('How RetireForecast works')
            ->assertSee('lump-sum tax shock')
            ->assertSee('Monte Carlo')
            ->assertSee('What we don')            // "What we don't model" (avoid the apostrophe)
            ->assertSee('guidance, not advice');  // the regulatory posture is present
    }

    public function test_the_methodology_doc_stays_in_the_assistant_corpus(): void
    {
        $this->assertContains('METHODOLOGY.md', (array) config('assistant.methodology_docs'),
            'METHODOLOGY.md must stay in config(assistant.methodology_docs) so the assistant can retrieve it.');
        $this->assertFileExists(base_path('docs/METHODOLOGY.md'));
    }
}
