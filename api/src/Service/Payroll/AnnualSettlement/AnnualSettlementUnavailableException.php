<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Roční zúčtování nelze provést a nejde o chybu vstupu od uživatele.
 *
 * Odlišuje se od `AnnualSettlementRefusedException`: tahle výjimka znamená, že
 * se rozbil PODKLAD (ruleset, kumulace, evidence), zatímco odmítnutí je řádný
 * výsledek posouzení podmínek § 38ch, který má uživateli co říct.
 */
final class AnnualSettlementUnavailableException extends \RuntimeException
{
}
