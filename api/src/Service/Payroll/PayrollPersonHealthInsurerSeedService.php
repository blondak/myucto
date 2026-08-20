<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;

/**
 * Zdravotní pojišťovna zvolená při zakládání zaměstnance.
 *
 * Pojišťovna je ZÁKONNÁ EVIDENCE osoby (`payroll_person_health_coverage_history`),
 * ne sloupec na kartě. Musí proto projít týmiž pravidly jako panel „Zákonná
 * evidence" — kód proti číselníku pojišťoven, návaznost intervalů, zmrazení
 * schválenou mzdou — a nechat stejnou auditní stopu. Tady se nic vlastním SQL
 * nezapisuje: staví se jen počáteční řádek a zbytek udělá
 * {@see PayrollPersonStatutoryEvidenceRepository::save()}.
 *
 * Volá se UVNITŘ transakce zakládání osoby, takže odmítnutý kód shodí celé
 * založení. Dřív to byl druhý požadavek z prohlížeče a jeho selhání skončilo
 * jen varovným toastem — zaměstnanec zůstal bez zákonné evidence pojišťovny.
 */
final class PayrollPersonHealthInsurerSeedService
{
    /**
     * Běžná česká situace, shodně s `DEFAULT_VALUES` ve
     * `web/src/pages/payroll/statutoryEvidenceForm.ts`: český režim veřejného
     * zdravotního pojištění a pojišťovna doložená registrací u ní.
     *
     * @var array<string,?string>
     */
    private const COVERAGE_DEFAULTS = [
        'jurisdiction' => 'czech_regime_verified',
        'foreign_country_code' => null,
        'jurisdiction_evidence_reference' => null,
        'insurer_status' => 'verified',
        'insurer_evidence_reference' => 'health:insurer-registration',
    ];

    public function __construct(
        private readonly PayrollPersonStatutoryEvidenceRepository $evidence,
    ) {}

    /**
     * @param string $effectiveOn den, od kterého má evidence platit; řádek
     *     začne prvním dnem jeho měsíce, protože evidence se zadává po celých
     *     měsících (viz `assertMonthAligned()` v repozitáři)
     */
    public function seed(
        int $supplierId,
        int $employeeId,
        string $insurerCode,
        string $effectiveOn,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $sections = array_fill_keys(
            PayrollPersonStatutoryEvidenceRepository::EDITABLE_SECTIONS,
            [],
        );
        $sections['health_coverages'] = [
            self::COVERAGE_DEFAULTS + [
                'insurer_code' => $insurerCode,
                'effective_from' => substr($effectiveOn, 0, 7) . '-01',
                'effective_to' => null,
                'evidence_note' => null,
            ],
        ];

        $this->evidence->save(
            $supplierId,
            $employeeId,
            ['sections' => $sections],
            $effectiveOn,
            $userId,
            $ip,
            $userAgent,
        );
    }
}
