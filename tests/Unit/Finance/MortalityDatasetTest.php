<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Finance\MortalityDataset;
use RetireForecast\FinanceEngine\Mortality\OnsPeriodMortalityData;
use RuntimeException;
use Tests\TestCase;

class MortalityDatasetTest extends TestCase
{
    /**
     * The load-bearing guard: the generated {@see OnsPeriodMortalityData} must still match its
     * JSON source home, cell for cell. Nothing else checked this, so a hand-edit or a
     * half-finished refresh could silently drift the class from its source (the data-integrity
     * rule: one definition, one home).
     */
    public function test_the_embedded_ons_data_matches_its_json_source_exactly(): void
    {
        $grid = MortalityDataset::grid(MortalityDataset::load(base_path(MortalityDataset::RESOURCE)));
        $diff = MortalityDataset::diff(OnsPeriodMortalityData::periodQx(), $grid);

        $this->assertSame(5100, $diff['compared'], '51 ages x 50 years x 2 sexes');
        $this->assertSame(0, $diff['changed'], 'the generated class has drifted from its JSON source');
    }

    public function test_grid_normalises_to_a_complete_int_keyed_grid(): void
    {
        $grid = MortalityDataset::grid(MortalityDataset::load(base_path(MortalityDataset::RESOURCE)));

        $this->assertCount(51, $grid['male']);   // ages 50-100
        $this->assertCount(50, $grid['male'][50]); // years 2025-2074
        $this->assertArrayHasKey('female', $grid);
        // Keys are integers (not the JSON's strings), matching the engine grid.
        $this->assertSame(0.003428, $grid['male'][50][2025]);
    }

    public function test_diff_counts_only_cells_that_moved_beyond_the_epsilon(): void
    {
        $base = ['male' => [50 => [2025 => 0.0100, 2026 => 0.0110]], 'female' => [50 => [2025 => 0.0090]]];
        $incoming = ['male' => [50 => [2025 => 0.0100, 2026 => 0.0120]], 'female' => [50 => [2025 => 0.0050]]];

        $diff = MortalityDataset::diff($base, $incoming);

        $this->assertSame(3, $diff['compared']);
        $this->assertSame(2, $diff['changed']); // 2026 male + 2025 female moved; 2025 male held
        $this->assertEqualsWithDelta(0.0040, $diff['maxAbsDelta'], 1e-9); // the female cell moved most
        $this->assertCount(2, $diff['samples']);
    }

    public function test_load_rejects_a_file_missing_required_keys(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mort').'.json';
        file_put_contents($path, json_encode(['source' => 'x'])); // no verified_on / period_qx

        $this->expectException(RuntimeException::class);
        try {
            MortalityDataset::load($path);
        } finally {
            @unlink($path);
        }
    }
}
