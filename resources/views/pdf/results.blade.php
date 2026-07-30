<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>RetireForecast report</title>
    <style>
        /* The printed report deliberately mirrors the on-screen results page: the same white
           cards on a light rule, the same coloured stat tiles, badges and row tints, the same
           section order. dompdf has no JavaScript and no flexbox, so the screen's grids are
           rebuilt as borderless layout tables — but the visual language is the page's.

           Landscape A4: the cashflow ladder and the full-width charts do not fit a portrait
           measure without shrinking the figures past readability. */
        @page { margin: 11mm 9mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 10.5px; line-height: 1.5; }

        h1 { font-size: 22px; color: #111827; margin: 0 0 2px; }
        h2 { font-size: 16px; color: #111827; margin: 0 0 4px; }
        h3 { font-size: 12.5px; color: #111827; margin: 12px 0 3px; }
        p { margin: 5px 0; }

        /* A results-page card: every section is one, as on screen. */
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; margin: 0 0 12px; }
        .lede { color: #4b5563; }
        .note { color: #6b7280; font-size: 9.5px; margin-top: 5px; }
        .meta { color: #6b7280; font-size: 10.5px; margin: 0 0 10px; }
        .badge { background: #dbeafe; color: #1e40af; padding: 1px 7px; border-radius: 9px; font-size: 10px; }

        /* The amber "note about your inputs" panel — this is where the assumed-figure
           disclosures land, so it keeps the screen's prominence rather than becoming fine print. */
        .callout { border: 1px solid #fcd34d; background: #fffbeb; border-radius: 8px; padding: 10px 14px; margin: 0 0 12px; color: #78350f; }
        .callout ul { margin: 5px 0 0; }

        /* The run-out-of-money verdict pill, coloured by risk level as on screen. */
        .verdict { border-radius: 6px; padding: 7px 10px; margin: 7px 0; font-weight: bold; }
        .verdict-none, .verdict-low { background: #ecfdf5; color: #065f46; }
        .verdict-medium { background: #fffbeb; color: #92400e; }
        .verdict-high { background: #fef2f2; color: #991b1b; }

        /* Stat tiles: the screen's <dl> grids of headline figures. A borderless layout table
           because dompdf has no flexbox or CSS grid. */
        table.tiles { width: 100%; border-collapse: collapse; margin: 8px 0 4px; }
        table.tiles td { border: none; padding: 0 5px 0 0; vertical-align: top; }
        .tile { background: #f9fafb; border-radius: 6px; padding: 9px 11px; }
        .tile-blue { background: #eff6ff; }
        .tile-green { background: #ecfdf5; }
        .tile-amber { background: #fffbeb; }
        .tile-label { font-size: 9.5px; color: #6b7280; }
        .tile-value { font-size: 18px; font-weight: bold; color: #111827; margin: 2px 0 0; }
        .tile-note { font-size: 9px; color: #6b7280; margin: 3px 0 0; }

        /* Data tables: underlines only, like the screen's border-b rows — not a boxed grid. */
        table { width: 100%; border-collapse: collapse; margin: 5px 0 3px; }
        th { text-align: left; font-size: 9.5px; color: #374151; background: #f9fafb; border-bottom: 1px solid #e5e7eb; padding: 4px 7px; }
        td { border-bottom: 1px solid #f3f4f6; padding: 3px 7px; }
        td.num, th.num { text-align: right; }
        .total td { border-top: 1px solid #d1d5db; font-weight: bold; }
        /* The cashflow ladder is the densest table in the report. */
        table.dense th, table.dense td { padding: 2px 4px; font-size: 8.5px; }
        /* The screen's ladder row tints: surplus / shortfall / below the safety buffer. */
        tr.surplus td { background: #ecfdf5; }
        tr.shortfall td { background: #fffbeb; }
        tr.below-floor td { background: #fef2f2; }

        .muted { color: #6b7280; }
        ul { margin: 4px 0; padding-left: 16px; }
        li { margin: 1px 0; }

        /* Charts are SVG data URIs from App\Export\ChartSvg, drawn at their natural size:
           1000px = 10.4in, the full text width of the landscape page. */
        img.chart { width: 1000px; margin: 6px 0 2px; }

        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    {{-- One report per scenario, each starting on a fresh page. A single download passes
         one report; "Export all to PDF" passes every ready scenario's. --}}
    @foreach ($reports as $report)
        <div @if (! $loop->first) class="page-break" @endif>
            @include('pdf.partials.report', $report)
        </div>
    @endforeach
</body>
</html>
