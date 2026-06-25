{{-- WALLED-OFF advice-style interpretation. Rendered only when the per-user `interpret`
     Gate allows (admin-granted, off by default). Every directive sentence originates in
     App\Compliance\Interpretation; this template only lists what that service produced.
     This file (its name carries "interpretation") and that service are the sole
     exemptions to the banned-phrasing build test. --}}
<section aria-labelledby="interpretation-heading" class="rounded-lg border border-violet-200 bg-violet-50 p-5">
    <h2 id="interpretation-heading" class="text-xl font-semibold text-violet-900">What this suggests</h2>
    <p class="mt-1 text-xs text-violet-800">
        Advice-style interpretation, enabled for your account. This is a directive reading of the figures
        above, not a regulated personal recommendation. Free, impartial guidance remains available below.
    </p>
    <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-violet-900">
        @foreach ($interpretation as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
</section>
