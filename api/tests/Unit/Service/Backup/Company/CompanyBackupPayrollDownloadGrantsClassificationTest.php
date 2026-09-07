<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollDownloadGrantsClassificationTest extends TestCase
{
    /** @var array<string,string> */
    private const REASONS = [
        'payroll_document_download_grants' =>
            'ephemeral_payroll_document_download_grant',
        'payroll_payment_export_download_grants' =>
            'ephemeral_payroll_payment_export_download_grant',
        'payroll_period_export_download_grants' =>
            'ephemeral_payroll_period_export_download_grant',
        'payroll_submission_artifact_download_grants' =>
            'ephemeral_payroll_submission_artifact_download_grant',
    ];

    public function testOneTimeDownloadGrantsStayOutsideRestoredPayload(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();

        foreach (self::REASONS as $table => $reason) {
            $definition = $registry->definition('table:' . $table);
            self::assertNotNull($definition, $table);
            self::assertSame(
                TenantDataPolicy::RuntimeDerived,
                $definition->policy,
                $table,
            );
            self::assertFalse(
                $definition->policy->hasMachineDataPayload(),
                $table,
            );
            self::assertSame(['id'], $definition->details['primary_key'] ?? null);
            self::assertSame('payroll', $definition->details['feature_group'] ?? null);
            self::assertSame($reason, $definition->details['reason'] ?? null);
            self::assertSame(
                TenantSecretPolicy::OmitAndReconfigure->value,
                $definition->details['secrets']['token_hash']['policy'] ?? null,
                $table,
            );
            self::assertSame(
                'ephemeral_one_time_download_token',
                $definition->details['secrets']['token_hash']['reason'] ?? null,
                $table,
            );
            self::assertArrayNotHasKey('company_backup', $definition->details);
        }
    }
}
