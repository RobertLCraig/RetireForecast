<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | What paying for advice would cost this plan (adviser-parity B1)
    |--------------------------------------------------------------------------
    |
    | These figures drive ONE thing: the results page's "What paying for advice
    | would cost" comparison, which re-runs the plan with an adviser's ongoing
    | fee added to the charges it already bears and reports the difference in
    | lifetime pounds and in the year the money runs out.
    |
    | They are deliberately NOT part of `AssumptionSet`. An assumption set is
    | what the projection assumes about the world; this is the parameter of a
    | side-by-side comparison, and putting it in the set would make it look as
    | though the forecast itself were charging an advice fee. The comparison is
    | the only reader.
    |
    | Every figure carries its source and the date it was checked, per the
    | no-magic-numbers rule. The user can override the ongoing fee per scenario
    | in the builder, which is the point: the benchmark average is a starting
    | figure, a real quote is better.
    |
    */

    /*
     * The average ONGOING adviser fee, in basis points a year, charged on the
     * value of the money advised on.
     *
     * 83bp is the 2026 benchmark average, up from 77bp in 2025. It is ADDED to
     * the charges the plan already bears (the platform + fund charge in
     * `AssumptionSet::$investmentCharge`), so the comparison's DIY side stays
     * whatever the user's own assumptions say and the difference between the
     * two sides is exactly this fee. That is the honest construction: the fee
     * is the one figure that can be benchmarked to a primary source, while how
     * much dearer an advised fund choice is varies far too much to assume.
     */
    'ongoing_fee_bp' => 83,

    'ongoing_fee_source' => 'https://nextwealth.co.uk/research/fee-benchmarking-report-2026/',

    'ongoing_fee_source_note' => 'NextWealth Fee Benchmarking Report 2026 (published 12 March 2026): '
        .'average ongoing advice fee 83bp, up from 77bp in 2025. Drawn from data requests to large UK '
        .'advice firms plus surveys of 545 advisers and 261 clients.',

    'verified_on' => '2026-07-31',

];
