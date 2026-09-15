<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Neaktivní kontrakt historie PDF vydaných faktur. `sent_to` zůstává uložený
 * JSON text nebo NULL; příjemci a auditní historie se při obnově nemění.
 * Aktivace faktur čeká na úplný souborový kontrakt archivovaných PDF.
 */
final class CompanyBackupInvoicePdfsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'invoice_id', 'filename', 'size_bytes', 'sha256',
            'was_sent', 'sent_to', 'reason', 'archived_at',
        ];
    }

    /**
     * @return list<array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [[
            'columns' => ['invoice_id'],
            'target' => 'table:invoices',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ]];
    }
}
