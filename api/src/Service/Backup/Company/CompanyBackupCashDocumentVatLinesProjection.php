<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce DPH řádků hotovostních dokladů. */
final class CompanyBackupCashDocumentVatLinesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'cash_document_id',
            'vat_rate',
            'base_amount',
            'vat_amount',
            'vat_classification_code',
            'vat_deduction',
            'vat_deduction_percent',
            'tax_treatment',
        ];
    }

    /**
     * Klasifikace DPH je stejně jako na ostatních dokladech měkký business kód;
     * do company registru zatím nemá jednoznačný referenční cíl.
     *
     * @return list<array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [[
            'columns' => ['cash_document_id'],
            'target' => 'table:cash_documents',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ]];
    }
}
