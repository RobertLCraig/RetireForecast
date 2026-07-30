{{-- The numbered milestone key for a printed chart. The on-screen charts label each life-event
     vertical with rotated text, which the PDF's SVG renderer cannot draw, so ChartSvg numbers
     the verticals instead and this line resolves the numbers. Nothing is dropped — the same
     events, with their years, are also tabulated in "When the big events happen". --}}
@if (! empty($milestones))
    <p class="note">Dashed verticals mark the life events:
        @foreach ($milestones as $milestone)
            <strong>{{ $loop->iteration }}</strong> {{ $milestone['year'] }} {{ $milestone['label'] }}@if (! $loop->last); @endif
        @endforeach
    </p>
@endif
