<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Service\Migration\Pohoda\PohodaException;

/**
 * Převody z POHODY: běhy průvodce s protokolem (`pohoda_imports`) a mapa „co už
 * z exportu v MyÚčtu vzniklo" (`pohoda_import_map`, migrace 1844). Stejný princip jako
 * u ostatních převodů ({@see AbstractMigrationImportRepository}): mapa nese idempotenci,
 * všechno je tenantové a převod jedné firmy smí běžet jen jednou naráz.
 */
final class PohodaImportRepository extends AbstractMigrationImportRepository
{
    public const KIND_PERIOD = 'period';
    public const KIND_ACCOUNT = 'account';
    public const KIND_JOURNAL_ENTRY = 'journal_entry';
    public const KIND_CLIENT = 'client';
    public const KIND_CLIENT_MATCH = 'client_match';
    public const KIND_POSTING_RULE = 'posting_rule';
    public const KIND_INVOICE = 'invoice';
    public const KIND_PURCHASE_INVOICE = 'purchase_invoice';
    public const KIND_CASH_REGISTER = 'cash_register';
    public const KIND_CASH_DOCUMENT = 'cash_document';
    public const KIND_BANK_STATEMENT = 'bank_statement';
    public const KIND_BANK_TRANSACTION = 'bank_transaction';
    public const KIND_PAYMENT = 'payment';
    /**
     * Úhrada, kterou převod odvodil u pohybu bez zápisu v deníku POHODY
     * ({@see \MyInvoice\Service\Migration\Pohoda\UnbookedBankPayments}): `tx|id pohybu` => payment_matches.id.
     */
    public const KIND_DERIVED_MATCH = 'derived_match';
    /** Zápis odvozené úhrady a jeho storno: `entry|pohyb|zápis`, `reversal|pohyb|zápis` => journal_entries.id. */
    public const KIND_DERIVED_ENTRY = 'derived_entry';
    public const KIND_ASSET = 'asset';
    public const KIND_SMALL_ASSET = 'small_asset';
    /** Převedený měsíc mezd: `období|otisk sešitu` => id dávky importu docházky. */
    public const KIND_PAYROLL_MONTH = 'payroll_month';
    /** Převedená trvalá srážka: reference srážky v PAMICA => id případu nebo dohody. */
    public const KIND_PAYROLL_DEDUCTION = 'payroll_deduction';

    protected function runsTable(): string
    {
        return 'pohoda_imports';
    }

    protected function mapTable(): string
    {
        return 'pohoda_import_map';
    }

    protected function keyColumn(): string
    {
        return 'pohoda_key';
    }

    protected function lockPrefix(): string
    {
        return 'pohoda';
    }

    protected function runMeta(array $meta): array
    {
        return [
            'agenda_ico' => self::str($meta['ico'] ?? null, 20),
            'agenda_year' => isset($meta['year']) ? (int) $meta['year'] : null,
            'pohoda_version' => self::str($meta['program'] ?? null, 60),
            'export_sha256' => self::str($meta['sha256'] ?? null, 64),
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
        return new PohodaException('map_conflict', "Záznam {$kind} {$key} z POHODY už v MyÚčtu převedený je - převod se zastavil, aby nic nezdvojil.");
    }
}
