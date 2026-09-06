<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Jobs\RunLeverThreshold;
use App\Jobs\RunScenarioSimulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two long-running queued jobs must survive a worker that can actually enforce its
 * timeout, and a worker restart must not put two copies of the same run on the same record.
 *
 * Windows has no `pcntl`, so a worker here cannot enforce a timeout at all and both faults
 * are invisible on this machine. They are not invisible in CI, in Docker, in WSL or on any
 * Linux deploy, which is why these are asserted on the QUEUE PAYLOAD and on the job's own
 * middleware rather than by running a worker.
 */
class QueuedRunSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** What a worker allows a job that names no timeout of its own (`queue:work --timeout`). */
    private const WORKER_DEFAULT_TIMEOUT = 60;

    /** @return array<string, array{class-string}> */
    public static function longRunningJobs(): array
    {
        return [
            'full simulation' => [RunScenarioSimulation::class],
            'lever threshold' => [RunLeverThreshold::class],
        ];
    }

    #[DataProvider('longRunningJobs')]
    public function test_a_long_run_is_not_killed_by_the_workers_default_timeout(string $jobClass): void
    {
        $timeout = $this->pushedPayload($jobClass)['timeout'];

        $this->assertNotNull(
            $timeout,
            "{$jobClass} names no timeout, so a worker that can enforce one kills every run after "
            .self::WORKER_DEFAULT_TIMEOUT.' seconds and marks it failed.'
        );
        $this->assertGreaterThan(self::WORKER_DEFAULT_TIMEOUT, $timeout);
    }

    #[DataProvider('longRunningJobs')]
    public function test_a_killed_run_is_not_silently_started_again(string $jobClass): void
    {
        $this->assertSame(
            1,
            $this->pushedPayload($jobClass)['maxTries'],
            "{$jobClass} names no attempt limit, so a killed run is quietly started again from the top."
        );
    }

    public function test_the_retry_window_outlasts_the_longest_run(): void
    {
        $timeouts = array_map(
            fn (array $case): mixed => $this->pushedPayload($case[0])['timeout'],
            array_values(self::longRunningJobs()),
        );
        $this->assertNotContains(null, $timeouts, 'A job names no timeout, so there is no window to outlast.');
        $longest = max(array_map(intval(...), $timeouts));

        $this->assertGreaterThan(
            $longest,
            (int) config('queue.connections.database.retry_after'),
            'The retry window is inside the job timeout, so the queue hands a still-running job to a '
            .'second worker and both write results for the same run.'
        );
    }

    #[DataProvider('longRunningJobs')]
    public function test_a_restarted_worker_cannot_run_the_same_record_twice_at_once(string $jobClass): void
    {
        $runs = 0;

        // Worker A has the job and is still inside it...
        $this->throughMiddleware(new $jobClass(42), function () use (&$runs, $jobClass): void {
            $runs++;
            // ...when a restarted worker B picks the same still-reserved job up.
            $this->throughMiddleware(new $jobClass(42), function () use (&$runs): void {
                $runs++;
            });
        });

        $this->assertSame(1, $runs, "Two workers ran {$jobClass} for the same record at the same time.");
    }

    #[DataProvider('longRunningJobs')]
    public function test_two_different_records_do_not_block_each_other(string $jobClass): void
    {
        $runs = 0;

        $this->throughMiddleware(new $jobClass(42), function () use (&$runs, $jobClass): void {
            $runs++;
            $this->throughMiddleware(new $jobClass(43), function () use (&$runs): void {
                $runs++;
            });
        });

        $this->assertSame(2, $runs, "{$jobClass} locks the whole queue rather than the one record.");
    }

    /**
     * The payload the database queue actually writes, which is what a worker reads its
     * timeout and attempt limit back off.
     *
     * @return array<string, mixed>
     */
    private function pushedPayload(string $jobClass): array
    {
        DB::table('jobs')->delete();
        Queue::connection('database')->push(new $jobClass(1));

        return json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Run $work as the worker does: through the job's own middleware stack. */
    private function throughMiddleware(object $job, callable $work): void
    {
        $middleware = method_exists($job, 'middleware') ? $job->middleware() : [];

        (new Pipeline($this->app))->send($job)->through($middleware)->then(fn () => $work());
    }
}
