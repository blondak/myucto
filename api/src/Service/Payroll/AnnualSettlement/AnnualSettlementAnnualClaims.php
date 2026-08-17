<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Uplatňuje poplatník něco, co se uplatňuje AŽ v ročním zúčtování?
 *
 * § 38h odst. 6 vyjmenovává přesně tři skupiny, ke kterým plátce při výpočtu
 * záloh NEPŘIHLÍŽÍ a přihlédne k nim až při ročním zúčtování:
 *
 *   - nezdanitelné části základu daně podle § 15 (bezúplatná plnění, úroky
 *     z úvěru na bytovou potřebu, penzijní a životní pojištění, dlouhodobý
 *     investiční produkt, pojištění dlouhodobé péče),
 *   - sleva na dani podle § 35 odst. 4 (za zastavenou exekuci),
 *   - sleva na manžela podle § 35ba odst. 1 písm. b) a § 35bb.
 *
 * Mzdový modul pro ně nemá evidenci nároku ani doložení podle § 38l.
 * `PresentUnsupported` proto zúčtování zastaví: kdyby se spočítalo bez nich,
 * vyšel by přeplatek NIŽŠÍ, než na jaký má poplatník nárok, a zaměstnanec by
 * o rozdíl přišel, aniž by se to kdekoli projevilo.
 *
 * Od 8/2026 nese ruleset roční částku slevy na manžela, násobek u průkazu ZTP/P
 * i limit vlastního příjmu (`credit.spouse.*`, `spouse.income_limit`). Blokaci to
 * NERUŠÍ a nesmí zrušit: chybějící kus nikdy nebylo číslo, ale podmínky nároku —
 * společně hospodařící domácnost, vyživované dítě do 3 let věku podle § 35bb
 * odst. 2 písm. a) a doložení podle § 38l. Ruleset to říká nahlas parametrem
 * `credit.spouse.eligibility`, který je vedený jako ruční posouzení.
 */
enum AnnualSettlementAnnualClaims: string
{
    case Unknown = 'unknown';
    case None = 'none';
    case PresentUnsupported = 'present_unsupported';
}
