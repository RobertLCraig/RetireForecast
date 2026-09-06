<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * The council tax valuation band a dwelling in England sits in, A to H.
 *
 * The band is not needed to charge the bill — the household enters the bill it actually
 * receives. It is needed for ONE thing: the **disabled band reduction**, which charges a
 * qualifying dwelling as if it were in the band immediately below, and how much that is
 * worth depends entirely on which band it starts in.
 *
 * Every band's charge is a fixed proportion of the band D charge, set in NINTHS: A is 6/9,
 * B 7/9, C 8/9, D 9/9, E 11/9, F 13/9, G 15/9 and H 18/9. That is why the reduction is worth
 * a different share of the bill in each band — from band D to band C is 8/9 of the bill,
 * but from band F to band E is 11/13.
 *
 * Band A has no band below it, so the regulations reduce it by one ninth of the band D charge
 * instead: 6/9 becomes 5/9, which is 5/6 of a band A bill.
 *
 * **SOURCING GAP — these proportions are STATED, not verified in this session.** The rule is
 * the statutory one (Local Government Finance Act 1992 s.5 for the ninths;
 * the Council Tax (Reductions for Disabilities) Regulations 1992 for the band-below charge and
 * the band A case), but the unattended build loop that added them had no web access, so neither
 * citation was fetched and neither carries a verified_on date. Board card 0111 carries pinning
 * them to a primary source. See docs/spec/ASSUMPTIONS.md §23.
 *
 * Scotland and Wales use the same A-H letters with their own proportions (Wales has a band I),
 * so this is the England set only — which matches the engine's existing region handling, where
 * Scotland throws rather than guessing.
 */
enum CouncilTaxBand: string
{
    case A = 'a';
    case B = 'b';
    case C = 'c';
    case D = 'd';
    case E = 'e';
    case F = 'f';
    case G = 'g';
    case H = 'h';

    /** This band's charge as a number of ninths of the band D charge. */
    public function ninths(): int
    {
        return match ($this) {
            self::A => 6,
            self::B => 7,
            self::C => 8,
            self::D => 9,
            self::E => 11,
            self::F => 13,
            self::G => 15,
            self::H => 18,
        };
    }

    /**
     * The ninths charged once the disabled band reduction applies: the band below's own ninths,
     * or — for band A, which has nothing below it — one ninth less than its own.
     */
    public function reducedNinths(): int
    {
        return match ($this) {
            self::A => 5,
            self::B => self::A->ninths(),
            self::C => self::B->ninths(),
            self::D => self::C->ninths(),
            self::E => self::D->ninths(),
            self::F => self::E->ninths(),
            self::G => self::F->ninths(),
            self::H => self::G->ninths(),
        };
    }

    /** The band's letter, as a reader would say it ("D"). */
    public function label(): string
    {
        return strtoupper($this->value);
    }
}
