<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | THE REGULATORY LINE — education/guidance only vs. personal advice
    |--------------------------------------------------------------------------
    |
    | RetireForecast is, by original design, education/guidance only and NEVER a
    | personal recommendation (DECISIONS 2026-06-24): personal pension/drawdown
    | advice is FCA-regulated activity. That posture is enforced two ways — the
    | build-time banned-phrasing partition lint (Tests\Feature\Compliance\
    | BannedPhrasingTest) keeps directive wording out of the neutral output, and
    | the per-user, admin-granted `interpret` Gate keeps the advice-style
    | "interpretation" readouts OFF by default.
    |
    | While this stays a PRIVATE, local-first tool for the owner's own use (NOT a
    | public release), that boundary is deliberately relaxed so the tool can give
    | the best possible *direct* advice. With `personal_use` = true the advice-style
    | interpretation is ON for everyone, with no admin grant needed.
    |
    | *** BEFORE ANY PUBLIC RELEASE, set this to false. ***  The full guidance-only
    | posture then re-applies: the `interpret` Gate falls back to the per-user
    | `can_interpret` grant (off by default) and the banned-phrasing lint keeps the
    | neutral zone clean. This config key is the SINGLE, findable home of that line.
    | See DECISIONS 2026-06-30 ("Personal-use advice mode").
    |
    */

    'personal_use' => env('COMPLIANCE_PERSONAL_USE', true),

];
