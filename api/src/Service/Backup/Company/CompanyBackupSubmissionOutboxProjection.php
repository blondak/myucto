<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Kontrakt fronty podání; aktivace vyžaduje projekce všech artefaktů a důkazů. */
final class CompanyBackupSubmissionOutboxProjection
{
    public const REGISTRY_KEY = 'table:submission_outbox';

    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'supplier_id', 'environment', 'channel', 'dispatch_mode',
            'agenda_code', 'recipient_id', 'recipient_box_id', 'subject',
            'artifact_kind', 'artifact_id', 'artifact_filename', 'artifact_sha256',
            'dispatch_state', 'acceptance_state', 'acceptance_evidence_kind',
            'acceptance_note', 'idempotency_key_hash', 'correlation_reference',
            'external_message_id', 'artifact_validation_status',
            'artifact_validated_at', 'recipient_box_verified_at', 'confirmed_by',
            'confirmed_at', 'sent_at', 'delivered_at', 'accepted_at', 'rejected_at',
            'failed_at', 'last_error_code', 'last_error_message',
            'receipt_document_id', 'receipt_signature_status', 'receipt_matched_by',
            'receipt_inbox_message_id', 'receipt_attached_at', 'row_version',
            'created_by', 'created_at', 'updated_at',
        ];
    }

    /** @return array<string,string> */
    public static function columnCodecs(): array
    {
        return ['idempotency_key_hash' => CompanyBackupColumnCodec::BinaryHex->value];
    }

    /** @return list<string> */
    public static function preservedIdentifiers(): array
    {
        return ['external_message_id', 'recipient_box_id'];
    }

    /** @return list<array<string,mixed>> */
    public static function references(): array
    {
        return [
            self::actor('confirmed_by'),
            self::actor('created_by'),
            // Doručenka je logická vazba; schéma 1381 ji nemá jako fyzický FK.
            self::tenant('receipt_document_id', 'documents', true, CompanyBackupReferenceConstraint::Optional),
            self::tenant('receipt_inbox_message_id', 'submission_inbox_messages', true),
            [
                'columns' => ['recipient_id'],
                'target' => 'table:submission_recipients',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantOrSystemId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['recipient_id'],
                'fallbacks' => [],
            ],
            self::tenant('supplier_id', 'supplier'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function polymorphicReferences(): array
    {
        return [[
            'column' => 'artifact_id',
            'discriminator_column' => 'artifact_kind',
            'nullable' => false,
            'cases' => [
                self::artifactCase('document', 'documents'),
                self::artifactCase('payroll_submission', 'payroll_submission_artifacts'),
                self::artifactCase('tax_submission', 'tax_submissions'),
            ],
        ]];
    }

    /** @return list<array<string,mixed>> */
    public static function derivedHashes(): array
    {
        return [[
            'algorithm' => CompanyBackupDerivedHashAlgorithm::Sha256SubmissionOutboxV1->value,
            'hash_column' => 'idempotency_key_hash',
            'nullable' => false,
            'projection' => CompanyBackupSubmissionIdentity::projection(),
        ]];
    }

    /** @return array<string,mixed> */
    private static function actor(string $column): array
    {
        return [
            'columns' => [$column], 'target' => 'table:users',
            'target_columns' => ['id'], 'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [$column], 'fallbacks' => ['null', 'restore_actor'],
        ];
    }

    /** @return array<string,mixed> */
    private static function tenant(
        string $column,
        string $target,
        bool $nullable = false,
        CompanyBackupReferenceConstraint $constraint = CompanyBackupReferenceConstraint::Required,
    ): array
    {
        return [
            'columns' => [$column], 'target' => 'table:' . $target,
            'target_columns' => ['id'], 'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => $constraint->value,
            'nullable_columns' => $nullable ? [$column] : [], 'fallbacks' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function artifactCase(string $kind, string $target): array
    {
        return [
            'base' => 0,
            'equals' => $kind,
            'mapping' => CompanyBackupPolymorphicReferenceMapping::TenantId->value,
            'multiplier' => 1,
            'slots' => [],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'transform' => CompanyBackupPolymorphicReferenceTransform::Identity->value,
        ];
    }
}
