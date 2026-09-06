<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use LogicException;
use OutOfRangeException;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicPathDraws;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PathProjector;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\SampledPathDraws;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The no-silent-failure rule applied to the engine's own backstops. Each of these guarded a case
 * that cannot happen if the engine is right, and each carried on with a made-up answer when it
 * did: a truncated projection returned as a complete one, a draw repeated for ever past the end of
 * a sampled series. A backstop that invents a number hides the fault it was there to catch, so a
 * broken invariant throws and says which one.
 */
final class BrokenInvariantThrowsTest extends TestCase
{
    public function test_a_projection_that_runs_past_its_backstop_throws_rather_than_reporting_a_truncated_one(): void
    {
        // A person who never dies. The loop ends on the last death, so with mortality out of the
        // way it runs to the backstop, which used to `break` and hand back the years it had got
        // through as though the projection were finished.
        $household = new Household(
            'Immortal', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Male, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(70)),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(50_000))],
        );
        $set = AssumptionSetLibrary::default();
        $draws = new DeterministicPathDraws($set, PortfolioAllocation::cautious40_60(), ['p1' => 400]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/backstop/i');

        (new PathProjector(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi)))
            ->project($household, new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'), $draws);
    }

    public function test_a_sampled_draw_past_the_end_of_its_series_throws_rather_than_repeating_the_last(): void
    {
        // The sampled series is generated for a known number of years. A projector year beyond it
        // is an engine fault, not a data shortage, and repeating the final draw for ever answered
        // it with a number nobody generated.
        $draws = new SampledPathDraws(
            [
                'investment' => [0.04, 0.05],
                'cash' => [0.01, 0.01],
                'inflation' => [0.02, 0.02],
                'house' => [0.02, 0.02],
                'salary' => [0.01, 0.01],
            ],
            AssumptionSetLibrary::default(),
            ['p1' => 90],
        );

        $this->assertSame(0.05, $draws->investmentRealReturn(1), 'the last generated year still reads');

        $this->expectException(OutOfRangeException::class);
        $draws->investmentRealReturn(2);
    }
}
