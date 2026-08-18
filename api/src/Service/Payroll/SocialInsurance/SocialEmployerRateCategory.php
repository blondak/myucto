<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use LogicException;

/**
 * Sazbová kategorie zaměstnavatele podle § 5a odst. 1 zák. č. 589/1992 Sb.
 *
 * Zákon dělá ze zaměstnanců TŘI vyměřovací základy zaměstnavatele — písm. a)
 * ostatní, písm. b) zdravotničtí záchranáři a členové jednotky hasičského
 * záchranného sboru podniku, písm. c) rizikové zaměstnání — a § 7 odst. 1 na
 * každý z nich pouští jinou sazbu. Kategorie proto NENÍ štítek: rozhoduje
 * o částce a drží se u vztahu, ne u osoby (jeden člověk může mít rizikový
 * i běžný vztah současně).
 *
 * `Unverified` není čtvrtá zákonná kategorie, ale stav evidence: zařazení
 * nebylo doloženo. Výpočet ho nedosazuje na `Ordinary` — hádat kategorii
 * znamená hádat sazbu a rozdíl je v roce 2026 3 až 5 procentních bodů.
 */
enum SocialEmployerRateCategory: string
{
    case Ordinary = 'ordinary';
    case RescueAndCompanyFireService = 'rescue_and_company_fire_service';
    case RiskEmployment = 'risk_employment';
    case Unverified = 'unverified';

    /**
     * Kategorie v pořadí, v jakém je vyjmenovává § 5a odst. 1. Pořadí drží
     * kanonický tvar výsledku i rozpad podání (10478 → a, 10479 → b, 10480 → c).
     *
     * @return list<self>
     */
    public static function statutoryOrder(): array
    {
        return [self::Ordinary, self::RescueAndCompanyFireService, self::RiskEmployment];
    }

    /** Klíč sazby v rulesetu — v kódu nesmí být žádné procento. */
    public function rateParameter(): string
    {
        if ($this === self::Unverified) {
            throw new LogicException('Nedoložená sazbová kategorie zaměstnavatele nemá sazbu.');
        }

        return "employer.rate.{$this->value}";
    }

    /** Písmeno § 5a odst. 1, pod kterým se kategorie vykazuje. */
    public function paragraph5aLetter(): string
    {
        return match ($this) {
            self::Ordinary => 'a',
            self::RescueAndCompanyFireService => 'b',
            self::RiskEmployment => 'c',
            self::Unverified => throw new LogicException(
                'Nedoložená sazbová kategorie zaměstnavatele nemá písmeno § 5a.',
            ),
        };
    }
}
