<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Přijímá jen známý plochý snapshot dodavatele, včetně starších neúplných verzí. */
final class CompanyBackupInvoiceSupplierSnapshotContract
{
    public const REGISTRY_KEY = 'table:invoices';
    public const COLUMN = 'supplier_snapshot';

    private const TEXT_FIELDS = [
        'company_name', 'display_name', 'street', 'city', 'zip',
        'country_iso2', 'country_name_cs', 'country_name_en',
        'ic', 'dic', 'email', 'phone', 'web', 'tagline',
        'commercial_register', 'branding_profile_name', 'reply_to',
        'email_footer', 'logo_path', 'email_accent_color',
    ];

    private const BOOLEAN_FIELDS = [
        'is_vat_payer', 'is_identified', 'email_branding_enabled',
        'pdf_logo_show_name',
    ];

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [
            [
                'column' => self::COLUMN,
                'path' => ['email_profile_id'],
                'target' => 'table:email_profiles',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'nullable' => true,
                'condition' => null,
                'fallbacks' => [],
            ],
            [
                'column' => self::COLUMN,
                'path' => ['id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'nullable' => true,
                'condition' => null,
                'fallbacks' => [],
            ],
        ];
    }

    /**
     * @return null|array{supplier_id:?int,email_profile_id:?int}
     */
    public static function inspect(?string $json, int $sourceSupplierId): ?array
    {
        if ($sourceSupplierId < 1) {
            throw self::invalid('invoice_supplier_snapshot_supplier_invalid');
        }
        if ($json === null) {
            return null;
        }
        try {
            CompanyBackupLosslessJson::rewriteIntegerTokens(
                $json,
                static fn (array $_path, string $_token): null => null,
            );
            $decoded = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw self::invalid('invoice_supplier_snapshot_invalid', $e);
        }
        if (!$decoded instanceof \stdClass) {
            throw self::invalid('invoice_supplier_snapshot_invalid');
        }
        $fields = get_object_vars($decoded);
        if (!isset($fields['company_name']) || !is_string($fields['company_name'])) {
            throw self::invalid('invoice_supplier_snapshot_invalid');
        }
        foreach ($fields as $name => $value) {
            if (in_array($name, self::TEXT_FIELDS, true)) {
                if ($value !== null && !is_string($value)) {
                    throw self::invalid('invoice_supplier_snapshot_invalid');
                }
                continue;
            }
            if (in_array($name, self::BOOLEAN_FIELDS, true)) {
                if (!is_bool($value)) {
                    throw self::invalid('invoice_supplier_snapshot_invalid');
                }
                continue;
            }
            if (in_array($name, ['id', 'branding_profile_id', 'email_profile_id'], true)) {
                if ($value !== null && (!is_int($value) || $value < 1)) {
                    throw self::invalid('invoice_supplier_snapshot_invalid');
                }
                if ($name === 'id' && $value === null) {
                    throw self::invalid('invoice_supplier_snapshot_invalid');
                }
                continue;
            }
            throw self::invalid('invoice_supplier_snapshot_unsupported');
        }
        $embeddedSupplierId = $fields['id'] ?? null;
        if ($embeddedSupplierId !== null && $embeddedSupplierId !== $sourceSupplierId) {
            throw self::invalid('invoice_supplier_snapshot_supplier_mismatch');
        }
        return [
            'supplier_id' => $embeddedSupplierId,
            'email_profile_id' => $fields['email_profile_id'] ?? null,
        ];
    }

    private static function invalid(string $code, ?\Throwable $previous = null): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException($code, self::REGISTRY_KEY, self::COLUMN, $previous);
    }
}
