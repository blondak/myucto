<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupIsdsGatewaySessionsProjection as Projection;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreOverrideSet;
use MyInvoice\Service\Backup\Company\CompanyBackupPreservedIdentifierSet;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantSecretColumnDetector;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use MyInvoice\Service\Submission\SubmissionRestoreReview;
use PHPUnit\Framework\TestCase;

final class CompanyBackupIsdsGatewaySessionsProjectionTest extends TestCase
{
    public function testKeepsEveryPersistedGatewayFieldButNotGeneratedActiveIndex(): void
    {
        self::assertSame([
            'id', 'supplier_id', 'environment', 'outbox_id', 'user_id',
            'app_token', 'state', 'concept_id', 'concept_dm_id',
            'concept_status_code', 'concept_status_message', 'payload_sha256',
            'correlation_reference', 'error_code', 'error_message',
            'expires_at', 'started_at', 'concept_pushed_at', 'finished_at',
            'row_version', 'created_at', 'updated_at',
        ], Projection::dataColumns());
        self::assertSame(['active_outbox_id'], Projection::generatedColumns());
        self::assertSame(['concept_dm_id', 'concept_id'],
            CompanyBackupPreservedIdentifierSet::fromArray(
                Projection::preservedIdentifiers(), 'table:isds_gateway_sessions',
                Projection::dataColumns(),
            )->columns);

        self::assertTrue(TenantSecretColumnDetector::matches('app_token'));
        self::assertSame(TenantSecretPolicy::NotSecret->value,
            Projection::secretPolicies()['app_token']['policy']);
        self::assertSame('isds_callback_correlation_not_authentication',
            Projection::secretPolicies()['app_token']['reason']);

        $references = CompanyBackupReferenceSet::fromArray(
            Projection::references(), 'table:isds_gateway_sessions',
        );
        self::assertSame([
            'supplier_id,outbox_id->submission_outbox:supplier_id,id',
            'supplier_id->supplier:id',
            'user_id->users:id',
        ], array_map(static fn ($reference): string =>
            $reference->signature(), $references->references));
        self::assertSame([], $references->references[2]->fallbacks);
    }

    public function testPendingSessionRequiresReviewWithoutForgingApprovalOrChangingEvidence(): void
    {
        $row = [
            'id' => 11,
            'state' => 'awaiting_approval',
            'error_code' => null,
            'error_message' => null,
            'app_token' => '1234567890',
            'outbox_id' => 25,
            'user_id' => 31,
            'concept_id' => '1234567891',
            'concept_dm_id' => null,
            'correlation_reference' => 'synthetic-correlation-1',
            'payload_sha256' => str_repeat('a', 64),
        ];
        $references = CompanyBackupReferenceSet::fromArray([], 'table:isds_gateway_sessions');
        $overrides = CompanyBackupRestoreOverrideSet::fromArray(
            Projection::restoreOverrides(), 'table:isds_gateway_sessions',
            array_keys($row), ['id'], $references,
        );
        $restored = $overrides->apply($row);
        self::assertSame('uncertain', $restored['state']);
        self::assertSame(SubmissionRestoreReview::CODE, $restored['error_code']);
        self::assertSame(SubmissionRestoreReview::MESSAGE, $restored['error_message']);
        foreach (['app_token', 'outbox_id', 'user_id', 'concept_id',
            'concept_dm_id', 'correlation_reference', 'payload_sha256'] as $column) {
            self::assertSame($row[$column], $restored[$column]);
        }
        self::assertSame('awaiting_approval', $row['state']);
    }

    public function testDraftContractParsesWithExplicitNonSecretCallbackToken(): void
    {
        $definition = new TenantDataDefinition(
            'table:isds_gateway_sessions', TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'submission',
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => Projection::secretPolicies(),
                'company_backup' => [
                    'data_columns' => Projection::dataColumns(),
                    'generated_columns' => Projection::generatedColumns(),
                    'omit_columns' => [],
                    'embedded_references' => [],
                    'references' => Projection::references(),
                    'preserved_identifiers' => Projection::preservedIdentifiers(),
                    'restore_overrides' => Projection::restoreOverrides(),
                ],
            ],
        );
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $projection->assertRuntimeSchema(
            [...Projection::dataColumns(), ...Projection::generatedColumns()],
            Projection::generatedColumns(), ['id'],
        );
        self::assertSame(TenantSecretPolicy::NotSecret,
            $projection->secretPolicies['app_token']);
        self::assertSame(['concept_dm_id', 'concept_id'],
            $projection->preservedIdentifiers->columns);
    }

    public function testProjectionIsNotActivatedWhileGlobalTokenAndOutboxReviewRemainUnresolved(): void
    {
        self::assertNull(TenantDataRegistryFactory::draftV1()->definition(
            'table:isds_gateway_sessions',
        ));
    }
}
