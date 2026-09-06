<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;

/**
 * The ONE home for the deprivation-of-capital copy, so the engine, the results notes, the PDF
 * and the lump-sum panel cannot each say it differently (board card 0049).
 *
 * Deprivation appeared nowhere in this tool, while the tool models the Pension Credit capital
 * tariff, names the downsizing trap, and cheerfully projects plans built on selling possessions,
 * taking family money, spending a lump on a one-off and cashing pension pots. Every one of those
 * is a deprivation question:
 *
 * - **Benefits — the notional capital rule.** Money a claimant has spent, given away or converted
 *   can be treated as capital they still hold where getting or keeping a means-tested benefit was
 *   a significant reason for parting with it. There is no time limit on it.
 * - **Care charging — deliberate deprivation** (Care and Support Statutory Guidance, Annex E, made
 *   under the Care Act 2014). A local authority can treat an asset as still held where avoiding
 *   the charge was a significant motivation AND the need for care was reasonably foreseeable at
 *   the time. It can also recover the charge from the person who RECEIVED the money. There is no
 *   time limit on that either.
 *
 * Neither is a calculation this engine performs — both turn on motive and foreseeability, which
 * no model holds. So this is a WARNING on events the model already knows about, not a figure.
 *
 * The copy is deliberately factual and states the rule; it never says what to do. The one action
 * it names is a free benefits check, which is what tells the reader what an award would actually
 * be worth before the money moves.
 *
 * SOURCES ARE NAMED, NOT FETCHED: the two rules are cited by name above (the notional capital rule
 * and Annex E of the Care and Support Statutory Guidance). No URL is pinned because the session
 * that built this had no web access; nothing here reaches a projection, it only reaches prose.
 */
final class Deprivation
{
    /**
     * The neutral warning for a plan that moves a large sum, naming the events that triggered it.
     *
     * @param  list<string>  $events  the moves this plan makes, each already formatted for a reader
     * @param  Money  $threshold  the capital figure a move is judged large against, read from the
     *                            config constant that owns it, never restated here
     */
    public static function warning(array $events, Money $threshold): Warning
    {
        return new Warning(WarningCode::CAPITAL_DEPRIVATION, self::message($events, $threshold));
    }

    /**
     * The threshold is OPTIONAL because not every caller has one to name: a year-0 home sale is
     * raised by the presenter, which holds no tax-year config, and a home sale needs no test of
     * size to be large. Omitting it drops the sentence rather than inventing a figure.
     *
     * @param  list<string>  $events
     */
    public static function message(array $events, ?Money $threshold = null): string
    {
        $moves = $events === [] ? 'This plan moves a large sum.' : 'This plan moves a large sum: '.implode('; ', $events).'.';

        return $moves.' Spending, giving away or otherwise parting with capital is not always the '
            .'end of it. For means-tested benefits the notional capital rule lets the DWP or the '
            .'council treat money you no longer have as capital you still hold, where getting or '
            .'keeping the benefit was a significant reason for parting with it. For care charging '
            .'the deliberate deprivation test in Annex E of the Care and Support Statutory Guidance '
            .'does the same, where avoiding the charge was a significant motivation and the need for '
            .'care was reasonably foreseeable at the time; a local authority can also recover the '
            .'charge from the person who received the money. Neither rule has a time limit, so there '
            .'is no number of years after which a gift is safe. Nothing in this forecast applies '
            .'either rule: both turn on why the money moved, which no model can see, so the figures '
            .'here assume the money is simply gone. '
            .($threshold === null ? '' : 'A move above '.$threshold->format().', the capital limit '
                .'that already ends Housing Benefit and Council Tax Support, is large enough to '
                .'change what you are entitled to on its own. ')
            .self::benefitsCheckPointer();
    }

    /**
     * The one action the copy names. Kept apart so every surface points at the same thing.
     */
    public static function benefitsCheckPointer(): string
    {
        return 'Get a free benefits check before you move the money, not after: Citizens Advice, '
            .'Age UK and your council\'s welfare rights service all do them, and a check run on '
            .'your figures as they stand is what tells you what an award is worth and what moving '
            .'the money would cost it.';
    }

    /**
     * Gift with reservation of benefit: the idea a household in this position hears from friends,
     * and the one that costs them everything. Signing the home over to a child and carrying on
     * living in it is not a gift for Inheritance Tax, saves nothing, and is the plainest
     * deprivation case there is for care charging.
     */
    public static function giftWithReservation(): string
    {
        return 'Signing your home over to a child while you carry on living in it does not work the '
            .'way it is usually described. For Inheritance Tax it is a gift with reservation of '
            .'benefit: because you keep the benefit of the house, it stays in your estate however '
            .'many years pass, so no tax is saved (paying a full market rent to the new owner is the '
            .'only way out, and that rent is taxable income in their hands). For care charging it is '
            .'the clearest deliberate deprivation there is, and the council can assess you as still '
            .'owning the home, or recover the charge from your child. You also stop being the owner: '
            .'the home is then exposed to your child\'s divorce, bankruptcy or death, and you have no '
            .'right to stay. '.self::benefitsCheckPointer();
    }
}
