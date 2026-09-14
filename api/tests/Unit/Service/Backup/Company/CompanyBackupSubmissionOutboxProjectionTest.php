<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupColumnCodec;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupSubmissionIdentity;
use MyInvoice\Service\Backup\Company\CompanyBackupSubmissionOutboxProjection as Projection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\CompanyBackupSubmissionOutboxDefinition;
use MyInvoice\Service\Backup\Registry\CompanyBackupSubmissionRecipientsDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSubmissionOutboxProjectionTest extends TestCase
{
    public function testDraftCoversActualPersistedColumnsAndRemapContracts(): void
    {
        self::assertSame([
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
        ], Projection::dataColumns());
        self::assertCount(41, Projection::dataColumns());
        self::assertSame(['external_message_id', 'recipient_box_id'], Projection::preservedIdentifiers());
        self::assertSame(['idempotency_key_hash' => CompanyBackupColumnCodec::BinaryHex->value], Projection::columnCodecs());
        self::assertSame(CompanyBackupSubmissionIdentity::projection(),
            Projection::derivedHashes()[0]['projection']);
        self::assertSame('sha256_submission_outbox_v1', Projection::derivedHashes()[0]['algorithm']);
        self::assertSame(['document', 'payroll_submission', 'tax_submission'],
            array_column(Projection::polymorphicReferences()[0]['cases'], 'equals'));
        self::assertSame([
            'table:documents', 'table:payroll_submission_artifacts', 'table:tax_submissions',
        ], array_column(Projection::polymorphicReferences()[0]['cases'], 'target'));

        $definition = CompanyBackupSubmissionOutboxDefinition::definition();
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        self::assertFalse($projection->allowsDeferredUpdates);
        self::assertSame([], $projection->generatedColumns);
        self::assertSame([], $projection->omitColumns);
        self::assertSame([
            'confirmed_by->users:id', 'created_by->users:id',
            'receipt_document_id->documents:id',
            'receipt_inbox_message_id->submission_inbox_messages:id',
            'recipient_id->submission_recipients:id', 'supplier_id->supplier:id',
        ], array_map(static fn ($reference): string => $reference->signature(),
            $projection->references->references));
        self::assertSame(['recipient_id'],
            $projection->references->references[4]->nullableColumns);
        self::assertSame([
            CompanyBackupReferenceConstraint::Required,
            CompanyBackupReferenceConstraint::Required,
            CompanyBackupReferenceConstraint::Optional,
            CompanyBackupReferenceConstraint::Required,
            CompanyBackupReferenceConstraint::Required,
            CompanyBackupReferenceConstraint::Required,
        ], array_map(static fn ($reference) => $reference->constraint,
            $projection->references->references));
        self::assertNull(TenantDataRegistryFactory::draftV1()->definition(Projection::REGISTRY_KEY));
    }

    public function testProjectionChecksSyntheticRuntimeSchemaAndAllTargetKinds(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition(
            CompanyBackupSubmissionOutboxDefinition::definition(),
        );
        $projection->assertRuntimeSchema(
            Projection::dataColumns(), [], ['id'], ['idempotency_key_hash'],
        );
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $definitions = [
            CompanyBackupSubmissionRecipientsDefinition::definition(),
            $this->target('supplier', TenantDataPolicy::TenantRoot),
            $this->target('users', TenantDataPolicy::InstanceOwned),
            $this->target('documents', TenantDataPolicy::TenantOwned),
            $this->target('submission_inbox_messages', TenantDataPolicy::TenantOwned),
            $this->target('payroll_submission_artifacts', TenantDataPolicy::TenantOwned),
            $this->target('tax_submissions', TenantDataPolicy::TenantOwned),
        ];
        $projection->assertRegistryTargets(new TenantDataRegistry(1, $definitions, [$profile]));
        $runtimeForeignKeys = [
            new CompanyBackupForeignKey(['confirmed_by'], 'users', ['id']),
            new CompanyBackupForeignKey(['created_by'], 'users', ['id']),
            new CompanyBackupForeignKey(['receipt_inbox_message_id'], 'submission_inbox_messages', ['id']),
            new CompanyBackupForeignKey(['recipient_id'], 'submission_recipients', ['id']),
            new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
        ];
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['recipient_id', 'confirmed_by', 'created_by',
                'receipt_document_id', 'receipt_inbox_message_id'],
            $runtimeForeignKeys,
        ));
        self::assertSame(Projection::dataColumns(), $projection->dataColumns);

        try {
            $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
                ['recipient_id', 'confirmed_by', 'created_by',
                    'receipt_document_id', 'receipt_inbox_message_id'],
                array_values(array_filter($runtimeForeignKeys,
                    static fn (CompanyBackupForeignKey $key): bool => $key->columns !== ['receipt_inbox_message_id'])),
            ));
            self::fail('Skutečný FK příchozí zprávy musí zůstat povinný.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_constraint_missing', $e->errorCode);
            self::assertSame('receipt_inbox_message_id', $e->column);
        }
    }

    public function testReceiptMatchedByIsOnlyExactNonActorException(): void
    {
        $definition = CompanyBackupSubmissionOutboxDefinition::definition();
        $details = $definition->details;
        $details['company_backup']['references'] = array_values(array_filter(
            Projection::references(),
            static fn (array $reference): bool => $reference['columns'] !== ['created_by'],
        ));
        try {
            CompanyBackupTableProjection::fromDefinition(new TenantDataDefinition(
                $definition->key, $definition->kind, $definition->policy,
                $definition->profiles, $details,
            ));
            self::fail('created_by zůstává skutečnou referencí na uživatele.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_column_unclassified', $e->errorCode);
            self::assertSame('created_by', $e->column);
        }
        try {
            CompanyBackupReferenceSet::fromArray([], 'table:other_outbox')
                ->assertProjectionColumns(['id', 'receipt_matched_by']);
            self::fail('Výjimka se nesmí přenést na jinou tabulku.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_column_unclassified', $e->errorCode);
            self::assertSame('receipt_matched_by', $e->column);
        }
    }

    private function target(string $name, TenantDataPolicy $policy): TenantDataDefinition
    {
        return new TenantDataDefinition('table:' . $name, TenantDataObjectKind::Table,
            $policy, [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            ['primary_key' => ['id']]);
    }
}
