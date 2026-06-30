<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Enums\SimulationMode;
use App\Enums\SimulationStatus;
use App\Jobs\RunScenarioSimulation;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates a scenario's forecast run end to end: create the run record, execute
 * the buy-vs-rent comparison, report live progress, persist a Result per variant,
 * and land in a terminal status. Nothing runs silently — the run carries its status
 * and progress throughout, and a cancel between progress ticks stops it cleanly.
 *
 * A preview runs synchronously (responsive); the full run is queued.
 */
final class SimulationRunner
{
    public function __construct(private readonly ScenarioForecaster $forecaster) {}

    public function createRun(Scenario $scenario, SimulationMode $mode, ?int $seed = null, ?int $paths = null): SimulationRun
    {
        $run = new SimulationRun([
            'scenario_id' => $scenario->id,
            'user_id' => $scenario->user_id,
            'mode' => $mode,
            'n_paths' => $paths ?? $mode->defaultPaths(),
            'seed' => $seed ?? random_int(0, 2_147_483_647),
            'status' => SimulationStatus::Queued,
            'progress_pct' => 0,
            'engine_version' => ScenarioForecaster::ENGINE_VERSION,
            'taxyear_config_version' => $this->forecaster->config($scenario)->verifiedOn,
        ]);
        $run->setAssumptionSnapshot($this->forecaster->assumptions($scenario));
        $run->save();

        return $run;
    }

    /** A quick synchronous preview: create then run now, returning the finished run. */
    public function preview(Scenario $scenario, ?int $seed = null, ?int $paths = null): SimulationRun
    {
        $run = $this->createRun($scenario, SimulationMode::Preview, $seed, $paths);
        $this->execute($run);

        return $run->fresh();
    }

    /** Queue the full run on the worker; returns the queued run to poll. */
    public function dispatch(Scenario $scenario, ?int $seed = null): SimulationRun
    {
        $run = $this->createRun($scenario, SimulationMode::Full, $seed);
        RunScenarioSimulation::dispatch($run->id);

        return $run;
    }

    public function execute(SimulationRun $run): void
    {
        if ($run->status->isTerminal()) {
            return; // cancelled before it started, or already finished
        }

        $run->update(['status' => SimulationStatus::Running, 'started_at' => now(), 'progress_pct' => 0]);

        try {
            $comparison = $this->forecaster->compareHousing(
                $run->scenario,
                $run->n_paths,
                $run->seed,
                onProgress: function (float $fraction) use ($run): void {
                    $pct = min(99, (int) floor($fraction * 100));
                    if ($pct > $run->progress_pct) {
                        $run->update(['progress_pct' => $pct]);
                        $this->assertNotCancelled($run);
                    }
                },
            );
        } catch (RunCancelled) {
            $run->update(['status' => SimulationStatus::Cancelled, 'finished_at' => now()]);

            return;
        } catch (Throwable $e) {
            $run->update(['status' => SimulationStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()]);

            return;
        }

        DB::transaction(function () use ($run, $comparison): void {
            foreach ($comparison as $variant => $result) {
                (new Result(['simulation_run_id' => $run->id, 'variant' => $variant]))
                    ->setSimulationResult($result)
                    ->save();
            }

            $run->update(['status' => SimulationStatus::Done, 'progress_pct' => 100, 'finished_at' => now()]);

            // Snapshot this run's headline figures for the chosen strategy, so the next run can
            // be diffed against it. The snapshot lives on the scenario, which survives the run
            // deletion an input edit triggers — so the diff works across an edit, not just two
            // runs on identical inputs ({@see ResultPresenter::runDiff}).
            $scenario = $run->scenario;
            $primarySim = $comparison[$scenario->variant->value] ?? reset($comparison);
            if ($primarySim instanceof SimulationResult) {
                $scenario->recordResultSnapshot($primarySim);
            }
        });
    }

    /** Request cancellation; the running job stops at its next progress tick. */
    public function cancel(SimulationRun $run): void
    {
        if (! $run->status->isTerminal()) {
            $run->update(['status' => SimulationStatus::Cancelled, 'finished_at' => now()]);
        }
    }

    private function assertNotCancelled(SimulationRun $run): void
    {
        if ($run->fresh()?->status === SimulationStatus::Cancelled) {
            throw new RunCancelled;
        }
    }
}
