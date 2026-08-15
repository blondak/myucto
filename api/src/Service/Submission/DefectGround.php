<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/**
 * Které písmeno § 74 odst. 1 daňového řádu výzva uvádí.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se to rozlišuje
 * ═══════════════════════════════════════════════════════════════════════════
 * § 74 odst. 1 zák. 280/2009 Sb. — správce daně vyzve podatele, aby ve
 * stanovené lhůtě odstranil vady podání,
 *
 *   a) pro které není způsobilé k projednání,
 *   b) pro které nemůže mít předpokládané účinky pro správu daní,
 *   c) které spočívají ve skutečnosti, že podání nebylo učiněno stanoveným
 *      způsobem, a které nejsou současně vadami podle písmene a) nebo b), nebo
 *   d) které spočívají ve skutečnosti, že podání nebylo učiněno ve stanoveném
 *      formátu nebo požadované struktuře, a které nejsou současně vadami podle
 *      písmene a) nebo b).
 *
 * Následek se ale u těch čtyř písmen LIŠÍ. § 74 odst. 4: „Nebudou-li vady
 * podání **podle odstavce 1 písm. a) nebo b)** ve stanovené lhůtě odstraněny,
 * stává se podání uplynutím této lhůty neúčinným." U písmen c) a d) tedy
 * podání neúčinným NENÍ — sankcí je pokuta podle § 247a DŘ.
 *
 * Kdo to slije dohromady, buď zbytečně straší („podání propadne", i když
 * nepropadne), nebo — a to je horší — uklidňuje u vady, po které podání
 * skutečně zanikne. Proto tu {@see Unknown} existuje a proto vede na
 * {@see DefectConsequence::Unknown}: dokud nevíme, které písmeno výzva uvádí,
 * aplikace neřekne ani jedno.
 *
 * § 74 odst. 3 platí pro všechna písmena stejně: „Budou-li vady podání
 * odstraněny ve stanovené lhůtě, hledí se na podání, jako by bylo učiněno řádně
 * a včas."
 */
enum DefectGround: string
{
    /** § 74 odst. 1 písm. a) — podání není způsobilé k projednání. */
    case NotProcessable = 'a_not_processable';

    /** § 74 odst. 1 písm. b) — podání nemůže mít předpokládané účinky. */
    case NoEffects = 'b_no_effects';

    /** § 74 odst. 1 písm. c) — podání nebylo učiněno stanoveným způsobem. */
    case WrongWay = 'c_wrong_way';

    /** § 74 odst. 1 písm. d) — podání nebylo učiněno ve stanoveném formátu či struktuře. */
    case WrongFormat = 'd_wrong_format';

    /** Výzva písmeno neuvádí, nebo ho zatím nikdo nepřečetl. */
    case Unknown = 'unknown';

    public function consequence(): DefectConsequence
    {
        return match ($this) {
            self::NotProcessable, self::NoEffects => DefectConsequence::Ineffective,
            self::WrongWay, self::WrongFormat => DefectConsequence::NoIneffectiveness,
            self::Unknown => DefectConsequence::Unknown,
        };
    }
}
