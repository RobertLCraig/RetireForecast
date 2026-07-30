<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

use App\Export\ChartSvg;
use Tests\TestCase;

/**
 * The server-side SVG chart renderer that puts the results page's charts into the printable
 * PDF. dompdf executes no JavaScript, so the screen's ApexCharts canvases cannot print; this
 * re-draws them from the SAME option blob ResultPresenter hands the screen chart. These tests
 * pin the drawing to that input — a band per series, in the presenter's colours, on an axis
 * that actually spans the data — so a chart in the PDF cannot quietly plot something else.
 */
class ChartSvgTest extends TestCase
{
    /** Two-series stacked area: the presenter's shape for the income / wealth / cost charts. */
    private function stackedOptions(): array
    {
        return [
            'chart' => ['type' => 'area', 'stacked' => true],
            'colors' => ['#111111', '#222222'],
            'series' => [
                ['name' => 'Alpha', 'data' => [['x' => 2026, 'y' => 10_000], ['x' => 2027, 'y' => 20_000], ['x' => 2028, 'y' => 30_000]]],
                ['name' => 'Beta', 'data' => [['x' => 2026, 'y' => 5_000], ['x' => 2027, 'y' => 5_000], ['x' => 2028, 'y' => 5_000]]],
            ],
            'yaxis' => ['min' => 0, 'title' => ['text' => 'Wealth (real £)']],
            'xaxis' => ['title' => ['text' => 'Calendar year']],
        ];
    }

    public function test_it_draws_one_band_per_series_in_the_presenters_colours(): void
    {
        $svg = ChartSvg::render($this->stackedOptions());

        $this->assertStringContainsString('fill="#111111"', $svg);
        $this->assertStringContainsString('fill="#222222"', $svg);
        $this->assertStringContainsString('Alpha', $svg);
        $this->assertStringContainsString('Beta', $svg);
    }

    /**
     * A stacked chart's axis has to span the STACK, not the tallest single series: 30k + 5k
     * here. An axis topping out at 30k would clip the top band off the picture.
     */
    public function test_a_stacked_axis_spans_the_stack_not_the_tallest_series(): void
    {
        $svg = ChartSvg::render($this->stackedOptions());

        $this->assertGreaterThanOrEqual(35_000.0, $this->axisTop($svg));
    }

    /** A rangeArea (the Monte Carlo fan) draws its bands plus the median line. */
    public function test_it_draws_a_range_area_fan_with_its_median_line(): void
    {
        $svg = ChartSvg::render([
            'chart' => ['type' => 'rangeArea'],
            'colors' => ['#93c5fd', '#3b82f6', '#1e3a8a'],
            'series' => [
                ['name' => '10th–90th percentile', 'type' => 'rangeArea', 'data' => [['x' => 2026, 'y' => [1_000, 9_000]], ['x' => 2027, 'y' => [500, 12_000]]]],
                ['name' => '25th–75th percentile', 'type' => 'rangeArea', 'data' => [['x' => 2026, 'y' => [3_000, 7_000]], ['x' => 2027, 'y' => [2_500, 9_000]]]],
                ['name' => 'Median (50th)', 'type' => 'line', 'data' => [['x' => 2026, 'y' => 5_000], ['x' => 2027, 'y' => 6_000]]],
            ],
            'fill' => ['opacity' => [0.25, 0.4, 1]],
            'stroke' => ['width' => [0, 0, 3]],
            'yaxis' => ['min' => 0],
        ]);

        $this->assertStringContainsString('fill="#93c5fd"', $svg);
        $this->assertStringContainsString('fill="#3b82f6"', $svg);
        // The median is a stroked line, never a filled band.
        $this->assertMatchesRegularExpression('/fill="none" stroke="#1e3a8a"/', $svg);
        $this->assertGreaterThanOrEqual(12_000.0, $this->axisTop($svg));
    }

    /**
     * A fan that runs into cumulative shortfall must show it: the axis extends below £0 and the
     * negative territory is shaded, exactly as the screen chart does.
     */
    public function test_a_fan_that_dips_below_zero_extends_the_axis_and_shades_the_shortfall(): void
    {
        $svg = ChartSvg::render([
            'chart' => ['type' => 'rangeArea'],
            'colors' => ['#93c5fd'],
            'series' => [
                ['name' => 'band', 'type' => 'rangeArea', 'data' => [['x' => 2026, 'y' => [1_000, 9_000]], ['x' => 2027, 'y' => [-40_000, 5_000]]]],
            ],
        ]);

        $this->assertStringContainsString('−£', $svg, 'A fan below £0 should carry a negative axis label.');
        $this->assertStringContainsString('fill="#fee2e2"', $svg, 'Below-£0 territory should be shaded, as on screen.');
    }

    /**
     * Milestone verticals are NUMBERED (the screen's rotated labels are not drawable here) and
     * a milestone outside the plotted years is skipped rather than drawn off the axis.
     */
    public function test_it_numbers_milestone_verticals_and_skips_ones_outside_the_plot(): void
    {
        $options = $this->stackedOptions();
        $options['annotations']['xaxis'] = [
            ['x' => 2027, 'borderColor' => '#0284c7', 'label' => ['text' => 'Alex retires']],
            ['x' => 2099, 'borderColor' => '#dc2626', 'label' => ['text' => 'Far future']],
        ];

        $svg = ChartSvg::render($options);

        $this->assertStringContainsString('stroke="#0284c7"', $svg);
        $this->assertStringContainsString('>1<', $svg, 'The in-range milestone should carry its number.');
        $this->assertStringNotContainsString('stroke="#dc2626"', $svg, 'A milestone outside the plotted years should not be drawn.');
    }

    /** A source that pays in only some years is plotted as zero elsewhere, never dropped. */
    public function test_a_series_with_gaps_is_plotted_as_zero_not_dropped(): void
    {
        $svg = ChartSvg::render([
            'chart' => ['type' => 'area', 'stacked' => true],
            'colors' => ['#111111', '#222222'],
            'series' => [
                ['name' => 'Always', 'data' => [['x' => 2026, 'y' => 1_000], ['x' => 2027, 'y' => 1_000], ['x' => 2028, 'y' => 1_000]]],
                ['name' => 'Late only', 'data' => [['x' => 2028, 'y' => 4_000]]],
            ],
        ]);

        $this->assertStringContainsString('fill="#222222"', $svg);
        $this->assertGreaterThanOrEqual(5_000.0, $this->axisTop($svg));
    }

    public function test_it_renders_a_placeholder_rather_than_failing_on_an_empty_series(): void
    {
        $svg = ChartSvg::render(['chart' => ['type' => 'area'], 'series' => []]);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('No data to plot', $svg);
    }

    /** dompdf reads the chart from an `<img>`; a bare `<svg>` element is silently ignored. */
    public function test_the_data_uri_is_a_base64_svg_image(): void
    {
        $uri = ChartSvg::dataUri($this->stackedOptions());

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $this->assertStringContainsString('<svg', base64_decode(substr($uri, strlen('data:image/svg+xml;base64,'))));
    }

    /**
     * SVG requires a '.' decimal separator whatever the host locale. A comma would silently
     * corrupt every coordinate in every chart on a machine set to a European locale.
     */
    public function test_coordinates_use_a_dot_decimal_separator_under_any_locale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German');

        try {
            $svg = ChartSvg::render($this->stackedOptions());
            preg_match_all('/d="([^"]+)"/', $svg, $paths);
            $this->assertNotEmpty($paths[1]);
            foreach ($paths[1] as $d) {
                $this->assertStringNotContainsString(',', $d, 'A locale comma has corrupted the path coordinates.');
            }
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }

    /** The largest y-axis money label the chart drew, in pounds. */
    private function axisTop(string $svg): float
    {
        preg_match_all('/>−?£([\d.]+)([km]?)</u', $svg, $labels, PREG_SET_ORDER);
        $top = 0.0;
        foreach ($labels as [, $value, $unit]) {
            $top = max($top, (float) $value * ['' => 1, 'k' => 1_000, 'm' => 1_000_000][$unit]);
        }

        return $top;
    }
}
