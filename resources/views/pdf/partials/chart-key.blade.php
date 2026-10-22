{{-- The numbered milestone key for a printed chart. The on-screen charts label each life-event
     vertical with rotated text, which the PDF's SVG renderer cannot draw, so ChartSvg numbers
     the verticals instead and this line resolves the numbers. A chart draws only the events
     ResultPresenter::chartedMilestones() keeps, so the key numbers those; every event, with its
     year, is still tabulated in "When the big events happen". --}}
@php($charted = \App\Forecast\ResultPresenter::chartedMilestones($milestones ?? []))
@if (! empty($charted))
    <p class="note">Dashed verticals mark the life events:
        @foreach ($charted as $milestone)
            <strong>{{ $loop->iteration }}</strong> {{ $milestone['year'] }} {{ $milestone['label'] }}@if (! $loop->last); @endif
        @endforeach
    </p>
@endif
