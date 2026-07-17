<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>RetireForecast summary</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 11px; line-height: 1.4; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        h2 { font-size: 14px; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        .meta { color: #6b7280; font-size: 10px; }
        .disclaimer { border: 1px solid #d1d5db; background: #f9fafb; padding: 8px 10px; margin: 12px 0; font-size: 10px; }
        .disclaimer strong { color: #374151; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #e5e7eb; padding: 3px 6px; text-align: left; }
        th { background: #f3f4f6; font-size: 10px; }
        td.num, th.num { text-align: right; }
        .muted { color: #6b7280; }
        .note { color: #6b7280; font-size: 10px; margin-top: 4px; }
        ul { margin: 4px 0; padding-left: 16px; }
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
