<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Zápis převzatých mzdových úhrnů do `payroll_migration_reference_totals`.
 *
 * Volá se z převodu z původního systému. Je idempotentní přes UNIQUE
 * (firma, zdroj, období, vztah): opakovaný převod téhož exportu řádek přepíše,
 * nezaloží druhý. Díky tomu smí běžet i po zkoušce nanečisto.
 */
final class PayrollMigrationReferenceTotalsWriter
{
    /** Zdroje, ze kterých převzatá strana může pocházet (musí sedět na ENUM v migraci 1849). */
    public const SOURCES = ['pamica', 'pohoda', 'money_s3'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<PayrollMigrationReferenceTotals> $totals
     * @return int počet zapsaných řádků
     */
    public function store(
        int $supplierId,
        string $source,
        array $totals,
        ?string $importReference = null,
    ): int {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být zvolená.');
        }
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("Neznámý zdroj převzatých mezd: {$source}.");
        }
        if ($totals === []) {
            return 0;
        }

        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                 (supplier_id, source, period_start, external_person_ref,
                  external_relationship_ref, employee_id, employment_id,
                  gross_minor, net_minor, social_base_minor, health_base_minor,
                  employee_social_minor, employee_health_minor,
                  employer_social_minor, employer_health_minor,
                  advance_tax_minor, withholding_tax_minor, tax_bonus_minor,
                  import_reference)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 external_person_ref = VALUES(external_person_ref),
                 employee_id = VALUES(employee_id),
                 employment_id = VALUES(employment_id),
                 gross_minor = VALUES(gross_minor),
                 net_minor = VALUES(net_minor),
                 social_base_minor = VALUES(social_base_minor),
                 health_base_minor = VALUES(health_base_minor),
                 employee_social_minor = VALUES(employee_social_minor),
                 employee_health_minor = VALUES(employee_health_minor),
                 employer_social_minor = VALUES(employer_social_minor),
                 employer_health_minor = VALUES(employer_health_minor),
                 advance_tax_minor = VALUES(advance_tax_minor),
                 withholding_tax_minor = VALUES(withholding_tax_minor),
                 tax_bonus_minor = VALUES(tax_bonus_minor),
                 import_reference = VALUES(import_reference)',
        );

        $written = 0;
        foreach ($totals as $row) {
            $statement->execute([
                $supplierId,
                $source,
                $row->period . '-01',
                $row->externalPersonRef,
                $row->externalRelationshipRef,
                $row->employeeId,
                $row->employmentId,
                $row->grossMinor,
                $row->netMinor,
                $row->socialBaseMinor,
                $row->healthBaseMinor,
                $row->employeeSocialMinor,
                $row->employeeHealthMinor,
                $row->employerSocialMinor,
                $row->employerHealthMinor,
                $row->advanceTaxMinor,
                $row->withholdingTaxMinor,
                $row->taxBonusMinor,
                $importReference,
            ]);
            $written++;
        }

        return $written;
    }
}
