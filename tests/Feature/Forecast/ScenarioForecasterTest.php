<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\DrawCandidate;
use App\Forecast\ScenarioForecaster;
use App\Forecast\WithdrawalStrategyComparison;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Money\Money;
use Tests\Support\BuilderStateFixture;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

class ScenarioForecasterTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): Scenario
    {
        return ScenarioFixture::rich(User::factory()->create());
    }

    public function test_the_deterministic_forecast_runs_for_a_persisted_scenario(): void
    {
        $result = (new ScenarioForecaster)->deterministic($this->scenario());

        $this->assertNotEmpty($result->years);
        $this->assertGreaterThanOrEqual(2026, $result->finalCalendarYear);
    }

    public function test_the_withdrawal_strategy_comparison_prices_each_strategy_and_reconciles(): void
    {
        $comparison = WithdrawalStrategyComparison::for(new ScenarioForecaster, $this->scenario());

        // A rich household pays tax over the plan under either withdrawal strategy.
        $this->assertGreaterThan(0, $comparison->baselineTaxPence);
        $this->assertGreaterThan(0, $comparison->fillBandsTaxPence);
        // The saving is EXACTLY the difference of the two engine-computed lifetime-tax totals
        // (one figure, one home - never re-derived from something else).
        $this->assertSame($comparison->baselineTaxPence - $comparison->fillBandsTaxPence, $comparison->savingPence);
    }

    public function test_the_optimiser_returns_the_cheapest_candidate_and_reconciles_to_two_engine_runs(): void
    {
        $forecaster = new ScenarioForecaster;
        $comparison = WithdrawalStrategyComparison::for($forecaster, $this->scenario());

        // The reported delta is the difference of two of the engine's OWN runs, never a
        // re-derivation: re-run the winner and the current order and subtract.
        $lifetimeTax = function (DrawCandidate $candidate) use ($forecaster): int {
            $run = $forecaster->deterministicUnderStrategy($this->scenario(), $candidate->strategy, $candidate->taxableIncomeTargetPence);
            $total = $run->iht?->total->pence ?? 0;
            foreach ($run->years as $year) {
                $total += $year->totalTax->pence;
            }

            return $total;
        };
        $this->assertSame($lifetimeTax($comparison->cheapest), $comparison->cheapestTaxPence);
        $this->assertSame(
            $lifetimeTax(DrawCandidate::order($comparison->current)) - $lifetimeTax($comparison->cheapest),
            $comparison->optimiserSavingPence,
        );

        // The winner really is the cheapest of the candidate set, and the optimiser never
        // reports a saving for an order that pays MORE than the one in place.
        foreach ($this->candidates() as $candidate) {
            $this->assertLessThanOrEqual($lifetimeTax($candidate), $comparison->cheapestTaxPence);
        }
        $this->assertGreaterThanOrEqual(0, $comparison->optimiserSavingPence);
    }

    /** The candidate set the optimiser really searches, for the fixture's own tax year. */
    private function candidates(): array
    {
        return WithdrawalStrategyComparison::candidates((new ScenarioForecaster)->config($this->scenario()));
    }

    /**
     * The rich couple spending well beyond their income, holding most of their money in a taxable
     * account. The stock fixture funds its spending out of income, so every draw order ties and
     * nothing about ORDER can be seen in it at all; this household has to draw on its capital every
     * year, which is the only state in which which pot it draws first can matter.
     */
    private function drawsOnItsCapital(): Scenario
    {
        $state = BuilderStateFixture::full();
        $state['expenseLines'][0]['amount'] = '70000';
        $state['accounts'][1]['balance'] = '300000';
        $state['accounts'][1]['unrealisedGain'] = '90000';

        return ScenarioFixture::rich(User::factory()->create(), $state);
    }

    /**
     * Board card 0078, criterion 1. The search only ever ran the three orders the tool has NAMES
     * for, so "the cheapest of the draw orders we tried" was a pick from a menu: it could not find
     * an order nobody had already written down, and a household sitting just over a threshold paid
     * for that every year of its plan. A generated candidate has to be a genuinely DIFFERENT
     * forecast, not a rename of one of the three, or the search has widened on paper only.
     */
    public function test_the_search_runs_an_order_that_is_not_one_of_the_three_named_ones(): void
    {
        $forecaster = new ScenarioForecaster;
        $scenario = $this->drawsOnItsCapital();

        $lifetimeTax = function (DrawCandidate $candidate) use ($forecaster, $scenario): int {
            $run = $forecaster->deterministicUnderStrategy($scenario, $candidate->strategy, $candidate->taxableIncomeTargetPence);
            $total = $run->iht?->total->pence ?? 0;
            foreach ($run->years as $year) {
                $total += $year->totalTax->pence;
            }

            return $total;
        };

        $named = [];
        foreach (WithdrawalStrategyComparison::CANDIDATES as $strategy) {
            $named[$strategy->name] = $lifetimeTax(DrawCandidate::order($strategy));
        }

        $candidates = WithdrawalStrategyComparison::candidates($forecaster->config($scenario));
        $generated = array_values(array_filter($candidates, fn (DrawCandidate $c): bool => $c->isGenerated()));
        $this->assertNotEmpty($generated, 'the search still tries only the orders the tool has names for');

        // Each generated order really is a different order: it pays a lifetime tax none of the
        // three named ones pays. Equal totals would mean the target never reached the projector.
        foreach ($generated as $candidate) {
            $this->assertNotContains($lifetimeTax($candidate), $named,
                "The generated order \"{$candidate->label()}\" pays exactly what a named order pays, so it is not "
                .'a different order at all: the target never reached the projector.');
        }

        // ...and it can WIN, which is the whole point of the card: on this household the cheapest
        // order of the set is one the tool holds no name for, so the old search could not find it.
        $comparison = WithdrawalStrategyComparison::for($forecaster, $scenario);
        $this->assertTrue($comparison->cheapest->isGenerated(),
            'the cheapest order here is a generated one, so a search that stops at the named orders misses it');
        $this->assertLessThan(min($named), $comparison->cheapestTaxPence);
    }

    /**
     * Board card 0078, criterion 2. Every candidate is a whole deterministic forecast run on a page
     * render, so the size of the set IS what the page costs. The plan's ceiling is 4 to 6.
     */
    public function test_the_bounded_search_stays_within_the_forecasts_a_page_can_afford(): void
    {
        $candidates = $this->candidates();

        $this->assertGreaterThanOrEqual(4, count($candidates));
        $this->assertLessThanOrEqual(6, count($candidates));

        // ...and the panel reports the number it really ran, not a count of a different list.
        $comparison = WithdrawalStrategyComparison::for(new ScenarioForecaster, $this->scenario());
        $this->assertSame(count($candidates), $comparison->panel()['candidateCount']);

        // No two candidates share a key, or one would silently overwrite another's total and the
        // search would run fewer orders than it says it did.
        $keys = array_map(fn (DrawCandidate $c): string => $c->key(), $candidates);
        $this->assertSame($keys, array_unique($keys));
    }

    /**
     * Board card 0078, criterion 3. A generated order has no name in the tool, so the panel could
     * only have named it by its internal setting. The reader has to be told the thing they would
     * actually do and the figure it turns on.
     */
    public function test_a_generated_order_is_named_in_terms_the_reader_can_act_on(): void
    {
        // The panel names the winner, so start from a household a generated order actually wins on.
        $comparison = WithdrawalStrategyComparison::for(new ScenarioForecaster, $this->drawsOnItsCapital());
        $this->assertTrue($comparison->cheapest->isGenerated());
        $this->assertSame($comparison->cheapest->label(), $comparison->panel()['cheapestLabel'],
            'the panel must name the order that actually won, not the nearest one that has a name');

        $generated = array_values(array_filter($this->candidates(), fn (DrawCandidate $c): bool => $c->isGenerated()));
        $this->assertNotEmpty($generated);

        foreach ($generated as $candidate) {
            $label = $candidate->label();

            // The amount is in the name, because the amount is the whole instruction.
            $this->assertStringContainsString(
                Money::fromPence($candidate->taxableIncomeTargetPence)->format(),
                $label,
                "\"{$label}\" does not say what to keep the income under, which is the only actionable part of it.",
            );
            // ...and no internal setting is printed at a reader.
            foreach ([$candidate->strategy->value, $candidate->strategy->name, 'pence', 'target', '::'] as $internal) {
                $this->assertStringNotContainsStringIgnoringCase($internal, $label,
                    "\"{$label}\" names the internal setting \"{$internal}\" rather than what the reader would do.");
            }
        }
    }

    public function test_the_lifetime_tax_the_optimiser_ranks_on_counts_the_tax_paid_at_death(): void
    {
        // The panel calls its totals "tax paid across the plan" and the steer turns the gap into
        // "the order to lean towards for tax" — but the metric used to stop at the last living
        // year and throw away the Inheritance Tax the SAME run computes and the same page prints.
        // Two ways that misleads: an order that pays less income tax ends with more wealth, so the
        // estate hands roughly 40% of the "saving" back; and a pension outside the estate versus an
        // ISA inside it can swing IHT further than the income-tax gap and invert the winner.
        $user = User::factory()->create();
        $forecaster = new ScenarioForecaster;
        $scenario = fn (bool $iht): Scenario => ScenarioFixture::rich($user, ['ihtModelled' => $iht]);

        // The fixture has to actually pay the death tax, or this test proves nothing.
        $modelled = $forecaster->deterministicUnderStrategy($scenario(true), DrawdownStrategy::DEFAULT);
        $this->assertGreaterThan(0, $modelled->iht?->total->pence ?? 0, 'the fixture pays no IHT, so it cannot pin this');

        // Every candidate's total is its own year-by-year tax PLUS its own death tax — one run,
        // both figures, never a re-derivation.
        $yearlyTax = 0;
        foreach ($modelled->years as $year) {
            $yearlyTax += $year->totalTax->pence;
        }
        $withIht = WithdrawalStrategyComparison::for($forecaster, $scenario(true));
        $this->assertSame($yearlyTax + $modelled->iht->total->pence, $withIht->baselineTaxPence);
        $this->assertTrue($withIht->includesIht, 'the panel must say the death tax is in the total');

        // ...and turning the toggle off leaves the yearly tax alone, so the difference between the
        // two headline totals IS the death tax and nothing else has moved.
        $withoutIht = WithdrawalStrategyComparison::for($forecaster, $scenario(false));
        $this->assertSame($yearlyTax, $withoutIht->baselineTaxPence);
        $this->assertFalse($withoutIht->includesIht, 'an unmodelled death tax must not be claimed as counted');
        $this->assertSame(
            $modelled->iht->total->pence,
            $withIht->baselineTaxPence - $withoutIht->baselineTaxPence,
        );
    }

    public function test_the_panel_never_compares_the_current_order_against_itself(): void
    {
        // The two tiles are "your current order" and one named alternative. Since card 0075 the
        // current order is the READER'S, so any of the candidates can be in the first tile: if the
        // alternative were fixed the panel would print the same order twice and report a £0 saving
        // against itself, which reads as "there is nothing to gain here". Pinned for every order
        // the reader can pick, not just for the one that used to be hard-coded.
        foreach (WithdrawalStrategyComparison::CANDIDATES as $current) {
            $alternative = WithdrawalStrategyComparison::alternativeTo($current);
            $this->assertNotSame($current, $alternative, 'the panel would show one draw order in both tiles');
            $this->assertContains($alternative, WithdrawalStrategyComparison::CANDIDATES);
        }
    }

    /**
     * Board card 0075, criterion 3. The baseline tile is captioned "your current order", and it
     * read a CONSTANT: a reader who picked a different order was shown somebody else's baseline,
     * and the saving beside it was measured against a plan that is not theirs.
     */
    public function test_the_panel_names_the_chosen_order_as_the_current_one(): void
    {
        $user = User::factory()->create();
        $forecaster = new ScenarioForecaster;

        foreach (DrawdownStrategy::cases() as $chosen) {
            $scenario = ScenarioFixture::rich($user, [
                'assumptionOverrides' => ['drawdownStrategy' => $chosen->value],
            ]);
            $comparison = WithdrawalStrategyComparison::for($forecaster, $scenario);
            $panel = $comparison->panel();
            $this->assertNotNull($panel);

            $this->assertSame($chosen->label(), $panel['baselineLabel'],
                'the baseline tile must name the order the reader actually picked');
            $this->assertNotSame($panel['baselineLabel'], $panel['alternativeLabel'],
                'the second tile must be a different order, whichever one is current');

            // ...and the figure under that caption is the run under THAT order, not another one.
            $run = $forecaster->deterministicUnderStrategy($scenario, $chosen);
            $lifetime = $run->iht?->total->pence ?? 0;
            foreach ($run->years as $year) {
                $lifetime += $year->totalTax->pence;
            }
            $this->assertSame($lifetime, $comparison->baselineTaxPence);
        }
    }

    public function test_the_screen_and_the_printed_panel_both_read_every_figure_it_publishes(): void
    {
        // The optimiser's figures reached the screen partial and not the PDF one, while the steer
        // (which both print) named the winner and its saving — so the PDF told a reader a third
        // order saves £X with no figure on the page behind it. That is an invisible figure. The
        // section-level PDF completeness test could not see it: the section was there, only its
        // figures were not. So pin it at the level it broke, the keys of the shared panel().
        $panel = WithdrawalStrategyComparison::for(new ScenarioForecaster, $this->scenario())->panel();
        $this->assertNotNull($panel);

        foreach ([
            'screen' => 'views/livewire/partials/withdrawal-sequencing.blade.php',
            'printed' => 'views/pdf/partials/report.blade.php',
        ] as $where => $template) {
            $source = (string) file_get_contents(resource_path($template));
            foreach (array_keys($panel) as $key) {
                $this->assertStringContainsString("\$withdrawal['{$key}']", $source,
                    "The {$where} withdrawal panel never reads \$withdrawal['{$key}'], so it shows less than the other one does.");
            }

            // ...and no draw order is NAMED by hand. Reading every key is not enough on its own:
            // a tile that reads baselineLabel while its caption spells one order out still renames
            // itself the moment a constant moves, and the key check cannot see a literal. So the
            // names live only in WithdrawalStrategyComparison::label(), which is what card 0075
            // has to change and nothing else.
            foreach ($this->candidates() as $candidate) {
                $phrase = implode(' ', array_slice(explode(' ', $candidate->label()), 0, 3));
                $this->assertStringNotContainsStringIgnoringCase($phrase, $source,
                    "The {$where} withdrawal panel writes the draw order \"{$phrase}\" out by hand instead of reading it from label(), so it will keep that name after the order changes.");
            }
        }
    }

    public function test_the_monte_carlo_run_records_its_seed_and_bounded_probabilities(): void
    {
        $result = (new ScenarioForecaster)->simulate($this->scenario(), nPaths: 50, seed: 7);

        $this->assertSame(50, $result->nPaths);
        $this->assertSame(7, $result->seed);
        $this->assertGreaterThanOrEqual(0.0, $result->successProbabilityEssentials);
        $this->assertLessThanOrEqual(1.0, $result->successProbabilityEssentials);
    }

    public function test_deterministic_variants_apply_the_housing_transforms_per_strategy(): void
    {
        $scenario = $this->scenario();
        $forecaster = new ScenarioForecaster;

        $variants = $forecaster->deterministicVariants($scenario);
        $this->assertSame(['stay_put', 'buy_outright', 'rent'], array_keys($variants));

        // stay_put is the raw household as entered — byte-identical to deterministic() (the
        // single source: variantInputs()['stay_put'] is the unchanged household + settings).
        $stay = $variants['stay_put'];
        $raw = $forecaster->deterministic($scenario);
        $this->assertSame($raw->finalCalendarYear, $stay->finalCalendarYear);
        $this->assertSame($raw->years[0]->totalWealth->pence, $stay->years[0]->totalWealth->pence);

        // Sell & rent owns no home: property wealth is zero in every year, so usable == total.
        $rent = $variants['rent'];
        foreach ($rent->years as $year) {
            $this->assertSame(0, $year->propertyWealth->pence, "rent kept property wealth in {$year->calendarYear}");
        }

        // Staying put keeps the home as an (illiquid) floor; selling frees its equity into
        // investments — so the sell variant carries more spendable (liquid) wealth from year 0.
        $this->assertGreaterThan(0, $stay->years[0]->propertyWealth->pence);
        $this->assertGreaterThan(
            $stay->years[0]->liquidWealth->pence,
            $rent->years[0]->liquidWealth->pence,
        );

        // Buying a cheaper home (£320k) leaves less in the home than staying put (£525k).
        $this->assertGreaterThan(0, $variants['buy_outright']->years[0]->propertyWealth->pence);
        $this->assertLessThan(
            $stay->years[0]->propertyWealth->pence,
            $variants['buy_outright']->years[0]->propertyWealth->pence,
        );
    }

    public function test_a_capital_receipt_reaches_the_forecast(): void
    {
        $user = User::factory()->create();
        $forecaster = new ScenarioForecaster;

        // The rich fixture carries a £90k family gift in 2029; the same scenario without it
        // must be visibly poorer — a documented receipt that did not reach the result would
        // be a silent drop (per-source completeness).
        $with = $forecaster->deterministic(ScenarioFixture::rich($user));
        $without = $forecaster->deterministic(ScenarioFixture::rich($user, ['capitalReceipts' => []]));

        $withYears = [];
        foreach ($with->years as $year) {
            $withYears[$year->calendarYear] = $year;
        }
        // Visible on the ladder in its year (real money — ±1p inflation round-trip).
        $this->assertEqualsWithDelta(90_000_00, $withYears[2029]->incomeBySource['capital_receipt']->pence, 1);
        $this->assertSame(0, $withYears[2028]->incomeBySource['capital_receipt']->pence);

        $this->assertGreaterThan(
            $without->terminalTotalWealth->pence,
            $with->terminalTotalWealth->pence,
            'the banked receipt must leave the household visibly better off',
        );
    }

    public function test_savings_drawn_to_fund_a_buy_reach_the_forecast(): void
    {
        $user = User::factory()->create();
        $forecaster = new ScenarioForecaster;

        // A £700k buy far exceeds the sale proceeds AND the fixture's £145k of liquid savings
        // (no buy mortgage configured): the savings are drained into the purchase and the
        // remainder is an unfunded gap that fails year 0 loudly — never free home equity.
        $variants = $forecaster->deterministicVariants(
            ScenarioFixture::rich($user, ['housing' => array_replace(
                BuilderStateFixture::full()['housing'], ['buyPrice' => '700000'],
            )]),
        );
        $buy = $variants['buy_outright'];
        $stay = $variants['stay_put'];

        $this->assertGreaterThan(0, $buy->years[0]->unmetSpend->pence, 'the unfunded gap surfaces as year-0 unmet spend');
        $this->assertLessThan(
            $stay->years[0]->liquidWealth->pence,
            $buy->years[0]->liquidWealth->pence,
            'the liquid savings were actually spent on the home',
        );
    }

    public function test_an_edited_assumption_reaches_the_forecast(): void
    {
        $user = User::factory()->create();
        $forecaster = new ScenarioForecaster;

        // The same household, once on the preset and once with the user's investment growth
        // cut to 0% real — the override must demonstrably change the forecast (completeness:
        // an edited assumption that did not reach the result would be a silent drop).
        $base = $forecaster->deterministic(ScenarioFixture::rich($user));
        $slowed = $forecaster->deterministic(
            ScenarioFixture::rich($user, ['assumptionOverrides' => ['investmentGrowth' => '0']]),
        );

        $this->assertLessThan(
            $base->terminalTotalWealth->pence,
            $slowed->terminalTotalWealth->pence,
            'Lower investment growth should leave less terminal wealth — the override did not reach the engine',
        );
    }

    /**
     * Board card 0038. The three uprating choices have to be three different futures by the time
     * they reach the projector, not three labels on one. The ordering is the whole point: the
     * full lock is the most generous, prices alone the least, and ending the lock in a stated
     * year lands between them.
     */
    public function test_the_chosen_state_pension_uprating_reaches_the_forecast(): void
    {
        $user = User::factory()->create();
        $forecaster = new ScenarioForecaster;

        $wealthUnder = fn (array $overrides): int => $forecaster->deterministic(
            ScenarioFixture::rich($user, ['assumptionOverrides' => $overrides]),
        )->terminalTotalWealth->pence;

        $lock = $wealthUnder([]); // the engine's default: the lock never ends
        $until = $wealthUnder(['statePensionUprating' => 'triple_lock_until', 'statePensionUpratingUntilYear' => '2035']);
        $prices = $wealthUnder(['statePensionUprating' => 'inflation']);

        $this->assertLessThan($lock, $until, 'ending the lock in 2035 must leave less than a lock that never ends');
        $this->assertLessThan($until, $prices, 'and prices alone must leave less again');
    }

    public function test_an_unreadable_uprating_choice_falls_back_to_the_engine_default(): void
    {
        // A half-filled or corrupted choice must not silently become a DIFFERENT policy: a
        // scenario stored before card 0038 carries no choice at all and has to reproduce
        // byte-identically, so anything unrecognised is the engine's own default.
        $user = User::factory()->create();
        $forecaster = new ScenarioForecaster;

        $this->assertSame(
            $forecaster->deterministic(ScenarioFixture::rich($user))->terminalTotalWealth->pence,
            $forecaster->deterministic(
                ScenarioFixture::rich($user, ['assumptionOverrides' => ['statePensionUprating' => 'nonsense']]),
            )->terminalTotalWealth->pence,
        );
    }

    public function test_the_assumption_set_carries_the_users_edits(): void
    {
        $user = User::factory()->create();
        $scenario = ScenarioFixture::rich($user, ['assumptionOverrides' => ['inflation' => '3.5']]);

        // The single resolution point hands the customised set to every consumer.
        $this->assertSame(350, (new ScenarioForecaster)->assumptions($scenario)->inflationMean->basisPoints);
    }

    public function test_buy_vs_rent_returns_all_three_variants_and_is_reproducible(): void
    {
        $scenario = $this->scenario();
        $forecaster = new ScenarioForecaster;

        $a = $forecaster->compareHousing($scenario, nPaths: 50, seed: 42);
        $b = $forecaster->compareHousing($scenario, nPaths: 50, seed: 42);

        $this->assertSame(['stay_put', 'buy_outright', 'rent'], array_keys($a));

        // Identical seed gives byte-identical aggregates (the golden-master property).
        foreach (array_keys($a) as $variant) {
            $this->assertSame(
                $a[$variant]->terminalWealthPercentiles['p50']->pence,
                $b[$variant]->terminalWealthPercentiles['p50']->pence,
                "Variant {$variant} was not reproducible",
            );
        }
    }

    public function test_a_forced_sale_scenario_carries_the_post_sale_rent_and_selling_costs_into_settings(): void
    {
        $user = User::factory()->create();

        // A home whose mortgage is called for redemption in 2030 and cannot be refinanced: the
        // projector sells it in place and the household rents, so the entered post-sale rent and
        // the selling-cost basis must reach the run settings — the projector has no HousingAction
        // to read them from, so a drop here would silently omit the rent from the forecast.
        $forced = ScenarioFixture::rich($user, [
            'variant' => 'stay_put',
            'property' => [
                'currentValue' => '525000', 'ownership' => 'mortgaged', 'everLet' => false,
                'outstandingMortgage' => '48000', 'runningCosts' => '6400', 'ownershipShare' => '100',
                'mortgageRedemptionYear' => '2030', 'mortgageMaturityAction' => 'forced_sale',
            ],
        ]);
        $settings = (new ScenarioForecaster)->settings($forced);
        $this->assertSame(1_800_000, $settings->annualRent?->pence, 'the £18k post-sale rent reaches the settings');
        $this->assertNotNull($settings->sellingCosts, 'the entered selling-cost basis reaches the settings');
        $this->assertNotNull($settings->rentInflationReal, 'rent inflation falls back to the assumption set');

        // A refinancing owner keeps the home, so no rent is charged (settings stay bare).
        $this->assertNull((new ScenarioForecaster)->settings(ScenarioFixture::rich($user))->annualRent);
    }
}
