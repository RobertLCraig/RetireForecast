<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Iht;

use RetireForecast\FinanceEngine\Dto\ResidenceDisposal;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Inheritance Tax on an estate, with the residence nil-rate band and spousal
 * transfer of unused bands.
 *
 * A home SOLD during the plan does not throw its band away: pass the disposal as
 * $formerResidenceDisposal and the lost part of the band comes back as the downsizing addition
 * ({@see downsizingAddition}). Without it, every plan this tool exists to compare against staying
 * put was penalised by tax the statute is written to prevent.
 *
 * Pass $nilRateBandMultiplier = 2 for the second death of a couple, where both
 * partners' unused nil-rate bands are available. The residence band applies only to
 * the value of a home passing to direct descendants, tapers away above the £2m
 * estate threshold, and is capped at that home value.
 *
 * The $includePensionsInEstate flag models the April 2027 change: with it on,
 * unused pension pots are added to the estate, which is the crux of the spend-vs-
 * preserve decision the IHT toggle surfaces.
 */
final class InheritanceTaxCalculator
{
    /**
     * The downsizing addition is available only where the former residence was disposed of ON OR
     * AFTER 8 July 2015, the date the residence nil-rate band was announced. Held as a YEAR
     * because {@see ResidenceDisposal} carries no month: the engine models no disposal before its
     * own base year, so this can never be the deciding test and a year is enough to state the
     * rule. A disposal IN 2015 is excluded rather than admitted, which is the cautious reading of
     * a date the model cannot resolve.
     *
     * source: Inheritance Tax Act 1984 ss.8FA to 8FE (inserted by Finance Act 2016 s.93 and Sch.15),
     * and gov.uk "Inheritance Tax: residence nil rate band" downsizing guidance,
     * https://www.gov.uk/guidance/inheritance-tax-residence-nil-rate-band
     * verified_on: NOT VERIFIED. This build had no web access, so the rule and the date are
     * STATED from the statute, not checked against a live page. See docs/spec/ASSUMPTIONS.md §26
     * and board card 0125.
     */
    public const DOWNSIZING_DISPOSALS_AFTER_YEAR = 2015;

    /**
     * The STATUTORY LEGACY (the "fixed net sum"): what a surviving spouse or civil partner takes
     * off the top of an intestate estate before the residue is halved with the children. £322,000
     * for deaths on or after 26 July 2023. Held in pence, the engine's money unit.
     *
     * England, Wales and Northern Ireland only, which is the one region this engine models an
     * estate for (Scotland throws before it reaches here, and its prior rights and legal rights are
     * a different scheme entirely). Northern Ireland's figure differs from the England and Wales
     * one; the engine bundles the two regions, so a Northern Irish household is modelled on the
     * England and Wales sum — carded, not silently assumed.
     *
     * The sum is FROZEN here rather than uprated, which is the cautious direction: a legacy that
     * does not rise leaves MORE in the residue, so more falls to the children, so more is
     * chargeable on the first death.
     *
     * source: Administration of Estates Act 1925 s.46(1)(i), as amended by the Administration of
     * Estates Act 1925 (Fixed Net Sum) Order 2023 (SI 2023/758),
     * https://www.legislation.gov.uk/uksi/2023/758/made
     * verified_on: NOT VERIFIED. This build had no web access, so the figure and its start date are
     * STATED from the instrument, not checked against a live page. See docs/spec/ASSUMPTIONS.md §27
     * and board card 0127.
     */
    public const STATUTORY_LEGACY_PENCE = 322_000_00;

    /**
     * The member's age at death from which an inherited pension fund is taxable as the
     * BENEFICIARY's own pension income. Die before it and the death benefits are paid out free of
     * income tax; die at it or after and every pound the beneficiary draws is taxed at their
     * marginal rate. The April 2027 Inheritance Tax change does not displace that charge — a pot
     * passing to a working-age child suffers both.
     *
     * source: Finance Act 2004 s.579A and Sch.28, as amended by the Taxation of Pensions Act 2014
     * (the "age 75" dividing line for beneficiary drawdown and lump-sum death benefits),
     * https://www.gov.uk/hmrc-internal-manuals/pensions-tax-manual/ptm073010
     * verified_on: NOT VERIFIED. This build had no web access, so the rule is STATED from the
     * legislation, not checked against a live page. See docs/spec/ASSUMPTIONS.md §30 and board
     * card 0057.
     */
    public const BENEFICIARY_TAXED_FROM_AGE = 75;

    /**
     * The marginal rate the BENEFICIARY is assumed to pay on what they draw, where nobody has said
     * otherwise: 40%, the higher rate. It is the adverse plausible answer for the household this
     * tool models, and adverse is the standing direction to default in. A pot of this size drawn on
     * top of a working-age child's own salary lands in the higher-rate band on almost any drawdown
     * pattern, and the alternative — assuming they take it slowly enough to stay basic-rate — would
     * halve the modelled cost of preserving the pot on exactly the comparison the Inheritance Tax
     * toggle exists to run. The additional rate (45%) is more adverse still but applies only above
     * £125,140 of income, which is a small minority; it is offered as a choice rather than assumed.
     *
     * The reader can edit it (it rides on the run settings as `beneficiaryMarginalRate`), and it is
     * disclosed as an assumed figure whenever they have not.
     *
     * source: Income Tax Act 2007 s.10 (the higher rate for England, Wales and Northern Ireland),
     * https://www.gov.uk/income-tax-rates
     * verified_on: NOT VERIFIED. See docs/spec/ASSUMPTIONS.md §30 and board card 0057.
     */
    public const DEFAULT_BENEFICIARY_MARGINAL_RATE_BPS = 4000;

    public function __construct(private readonly TaxYearConfig $config) {}

    /**
     * The FIRST death of a couple: what passes to the survivor, what is chargeable, and the tax.
     *
     * Three things decide the exempt share, and until board card 0054 the model asked about none
     * of them and simply granted the whole estate:
     *
     *  - **Is there a surviving spouse or civil partner?** A cohabiting partner attracts no
     *    exemption at all, so the whole estate is chargeable.
     *  - **Is there a will?** Without one the estate passes under intestacy, and a spouse does not
     *    take everything: {@see intestacySpouseShare}. The children's half of the residue is a
     *    chargeable transfer.
     *  - **Is the survivor a UK long-term resident?** If not, the exemption stops at the nil-rate
     *    band unless the couple elect otherwise (IHTA 1984 s.18(2)).
     *
     * The residence nil-rate band is deliberately NOT claimed here even where the children take a
     * share of the home under intestacy. It is the adverse reading of a split this engine cannot
     * see the shape of (it holds no per-asset destination, only a household-level "the home goes to
     * the children"), and it keeps the whole residence band available to transfer to the second
     * death, where the home actually is.
     *
     * $nilRateBandUsed on the result is the band this death CONSUMED, not the band it had — the
     * caller subtracts it from the second death's doubled band, which is what stops an intestacy
     * split handing the same nil-rate band out twice.
     */
    public function computeFirstDeath(
        Money $estateExcludingPensions,
        Money $unusedPensionValue,
        bool $includePensionsInEstate,
        bool $spouseSurvives,
        bool $deceasedLeftAWill,
        bool $issueTakeUnderIntestacy,
        bool $survivorIsUkLongTermResident,
        ?Money $pensionNominatedToSpouse = null,
        bool $deceasedDiedAtOrAfter75 = false,
        ?Percent $beneficiaryMarginalRate = null,
    ): IhtResult {
        $params = $this->config->iht;

        $pensionsInEstate = $includePensionsInEstate ? $unusedPensionValue : Money::zero();
        $totalEstate = $estateExcludingPensions->plus($pensionsInEstate);

        // A pension death benefit passes under the SCHEME's discretion, following the member's
        // expression of wish — not under the will and not under the intestacy rules. So the pot is
        // held out of the will/intestacy split entirely and exempted only to the extent it is
        // nominated to the surviving spouse or civil partner. Null (nobody was asked) means none of
        // it is, which is the adverse answer and the one this engine defaults to.
        $nominatedToSpouse = $spouseSurvives
            ? Money::min($pensionNominatedToSpouse ?? Money::zero(), $unusedPensionValue)
            : Money::zero();
        $pensionToSpouse = Money::min($nominatedToSpouse, $pensionsInEstate);

        $intestate = $spouseSurvives && ! $deceasedLeftAWill && $issueTakeUnderIntestacy;
        $nonPensionEstate = $estateExcludingPensions;
        $passingToSpouse = match (true) {
            ! $spouseSurvives => Money::zero(),
            $intestate => $this->intestacySpouseShare($nonPensionEstate)->plus($pensionToSpouse),
            default => $nonPensionEstate->plus($pensionToSpouse),
        };

        $capped = ! $survivorIsUkLongTermResident && $passingToSpouse->greaterThan($params->nilRateBand);
        $exempt = $capped ? $params->nilRateBand : $passingToSpouse;

        $chargeable = $totalEstate->minus($exempt)->minZero();
        $nilRateBandUsed = Money::min($chargeable, $params->nilRateBand);
        $taxableEstate = $chargeable->minus($params->nilRateBand)->minZero();

        $warnings = [];
        if ($chargeable->isZero() && $exempt->isPositive()) {
            $warnings[] = new Warning(
                WarningCode::IHT_SPOUSE_EXEMPTION,
                'Everything passes to the surviving spouse or civil partner, so no Inheritance Tax '
                .'is due on the first death (the spouse exemption); their unused allowances carry over.',
            );
        }
        if ($intestate) {
            $warnings[] = new Warning(
                WarningCode::IHT_INTESTACY,
                'We have assumed there is NO will, because you have not told us there is one. Without a '
                .'will the estate passes under the intestacy rules, and a husband, wife or civil partner '
                .'does not inherit everything: they take the personal belongings, the first '
                .Money::fromPence(self::STATUTORY_LEGACY_PENCE)->format().' and half of what is left, and '
                .'the children take the other half — '
                .$nonPensionEstate->minus($this->intestacySpouseShare($nonPensionEstate))->format().' here. '
                .'That half is not covered by the spouse exemption, so it is taxed and it uses up part of '
                .'the allowance that would otherwise have passed to the survivor. Making a will is the one '
                .'change that undoes this.',
            );
        }
        if ($capped) {
            $warnings[] = new Warning(
                WarningCode::IHT_SPOUSE_EXEMPTION_CAPPED,
                'You told us the surviving husband, wife or civil partner is not a UK long-term resident, '
                .'so the amount that can pass to them free of Inheritance Tax is capped at '
                .$params->nilRateBand->format().' rather than being unlimited. A couple can elect to be '
                .'treated as if they were UK long-term resident, which removes the cap but brings their '
                .'worldwide assets into charge; that election is not modelled here.',
            );
        }
        if ($pensionsInEstate->isPositive()) {
            $warnings[] = new Warning(
                WarningCode::IHT_PENSIONS_IN_ESTATE,
                'Unused pension funds of '.$pensionsInEstate->format().' are included in the '
                .'estate for Inheritance Tax (the rule due from April 2027), increasing the '
                .'taxable estate.',
            );
        }

        $tax = $taxableEstate->applyRate($params->rate);
        $beneficiaryRate = $beneficiaryMarginalRate
            ?? Percent::fromBasisPoints(self::DEFAULT_BENEFICIARY_MARGINAL_RATE_BPS);
        // Only the part LEAVING the household is charged here. A pot nominated to the surviving
        // spouse is inherited by somebody the projector goes on modelling, and it taxes every
        // withdrawal they make from it year by year, so restating that here would count the same
        // income tax twice.
        $leavingTheHousehold = $unusedPensionValue->minus($nominatedToSpouse)->minZero();
        $beneficiaryIncomeTax = $this->beneficiaryIncomeTax(
            $leavingTheHousehold,
            $pensionsInEstate->minus($pensionToSpouse)->minZero(),
            $chargeable,
            $tax,
            $deceasedDiedAtOrAfter75,
            $beneficiaryRate,
        );
        if ($beneficiaryIncomeTax->isPositive()) {
            $warnings[] = $this->beneficiaryIncomeTaxWarning($leavingTheHousehold, $beneficiaryIncomeTax, $beneficiaryRate);
        }

        return new IhtResult(
            totalEstate: $totalEstate,
            nilRateBandUsed: $nilRateBandUsed,
            residenceNilRateBandUsed: Money::zero(),
            taxableEstate: $taxableEstate,
            rate: $params->rate,
            tax: $tax,
            pensionsIncluded: $includePensionsInEstate,
            warnings: $warnings,
            downsizingAddition: Money::zero(),
            unusedPensionPassing: $leavingTheHousehold,
            beneficiaryIncomeTax: $beneficiaryIncomeTax,
            beneficiaryMarginalRate: $beneficiaryIncomeTax->isPositive() ? $beneficiaryRate : null,
        );
    }

    /**
     * What a surviving spouse or civil partner takes from an INTESTATE estate that also has issue:
     * the statutory legacy off the top, plus half of whatever is left. The children take the other
     * half. Personal chattels also pass to the spouse absolutely and are ignored here because this
     * engine models no chattels — an omission that leaves the spouse's share LOWER, which is the
     * cautious direction.
     *
     * An estate below the statutory legacy passes wholly to the spouse, so the exemption is again
     * total and this returns the whole estate.
     */
    private function intestacySpouseShare(Money $estate): Money
    {
        $legacy = Money::fromPence(self::STATUTORY_LEGACY_PENCE);

        if ($estate->lessThanOrEqual($legacy)) {
            return $estate;
        }

        return $legacy->plus($estate->minus($legacy)->dividedBy(2));
    }

    public function compute(
        Money $estateExcludingPensions,
        Money $unusedPensionValue,
        bool $includePensionsInEstate,
        Money $homePassingToDescendants,
        int $nilRateBandMultiplier = 1,
        ?ResidenceDisposal $formerResidenceDisposal = null,
        ?Money $nilRateBandUsedAtFirstDeath = null,
        bool $deceasedDiedAtOrAfter75 = false,
        ?Percent $beneficiaryMarginalRate = null,
    ): IhtResult {
        $params = $this->config->iht;

        $pensionsInEstate = $includePensionsInEstate ? $unusedPensionValue : Money::zero();
        $totalEstate = $estateExcludingPensions->plus($pensionsInEstate);

        // Only the UNUSED part of the first death's nil-rate band transfers. It is nil where that
        // death was wholly spouse-exempt, which is the case a doubled band was written for; where
        // an intestacy split made part of the estate chargeable, that part has already spent some
        // of the band and cannot spend it again here. Subtracting pence is exact, and the statute's
        // percentage form gives the same answer while one TaxYearConfig, with a band frozen in cash
        // terms, is in force for the whole run.
        $nrb = $params->nilRateBand->times($nilRateBandMultiplier)
            ->minus($nilRateBandUsedAtFirstDeath ?? Money::zero())
            ->minZero();

        // Residence band: tapered above the £2m threshold, then capped at the value
        // of the home actually passing to direct descendants.
        $rnrbBase = $params->residenceNilRateBand->times($nilRateBandMultiplier);
        $taperReduction = $totalEstate
            ->minus($params->residenceNilRateBandTaperThreshold)
            ->minZero()
            ->applyRate($params->taperRate, RoundingMode::Floor);
        $rnrbAfterTaper = $rnrbBase->minus($taperReduction)->minZero();
        $downsizingAddition = $this->downsizingAddition(
            $formerResidenceDisposal,
            $homePassingToDescendants,
            $rnrbBase,
            $totalEstate->minus($homePassingToDescendants)->minZero(),
        );
        $rnrb = Money::min($rnrbAfterTaper, $homePassingToDescendants->plus($downsizingAddition));

        $taxableEstate = $totalEstate->minus($nrb)->minus($rnrb)->minZero();
        $tax = $taxableEstate->applyRate($params->rate);

        $warnings = [];
        if ($downsizingAddition->isPositive()) {
            $warnings[] = new Warning(
                WarningCode::IHT_DOWNSIZING_ADDITION,
                'A former home was sold during this plan, so '.$downsizingAddition->format().' of the '
                .'residence nil-rate band it would have sheltered is added back (the downsizing '
                .'addition). Without it, selling the home would throw the band away.',
            );
        }
        if ($pensionsInEstate->isPositive()) {
            $warnings[] = new Warning(
                WarningCode::IHT_PENSIONS_IN_ESTATE,
                'Unused pension funds of '.$pensionsInEstate->format().' are included in the '
                .'estate for Inheritance Tax (the rule due from April 2027), increasing the '
                .'taxable estate.',
            );
        }

        // The final death has no surviving spouse, so nothing is exempt and the whole estate bears
        // the tax rateably: the pot's share of it is its share of the estate.
        $beneficiaryRate = $beneficiaryMarginalRate
            ?? Percent::fromBasisPoints(self::DEFAULT_BENEFICIARY_MARGINAL_RATE_BPS);
        $beneficiaryIncomeTax = $this->beneficiaryIncomeTax(
            $unusedPensionValue,
            $pensionsInEstate,
            $totalEstate,
            $tax,
            $deceasedDiedAtOrAfter75,
            $beneficiaryRate,
        );
        if ($beneficiaryIncomeTax->isPositive()) {
            $warnings[] = $this->beneficiaryIncomeTaxWarning($unusedPensionValue, $beneficiaryIncomeTax, $beneficiaryRate);
        }

        return new IhtResult(
            totalEstate: $totalEstate,
            nilRateBandUsed: $nrb,
            residenceNilRateBandUsed: $rnrb,
            taxableEstate: $taxableEstate,
            rate: $params->rate,
            tax: $tax,
            pensionsIncluded: $includePensionsInEstate,
            warnings: $warnings,
            downsizingAddition: $downsizingAddition,
            unusedPensionPassing: $unusedPensionValue,
            beneficiaryIncomeTax: $beneficiaryIncomeTax,
            beneficiaryMarginalRate: $beneficiaryIncomeTax->isPositive() ? $beneficiaryRate : null,
        );
    }

    /**
     * The BENEFICIARY's income tax on drawing an inherited pension fund, where the member died at
     * or after {@see BENEFICIARY_TAXED_FROM_AGE}. This is the SECOND of the two charges on the same
     * money, and until board card 0057 the tool showed only the first.
     *
     * It is charged on what the beneficiary can actually draw, so the Inheritance Tax the pot
     * itself bears comes off first. That share is apportioned rateably: the pot's part of the
     * CHARGEABLE estate, because an exempt part of an estate bears none of the tax. A pot outside
     * the estate (a death before April 2027) bears no Inheritance Tax at all and is charged whole.
     *
     * The result is the effective rate the estate planner's finding names: 40% Inheritance Tax and
     * then 40% income tax on what is left is 64% of the pot, not 40%.
     */
    private function beneficiaryIncomeTax(
        Money $potPassing,
        Money $chargeablePension,
        Money $chargeableEstate,
        Money $tax,
        bool $deceasedDiedAtOrAfter75,
        Percent $rate,
    ): Money {
        if (! $deceasedDiedAtOrAfter75 || ! $potPassing->isPositive()) {
            return Money::zero();
        }

        $borneByThePot = $chargeableEstate->isPositive() && $tax->isPositive()
            ? Money::fromPence((int) round($tax->pence * ($chargeablePension->pence / $chargeableEstate->pence)))
            : Money::zero();

        return $potPassing->minus($borneByThePot)->minZero()->applyRate($rate);
    }

    private function beneficiaryIncomeTaxWarning(Money $pot, Money $incomeTax, Percent $rate): Warning
    {
        $pct = rtrim(rtrim(number_format($rate->asPercent(), 2), '0'), '.');

        return new Warning(
            WarningCode::IHT_INHERITED_PENSION_INCOME_TAX,
            'The unused pension of '.$pot->format().' is taxed a SECOND time. Because the member is '
            .'modelled as dying at or after '.self::BENEFICIARY_TAXED_FROM_AGE.', whoever inherits it '
            .'pays their own income tax on every pound they take out of it, on top of any Inheritance '
            ."Tax the estate paid. At an assumed {$pct} that is ".$incomeTax->format().'. Inheritance '
            .'Tax and income tax together can take around two thirds of a pot left to a working-age '
            .'child, which is the figure to weigh against spending it.',
        );
    }

    /**
     * The downsizing addition: the part of the residence nil-rate band a DISPOSAL of a former home
     * cost the estate, given back.
     *
     * The band a home can use is capped at its own value, so a home worth less than the maximum
     * band only ever used part of it. The statute measures what a disposal lost by comparing the
     * band the former home would have used against the band the home left at death actually uses,
     * and restores the difference:
     *
     *   lost = min(disposal value, maximum band) - min(home at death, maximum band)
     *
     * The statute expresses that as PERCENTAGES of the maximum band at each of the two dates,
     * which matters when the band differs between them. Here it cannot: one {@see TaxYearConfig}
     * is in force for a whole run and the band is frozen in cash terms, so the percentage form and
     * this subtraction are the same number, and the subtraction is exact in pence where a
     * percentage would round twice.
     *
     * The addition is then capped at the value of everything OTHER than the home that passes to
     * direct descendants: the band shelters what is actually inherited, so an estate that sold the
     * house and spent the money has nothing left for the addition to work on.
     *
     * The TAPER is deliberately NOT applied here. The caller applies it to the home and the
     * addition TOGETHER, as a ceiling on the two, because the statute tapers the allowance and
     * then limits the band to what is inherited, not the other way round. Tapering the addition on
     * its own would hand a £2m-plus estate a band it is not entitled to.
     */
    private function downsizingAddition(
        ?ResidenceDisposal $disposal,
        Money $homePassingToDescendants,
        Money $maximumBand,
        Money $otherAssetsPassingToDescendants,
    ): Money {
        if ($disposal === null || $disposal->year <= self::DOWNSIZING_DISPOSALS_AFTER_YEAR) {
            return Money::zero();
        }

        $bandTheFormerHomeUsed = Money::min($disposal->netValue, $maximumBand);
        $bandTheCurrentHomeUses = Money::min($homePassingToDescendants, $maximumBand);
        $lost = $bandTheFormerHomeUsed->minus($bandTheCurrentHomeUses)->minZero();

        return Money::min($lost, $otherAssetsPassingToDescendants);
    }
}
