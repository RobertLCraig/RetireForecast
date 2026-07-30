<?php

declare(strict_types=1);

namespace App\Export;

use App\Forecast\ResultPresenter;

/**
 * Draws the results page's charts as plain SVG, for the printable PDF report.
 *
 * The PDF renderer (dompdf) executes no JavaScript, so the on-screen ApexCharts canvases
 * cannot be printed. Rather than build a second, drift-prone chart pipeline, this renders
 * the SAME ApexCharts option blob {@see ResultPresenter} already produces for
 * the screen — same series, same colours, same milestone annotations — into static vector
 * SVG. A chart in the PDF therefore plots the identical numbers the screen plots; there is
 * one source for the data and only the drawing differs.
 *
 * dompdf ignores an inline `<svg>` element but renders `<img src="data:image/svg+xml;...">`
 * as true vectors (verified against dompdf 3.1 / php-svg-lib 1.0), so {@see dataUri} is what
 * the Blade template embeds. Only the subset of SVG php-svg-lib supports is emitted: paths,
 * rects, lines, text with `text-anchor`, `fill-opacity` and `stroke-dasharray`. No rotated
 * text (milestones are numbered instead and keyed to the milestone table), no gradients.
 */
final class ChartSvg
{
    /**
     * Default canvas, in SVG user units — which dompdf reads as CSS pixels at 96 dpi, so this is
     * 10.4in × 5.0in: the full text width of a landscape A4 page and about two thirds of its
     * height. Sized deliberately large: the first cut drew at 720×320 and read as a postage stamp
     * on the page, which was the whole complaint about the printed charts.
     */
    public const WIDTH = 1000;

    public const HEIGHT = 480;

    private const PAD_LEFT = 84;

    private const PAD_RIGHT = 18;

    private const PAD_TOP = 12;

    private const PAD_BOTTOM = 50;

    /** Height of one legend row. The income staircase can carry nine series, so it wraps. */
    private const LEGEND_ROW = 18;

    /** Type sizes, chosen to stay legible once the canvas is printed at its natural size. */
    private const AXIS_FONT = 11;

    private const LEGEND_FONT = 11;

    private const AXIS_COLOUR = '#9ca3af';

    private const GRID_COLOUR = '#e5e7eb';

    private const TEXT_COLOUR = '#374151';

    private const FONT = 'DejaVu Sans';

    /**
     * The chart as a `data:` URI ready for an `<img src="...">` in the PDF template.
     * Base64 rather than raw, so the SVG's own quotes and `#` colours cannot break the
     * attribute or be mistaken for a URL fragment.
     *
     * @param  array<string, mixed>  $options  an ApexCharts option blob from ResultPresenter
     */
    public static function dataUri(array $options): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::render($options));
    }

    /**
     * The chart as an SVG document. Public so tests can assert the drawn geometry traces to
     * the presenter's series rather than only checking that *something* was emitted.
     *
     * @param  array<string, mixed>  $options  an ApexCharts option blob from ResultPresenter
     */
    public static function render(array $options): string
    {
        $series = self::seriesOf($options);

        if ($series === []) {
            return self::empty('No data to plot.');
        }

        $xs = self::xValues($series);
        if (count($xs) < 2) {
            return self::empty('Not enough years to plot.');
        }

        $stacked = (bool) ($options['chart']['stacked'] ?? false);
        $colours = array_values($options['colors'] ?? []);

        // Head the chart with the y-axis unit on its own line (no rotated text here), then lay
        // the legend out beneath it: the income staircase can carry nine series, whose names
        // would run off the canvas on one row, so the legend wraps and the plot starts below.
        $yTitle = (string) ($options['yaxis']['title']['text'] ?? '');
        $legendRows = self::legendRows($series, $colours);
        $captionRows = ($yTitle === '' ? 0 : 1);
        $plotTop = self::PAD_TOP + (($captionRows + count($legendRows)) * self::LEGEND_ROW);
        $plotLeft = self::PAD_LEFT;
        $plotRight = self::WIDTH - self::PAD_RIGHT;
        $plotBottom = self::HEIGHT - self::PAD_BOTTOM;

        // The plotted values: a stacked chart draws cumulative bands, so the axis has to span
        // the stack height, not the tallest single series.
        $bands = self::bands($series, $xs, $stacked);
        [$yMin, $yMax, $ticks] = self::scale($bands, $options);

        $xAt = static fn (float $x): float => $plotLeft + ($x - $xs[0]) / max(1e-9, (float) (end($xs) - $xs[0])) * ($plotRight - $plotLeft);
        $yAt = static fn (float $y): float => $plotBottom - ($y - $yMin) / max(1e-9, $yMax - $yMin) * ($plotBottom - $plotTop);

        $parts = [];

        // Plot background, so a band that reaches the edge still reads as inside the frame.
        $parts[] = self::rect($plotLeft, $plotTop, $plotRight - $plotLeft, $plotBottom - $plotTop, '#ffffff');

        // Below-£0 territory shaded red: where a net-position fan runs into cumulative
        // shortfall, that region should read at a glance, exactly as it does on screen.
        if ($yMin < 0) {
            $zero = $yAt(0.0);
            $parts[] = self::rect($plotLeft, $zero, $plotRight - $plotLeft, $plotBottom - $zero, '#fee2e2', 0.6);
        }

        // Horizontal gridlines + y-axis labels.
        foreach ($ticks as $tick) {
            $y = $yAt($tick);
            $parts[] = self::line($plotLeft, $y, $plotRight, $y, abs($tick) < 1e-9 ? self::AXIS_COLOUR : self::GRID_COLOUR);
            $parts[] = self::text($plotLeft - 7, $y + 4, self::abbreviate($tick), self::AXIS_FONT, self::TEXT_COLOUR, 'end');
        }

        // The series themselves, painted back-to-front so a later band overlays an earlier one
        // in the same order ApexCharts stacks them on screen.
        foreach ($bands as $i => $band) {
            $colour = $colours[$i % max(1, count($colours))] ?? '#3b82f6';
            $opacity = self::opacityFor($options, $i, $band['kind']);
            $strokeWidth = self::strokeWidthFor($options, $i, $band['kind']);

            if ($band['kind'] === 'line') {
                $parts[] = self::path(self::polyline($band['upper'], $xAt, $yAt), 'none', 1.0, $colour, max(1.5, $strokeWidth));

                continue;
            }

            $parts[] = self::path(
                self::area($band['lower'], $band['upper'], $xAt, $yAt),
                $colour,
                $opacity,
                $band['kind'] === 'area' ? '#ffffff' : 'none',
                $band['kind'] === 'area' ? 0.75 : 0.0,
            );
        }

        // Axis frame drawn last so the series never paint over it.
        $parts[] = self::line($plotLeft, $plotBottom, $plotRight, $plotBottom, self::AXIS_COLOUR);
        $parts[] = self::line($plotLeft, $plotTop, $plotLeft, $plotBottom, self::AXIS_COLOUR);

        // X-axis year labels: about eight, evenly spaced across the actual plotted years, so a
        // label always sits on a year the data has.
        foreach (self::xTicks($xs) as $year) {
            $x = $xAt((float) $year);
            $parts[] = self::line($x, $plotBottom, $x, $plotBottom + 5, self::AXIS_COLOUR);
            $parts[] = self::text($x, $plotBottom + 18, (string) $year, self::AXIS_FONT, self::TEXT_COLOUR, 'middle');
        }

        $xTitle = (string) ($options['xaxis']['title']['text'] ?? '');
        if ($xTitle !== '') {
            $parts[] = self::text(($plotLeft + $plotRight) / 2, self::HEIGHT - 8, $xTitle, self::AXIS_FONT, self::TEXT_COLOUR, 'middle');
        }

        // Milestone verticals, NUMBERED rather than labelled: the labels are rotated on screen,
        // which this renderer cannot do, so each line carries its index and the report prints a
        // matching numbered milestone table beneath the chart. Nothing is dropped, only moved.
        foreach (self::milestones($options) as $n => $milestone) {
            $year = (float) $milestone['x'];
            if ($year < $xs[0] || $year > (float) end($xs)) {
                continue;
            }
            $x = $xAt($year);
            $colour = (string) ($milestone['borderColor'] ?? '#94a3b8');
            $parts[] = self::line($x, $plotTop, $x, $plotBottom, $colour, 1.2, '4,4');
            $parts[] = self::rect($x - 8, $plotTop + 1, 16, 15, $colour, 0.9);
            $parts[] = self::text($x, $plotTop + 12.5, (string) ($n + 1), 10, '#ffffff', 'middle');
        }

        // The y-axis unit, headed above the legend. php-svg-lib's rotated-text support is
        // unreliable, so it is a caption line rather than a rotated axis label.
        if ($yTitle !== '') {
            $parts[] = self::text(4, self::PAD_TOP + 11, $yTitle, self::AXIS_FONT, self::TEXT_COLOUR, 'start');
        }

        // Legend beneath it: swatch + series name, the same names and colours as on screen.
        foreach ($legendRows as $row => $entries) {
            $top = self::PAD_TOP + (($captionRows + $row) * self::LEGEND_ROW);
            foreach ($entries as $entry) {
                $parts[] = self::rect($entry['x'], $top + 3, 11, 11, $entry['colour'], 0.9);
                $parts[] = self::text($entry['x'] + 15, $top + 12, $entry['name'], self::LEGEND_FONT, self::TEXT_COLOUR, 'start');
            }
        }

        return self::document(implode("\n", $parts));
    }

    /**
     * The legend laid out into rows that fit the canvas: each entry keeps its series' colour
     * and gets an x position, wrapping to a new row when the next name would overflow. Returns
     * an empty list when no series is named, so the plot then starts at the top padding.
     *
     * @param  list<array<string, mixed>>  $series
     * @param  list<string>  $colours
     * @return list<list<array{x: float, name: string, colour: string}>>
     */
    private static function legendRows(array $series, array $colours): array
    {
        $rows = [];
        $current = [];
        $x = self::PAD_LEFT;

        foreach ($series as $i => $s) {
            $name = (string) ($s['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $width = 15 + self::textWidth($name, self::LEGEND_FONT) + 18;

            if ($current !== [] && $x + $width > self::WIDTH - self::PAD_RIGHT) {
                $rows[] = $current;
                $current = [];
                $x = self::PAD_LEFT;
            }

            $current[] = ['x' => $x, 'name' => $name, 'colour' => $colours[$i % max(1, count($colours))] ?? '#3b82f6'];
            $x += $width;
        }

        if ($current !== []) {
            $rows[] = $current;
        }

        return $rows;
    }

    /**
     * The series to plot, ignoring any that carry no points. Keeps the presenter's order, so
     * the colour a series gets here is the colour it has on screen.
     *
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private static function seriesOf(array $options): array
    {
        return array_values(array_filter(
            $options['series'] ?? [],
            static fn ($s): bool => is_array($s) && ! empty($s['data']),
        ));
    }

    /**
     * Every x value that occurs, ascending. The presenter emits one point per calendar year
     * per series, so this is the projection's year axis.
     *
     * @param  list<array<string, mixed>>  $series
     * @return list<int>
     */
    private static function xValues(array $series): array
    {
        $xs = [];
        foreach ($series as $s) {
            foreach ($s['data'] as $point) {
                $xs[(int) $point['x']] = true;
            }
        }
        $xs = array_keys($xs);
        sort($xs);

        return $xs;
    }

    /**
     * Each series resolved to a lower and upper edge per x, ready to fill between:
     *  - a rangeArea point carries `y => [lo, hi]`, so its edges are given;
     *  - a stacked area's lower edge is the running total of the series beneath it;
     *  - a plain area sits on zero; a line has no fill and only its upper edge is drawn.
     * Missing points are treated as zero (a source that pays in only some years), which is
     * what the on-screen stack does.
     *
     * @param  list<array<string, mixed>>  $series
     * @param  list<int>  $xs
     * @return list<array{kind: string, lower: array<int, float>, upper: array<int, float>}>
     */
    private static function bands(array $series, array $xs, bool $stacked): array
    {
        $running = array_fill_keys($xs, 0.0);
        $bands = [];

        foreach ($series as $s) {
            $type = (string) ($s['type'] ?? ($stacked ? 'area' : 'line'));
            $byX = [];
            foreach ($s['data'] as $point) {
                $byX[(int) $point['x']] = $point['y'];
            }

            $lower = [];
            $upper = [];
            foreach ($xs as $x) {
                $y = $byX[$x] ?? null;

                if ($type === 'rangeArea') {
                    $lower[$x] = (float) ($y[0] ?? 0);
                    $upper[$x] = (float) ($y[1] ?? 0);

                    continue;
                }

                $value = (float) ($y ?? 0);
                if ($stacked && $type !== 'line') {
                    $lower[$x] = $running[$x];
                    $upper[$x] = $running[$x] + $value;
                    $running[$x] = $upper[$x];

                    continue;
                }

                $lower[$x] = 0.0;
                $upper[$x] = $value;
            }

            $bands[] = ['kind' => $type === 'line' ? 'line' : ($type === 'rangeArea' ? 'rangeArea' : 'area'), 'lower' => $lower, 'upper' => $upper];
        }

        return $bands;
    }

    /**
     * The y scale: the span the bands actually occupy, extended to a round step so the
     * gridline labels are readable figures. Honours an explicit `yaxis.min` (the presenter
     * anchors at £0 unless a fan dips into shortfall, and that anchoring should survive here).
     *
     * @param  list<array{kind: string, lower: array<int, float>, upper: array<int, float>}>  $bands
     * @param  array<string, mixed>  $options
     * @return array{0: float, 1: float, 2: list<float>}
     */
    private static function scale(array $bands, array $options): array
    {
        $min = INF;
        $max = -INF;
        foreach ($bands as $band) {
            foreach ([$band['lower'], $band['upper']] as $edge) {
                foreach ($edge as $value) {
                    $min = min($min, $value);
                    $max = max($max, $value);
                }
            }
        }
        if (! is_finite($min) || ! is_finite($max)) {
            $min = 0.0;
            $max = 1.0;
        }

        if (array_key_exists('min', $options['yaxis'] ?? [])) {
            $min = min($min, (float) $options['yaxis']['min']);
        }
        $min = min($min, 0.0);
        if ($max <= $min) {
            $max = $min + 1.0;
        }

        $step = self::niceStep(($max - $min) / 5);
        $niceMin = floor($min / $step) * $step;
        $niceMax = ceil($max / $step) * $step;

        $ticks = [];
        for ($t = $niceMin; $t <= $niceMax + $step / 2; $t += $step) {
            $ticks[] = round($t, 6);
        }

        return [$niceMin, $niceMax, $ticks];
    }

    /** A round gridline step at or above the raw span/5 — 1, 2, 2.5 or 5 times a power of ten. */
    private static function niceStep(float $raw): float
    {
        if ($raw <= 0.0) {
            return 1.0;
        }
        $magnitude = 10 ** floor(log10($raw));
        foreach ([1, 2, 2.5, 5, 10] as $multiple) {
            if ($raw <= $multiple * $magnitude) {
                return $multiple * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    /**
     * About eight x-axis labels, always on years the data actually has (so a label can never
     * point at a year outside the projection). The final year is always labelled.
     *
     * @param  list<int>  $xs
     * @return list<int>
     */
    private static function xTicks(array $xs): array
    {
        $count = count($xs);
        $wanted = min(10, $count);
        $stride = max(1, (int) ceil(($count - 1) / max(1, $wanted - 1)));

        $ticks = [];
        for ($i = 0; $i < $count; $i += $stride) {
            $ticks[] = $xs[$i];
        }
        $last = $xs[$count - 1];
        if (end($ticks) !== $last) {
            // Drop a penultimate tick that would collide with the final one.
            if ($ticks !== [] && $last - (int) end($ticks) < $stride / 2) {
                array_pop($ticks);
            }
            $ticks[] = $last;
        }

        return $ticks;
    }

    /**
     * The milestone verticals merged into the options by the results page
     * ({@see ResultPresenter::milestoneAnnotations}).
     *
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private static function milestones(array $options): array
    {
        return array_values(array_filter(
            $options['annotations']['xaxis'] ?? [],
            static fn ($a): bool => is_array($a) && isset($a['x']),
        ));
    }

    /** The fill opacity for series $i, honouring a per-series `fill.opacity` list. */
    private static function opacityFor(array $options, int $i, string $kind): float
    {
        if ($kind === 'line') {
            return 1.0;
        }
        $opacity = $options['fill']['opacity'] ?? 0.85;

        return (float) (is_array($opacity) ? ($opacity[$i] ?? 0.85) : $opacity);
    }

    /** The stroke width for series $i, honouring a per-series `stroke.width` list. */
    private static function strokeWidthFor(array $options, int $i, string $kind): float
    {
        $width = $options['stroke']['width'] ?? 1;

        return (float) (is_array($width) ? ($width[$i] ?? 1) : $width);
    }

    /**
     * A filled area between two edges: along the upper edge left-to-right, back along the
     * lower edge right-to-left, closed.
     *
     * @param  array<int, float>  $lower
     * @param  array<int, float>  $upper
     */
    private static function area(array $lower, array $upper, callable $xAt, callable $yAt): string
    {
        $forward = [];
        foreach ($upper as $x => $y) {
            $forward[] = self::point($xAt((float) $x), $yAt($y));
        }
        $back = [];
        foreach (array_reverse($lower, true) as $x => $y) {
            $back[] = self::point($xAt((float) $x), $yAt($y));
        }

        return 'M '.implode(' L ', [...$forward, ...$back]).' Z';
    }

    /** @param  array<int, float>  $edge */
    private static function polyline(array $edge, callable $xAt, callable $yAt): string
    {
        $points = [];
        foreach ($edge as $x => $y) {
            $points[] = self::point($xAt((float) $x), $yAt($y));
        }

        return 'M '.implode(' L ', $points);
    }

    private static function point(float $x, float $y): string
    {
        return self::num($x).' '.self::num($y);
    }

    private static function path(string $d, string $fill, float $fillOpacity, string $stroke, float $strokeWidth): string
    {
        $attributes = 'd="'.$d.'" fill="'.$fill.'"';
        if ($fill !== 'none') {
            $attributes .= ' fill-opacity="'.self::num($fillOpacity).'"';
        }
        if ($stroke !== 'none' && $strokeWidth > 0) {
            $attributes .= ' stroke="'.$stroke.'" stroke-width="'.self::num($strokeWidth).'"';
        }

        return '<path '.$attributes.'/>';
    }

    private static function rect(float $x, float $y, float $w, float $h, string $fill, float $opacity = 1.0): string
    {
        return '<rect x="'.self::num($x).'" y="'.self::num($y).'" width="'.self::num(max(0, $w))
            .'" height="'.self::num(max(0, $h)).'" fill="'.$fill.'" fill-opacity="'.self::num($opacity).'"/>';
    }

    private static function line(float $x1, float $y1, float $x2, float $y2, string $stroke, float $width = 1.0, ?string $dash = null): string
    {
        return '<path d="M '.self::point($x1, $y1).' L '.self::point($x2, $y2).'" fill="none" stroke="'.$stroke
            .'" stroke-width="'.self::num($width).'"'.($dash === null ? '' : ' stroke-dasharray="'.$dash.'"').'/>';
    }

    private static function text(float $x, float $y, string $content, float $size, string $fill, string $anchor): string
    {
        return '<text x="'.self::num($x).'" y="'.self::num($y).'" font-family="'.self::FONT.'" font-size="'.self::num($size)
            .'" fill="'.$fill.'" text-anchor="'.$anchor.'">'.htmlspecialchars($content, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</text>';
    }

    /** A rough advance width, good enough to space legend entries without measuring the font. */
    private static function textWidth(string $text, float $size): float
    {
        return mb_strlen($text) * $size * 0.52;
    }

    /** Locale-proof decimal output — SVG requires a '.' separator whatever the PHP locale. */
    private static function num(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.') ?: '0';
    }

    /** Axis money labels: £1.2m / £340k / £0 / −£12k, matching the screen's abbreviated axis. */
    private static function abbreviate(float $pounds): string
    {
        $sign = $pounds < 0 ? "\u{2212}" : '';
        $abs = abs($pounds);

        if ($abs >= 1_000_000) {
            return $sign.'£'.rtrim(rtrim(number_format($abs / 1_000_000, 1, '.', ''), '0'), '.').'m';
        }
        if ($abs >= 1_000) {
            return $sign.'£'.number_format($abs / 1_000, $abs < 10_000 ? 1 : 0, '.', '').'k';
        }

        return $sign.'£'.number_format($abs, 0, '.', ',');
    }

    private static function empty(string $message): string
    {
        return self::document(self::text(self::WIDTH / 2, self::HEIGHT / 2, $message, 11, self::TEXT_COLOUR, 'middle'));
    }

    private static function document(string $body): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::WIDTH.'" height="'.self::HEIGHT
            .'" viewBox="0 0 '.self::WIDTH.' '.self::HEIGHT.'">'."\n".$body."\n".'</svg>';
    }
}
