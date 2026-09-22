<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Service\Migration\Premier\PremierException;

/**
 * Převody z PREMIER: běhy průvodce s protokolem (`premier_imports`) a mapa „co už
 * ze zálohy v MyÚčtu vzniklo" (`premier_import_map`, migrace 1859). Stejný princip jako
 * u ostatních převodů ({@see AbstractMigrationImportRepository}): mapa nese idempotenci,
 * všechno je tenantové a převod jedné firmy smí běžet jen jednou naráz.
 */
final class PremierImportRepository extends AbstractMigrationImportRepository
{
    public const KIND_PERIOD = 'period';
    public const KIND_ACCOUNT = 'account';
    public const KIND_JOURNAL_ENTRY = 'journal_entry';
    public const KIND_CLIENT = 'client';
    public const KIND_CLIENT_MATCH = 'client_match';
    public const KIND_INVOICE = 'invoice';
    public const KIND_PURCHASE_INVOICE = 'purchase_invoice';
    public const KIND_VAT_DOCUMENT = 'vat_document';
    public const KIND_CASH_REGISTER = 'cash_register';
    public const KIND_CASH_DOCUMENT = 'cash_document';
    public const KIND_TAX_RETURN = 'tax_return';
    public const KIND_SMALL_ASSET = 'small_asset';
    public const KIND_BANK_STATEMENT = 'bank_statement';
    public const KIND_BANK_TRANSACTION = 'bank_transaction';
    public const KIND_PAYMENT = 'payment';
    public const KIND_ASSET = 'asset';
    /** Počáteční stav (import roku, ve kterém rok předtím převedený nebyl - saldo z osnovy). */
    public const KIND_OPENING = 'opening';
    /** Osoba z PREMIER (`PER_MAIN.ID`, u starších verzí osobní číslo) => payroll_employees.id. */
    public const KIND_PAYROLL_EMPLOYEE = 'payroll_employee';
    /** Pracovní vztah (`PERSONAL.INTER`) => payroll_employments.id. */
    public const KIND_PAYROLL_EMPLOYMENT = 'payroll_employment';
    /** Zpracovaná mzda vztahu za měsíc (`INTER|YYYY-MM`) => payroll_employments.id. */
    public const KIND_PAYROLL_MONTH = 'payroll_month';

    protected function runsTable(): string
    {
        return 'premier_imports';
    }

    protected function mapTable(): string
    {
        return 'premier_import_map';
    }

    protected function keyColumn(): string
    {
        return 'premier_key';
    }

    protected function lockPrefix(): string
    {
        return 'premier';
    }

    protected function runMeta(array $meta): array
    {
        return [
            'agenda_ico' => self::str($meta['ico'] ?? null, 20),
            'agenda_year' => isset($meta['year']) ? (int) $meta['year'] : null,
            'program_version' => self::str($meta['program'] ?? null, 60),
            'backup_sha256' => self::str($meta['sha256'] ?? null, 64),
        ];
    }

    /** Druh převodu z protokolu; běh bez něj (starší nebo ještě běžící) je účetnictví. */
    protected function listExtraColumns(): string
    {
        return ",\n                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(protocol, '$.kind')), 'accounting') AS kind";
    }

    protected function nullableIntColumns(): array
    {
        return ['agenda_year'];
    }

    protected function mapConflict(string $kind, string $key): \RuntimeException
    {
        return new PremierException('map_conflict', "Záznam {$kind} {$key} z PREMIER už v MyÚčtu převedený je - převod se zastavil, aby nic nezdvojil.");
    }

    /**
     * Zápisy deníku období, které nezaložil tento převod - stejná kontrola jako u POHODY
     * ({@see \MyInvoice\Service\Migration\Pohoda\ChartJournalImporter::foreignEntryCount()}):
     * rekonciliace na konci převodu musí sedět jen na tom, co sama založila. Otevírací
     * zápis se nepočítá: založí ho uzávěrka převedeného minulého roku a převod roku ho
     * převezme (musí sedět na počáteční stavy z PREMIER, jinak rekonciliace neprojde).
     */
    public function foreignEntryCount(int $supplierId, int $periodId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM journal_entries e
              WHERE e.supplier_id = ? AND e.period_id = ?
                AND e.source_type NOT IN ('closing', 'fx_revaluation', 'opening')
                AND NOT EXISTS (
                    SELECT 1 FROM premier_import_map m
                     WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id
                )"
        );
        $stmt->execute([$supplierId, $periodId]);
        return (int) $stmt->fetchColumn();
    }
}
