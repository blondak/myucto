<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Neaktivní kontrakt metadat uživatelských příloh faktur. Historická metadata
 * se zachovají; aktivace čeká na souborové cesty s přemapovaným ID faktury.
 */
final class CompanyBackupInvoiceAttachmentsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'invoice_id', 'filename', 'original_name', 'size_bytes',
            'sha256', 'mime_type', 'uploaded_by', 'uploaded_at',
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
        return [
            [
                'columns' => ['invoice_id'],
                'target' => 'table:invoices',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['uploaded_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['uploaded_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
        ];
    }
}
