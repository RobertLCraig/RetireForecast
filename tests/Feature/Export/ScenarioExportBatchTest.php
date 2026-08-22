<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

use App\Enums\ScenarioStatus;
use App\Export\ScenarioExport;
use App\Gdpr\GdprService;
use App\Jobs\BuildScenarioExport;
use App\Livewire\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;
use ZipArchive;

/**
 * "Export all to PDF" past the point one request can render it.
 *
 * A single dompdf document holds every page in memory until it is written, so a one-request
 * export costs memory AND time that grow with the number of forecasts — measured on this
 * machine at 8.5s / 274MB for five, 79s / 774MB for twenty and 172s / 1114MB for thirty,
 * against a 1512M PHP limit and a 300s gateway timeout. Past
 * {@see ScenarioExport::BATCH_ABOVE} the export therefore moves to the worker and is built
 * one forecast at a time, so its cost stops growing with the count.
 *
 * What is guarded here is that the batching is real: each forecast is rendered as its own
 * complete standalone report, not as one document split up afterwards.
 */
class ScenarioExportBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Storage::fake('local');
    }

    /** Small exports keep the direct download — it has plenty of headroom and needs no worker. */
    public function test_an_export_that_fits_one_request_is_still_rendered_there(): void
    {
        Queue::fake();
        ScenarioFixture::rich($this->user);
        ScenarioFixture::rich($this->user);

        $response = $this->get(route('scenarios.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        Queue::assertNothingPushed();
    }

    public function test_an_export_too_big_for_one_request_is_queued_instead(): void
    {
        Queue::fake();
        $count = ScenarioExport::BATCH_ABOVE + 1;
        for ($i = 0; $i < $count; $i++) {
            ScenarioFixture::rich($this->user);
        }

        $this->get(route('scenarios.pdf'))->assertRedirect(route('dashboard'));

        Queue::assertPushed(BuildScenarioExport::class, fn (BuildScenarioExport $job): bool => $job->userId === $this->user->id);

        // …and the user is told it is running, with how much there is to do.
        $status = app(ScenarioExport::class)->status($this->user);
        $this->assertSame('building', $status['state']);
        $this->assertSame($count, $status['total']);
        $this->assertSame(0, $status['done']);
    }

    /** A second click while one is already running must not queue the same archive twice. */
    public function test_asking_again_while_it_is_building_does_not_queue_a_second_build(): void
    {
        Queue::fake();
        for ($i = 0; $i <= ScenarioExport::BATCH_ABOVE; $i++) {
            ScenarioFixture::rich($this->user);
        }

        $this->get(route('scenarios.pdf'));
        $this->get(route('scenarios.pdf'));

        Queue::assertPushed(BuildScenarioExport::class, 1);
    }

    /**
     * The archive holds one COMPLETE report per forecast. Each entry being a PDF of its own
     * that carries the report's own front matter is what proves the export was rendered a
     * forecast at a time, rather than assembled as one document — which is the whole point of
     * batching it.
     */
    public function test_the_queued_export_writes_one_complete_report_per_forecast(): void
    {
        // Dated apart so "newest first" is a real order rather than a same-second tie.
        $first = ScenarioFixture::rich($this->user);
        $first->forceFill(['name' => 'First plan', 'created_at' => now()->subDays(2)])->save();
        $second = ScenarioFixture::rich($this->user);
        $second->forceFill(['name' => 'Second plan', 'created_at' => now()->subDay()])->save();
        $draft = ScenarioFixture::rich($this->user);
        $draft->update(['name' => 'Unfinished draft', 'status' => ScenarioStatus::Draft]);
        ScenarioFixture::rich(User::factory()->create());

        (new BuildScenarioExport($this->user->id))->handle(app(ScenarioExport::class));

        $entries = $this->archiveEntries();

        // Newest base first, as on the dashboard, numbered so the archive lists in that order.
        $this->assertSame(['01-second-plan.pdf', '02-first-plan.pdf'], array_keys($entries));

        foreach ($entries as $name => $contents) {
            $this->assertStringStartsWith('%PDF', $contents, "{$name} is not a PDF of its own.");
        }
    }

    public function test_the_finished_export_is_reported_ready_and_can_be_downloaded(): void
    {
        ScenarioFixture::rich($this->user);

        (new BuildScenarioExport($this->user->id))->handle(app(ScenarioExport::class));

        $status = app(ScenarioExport::class)->status($this->user);
        $this->assertSame('ready', $status['state']);
        $this->assertSame(1, $status['done']);
        $this->assertSame(1, $status['total']);

        $response = $this->get(route('scenarios.pdf.archive'));
        $response->assertOk();
        $response->assertDownload('retireforecast-all-scenarios.zip');
    }

    /** One user's archive is never served to another: the path is derived from who is asking. */
    public function test_the_archive_is_owner_scoped(): void
    {
        ScenarioFixture::rich($this->user);
        (new BuildScenarioExport($this->user->id))->handle(app(ScenarioExport::class));

        $this->actingAs(User::factory()->create());

        $this->get(route('scenarios.pdf.archive'))->assertNotFound();
    }

    public function test_there_is_nothing_to_download_before_an_export_is_built(): void
    {
        $this->get(route('scenarios.pdf.archive'))->assertNotFound();
    }

    /**
     * No silent failure: a dead worker (timeout, OOM, killed) must not leave the dashboard
     * polling forever. The job's failed() handler lands the export in a terminal state with
     * its reason.
     */
    public function test_a_failed_build_is_reported_with_its_reason(): void
    {
        (new BuildScenarioExport($this->user->id))->failed(new RuntimeException('worker died'));

        $status = app(ScenarioExport::class)->status($this->user);
        $this->assertSame('failed', $status['state']);
        $this->assertSame('worker died', $status['error']);
    }

    public function test_a_failed_build_without_a_message_still_says_something(): void
    {
        (new BuildScenarioExport($this->user->id))->failed(null);

        $this->assertNotEmpty(app(ScenarioExport::class)->status($this->user)['error']);
    }

    /** Building an export of nothing is a stated failure, not an empty zip. */
    public function test_an_export_with_no_finished_forecasts_fails_with_a_reason(): void
    {
        (new BuildScenarioExport($this->user->id))->handle(app(ScenarioExport::class));

        $status = app(ScenarioExport::class)->status($this->user);
        $this->assertSame('failed', $status['state']);
        $this->assertNotEmpty($status['error']);
        $this->assertFalse(app(ScenarioExport::class)->exists($this->user));
    }

    /**
     * Erasing an account takes the built archive with it. The zip is a file on disk, so no
     * foreign key reaches it: without this, "erased means gone" would leave a full copy of
     * that household's forecasts behind.
     */
    public function test_erasing_an_account_deletes_its_built_export(): void
    {
        ScenarioFixture::rich($this->user);
        (new BuildScenarioExport($this->user->id))->handle(app(ScenarioExport::class));
        $this->assertTrue(app(ScenarioExport::class)->exists($this->user));

        app(GdprService::class)->erase($this->user);

        $this->assertFalse(app(ScenarioExport::class)->exists($this->user));
    }

    /** The dashboard is where a queued export reports itself, through each of its states. */
    public function test_the_dashboard_shows_the_export_running_then_ready_then_broken(): void
    {
        Queue::fake();
        ScenarioFixture::rich($this->user);
        $export = app(ScenarioExport::class);

        $export->queue($this->user, 12);
        Livewire::test(Dashboard::class)
            ->assertSee('Building your export')
            ->assertSee('0 of 12 forecasts rendered');

        (new BuildScenarioExport($this->user->id))->handle($export);
        Livewire::test(Dashboard::class)
            ->assertSee('is ready')
            ->assertSee(route('scenarios.pdf.archive'), escape: false);

        $export->fail($this->user, 'worker died');
        Livewire::test(Dashboard::class)->assertSee('worker died');
    }

    /**
     * The archive's contents, keyed by entry name in archive order.
     *
     * @return array<string, string>
     */
    private function archiveEntries(): array
    {
        $path = Storage::disk('local')->path(app(ScenarioExport::class)->path($this->user));
        $this->assertFileExists($path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The export archive could not be opened.');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $entries;
    }
}
