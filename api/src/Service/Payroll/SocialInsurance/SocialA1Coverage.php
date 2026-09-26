<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

/**
 * Pokryje ověřený doklad A1 celou část měsíce, ve které věta cizí sociální
 * příslušnosti platí?
 *
 * Cizí právní předpisy platí jen po dobu platnosti A1 (čl. 19 nařízení
 * č. 987/2009). Věta příslušnosti končící v měsíci (navazuje česká
 * příslušnost) potřebuje A1 jen do svého konce. Jediné pravidlo pro mzdový
 * výpočet (PayrollRunStatutoryInputAssembler) i pro editor zákonné evidence
 * (PayrollPersonStatutoryEvidenceRepository::blockers()); oba hlásí kód
 * `social_a1_expired`.
 */
final class SocialA1Coverage
{
    /**
     * @param array<string,mixed> $jurisdictionRow normalizovaná věta příslušnosti
     *        (`effective_to`, `a1_valid_until`)
     */
    public static function coversMonth(array $jurisdictionRow, string $periodEnd): bool
    {
        $until = $jurisdictionRow['a1_valid_until'] ?? null;
        if (!is_string($until) || $until === '') {
            return false;
        }
        $rowEnd = $jurisdictionRow['effective_to'] ?? null;
        $needed = is_string($rowEnd) && $rowEnd !== '' && $rowEnd < $periodEnd ? $rowEnd : $periodEnd;

        return $until >= $needed;
    }
}
