<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company as Backup;
use PHPUnit\Framework\TestCase;

final class CompanyBackupIsdsRestorePolicyTest extends TestCase
{
    public function testOnlyActiveGatewaySessionsLoseResumableState(): void
    {
        foreach (['awaiting_login', 'awaiting_approval', 'approved', 'rejected', 'failed', 'uncertain', 'expired'] as $state) {
            $row = ['id' => 11, 'state' => $state, 'error_code' => null, 'error_message' => null,
                'concept_dm_id' => $state === 'approved' ? 'SYNTHETIC-MESSAGE' : null];
            $source = $row;
            $set = Backup\CompanyBackupRestoreOverrideSet::fromArray(
                Backup\CompanyBackupIsdsRestorePolicy::gatewayOverrides(), 'table:isds_gateway_sessions',
                array_keys($row), ['id'], Backup\CompanyBackupReferenceSet::fromArray([], 'table:isds_gateway_sessions'));
            $result = $set->apply($row);
            if (in_array($state, ['awaiting_login', 'awaiting_approval'], true)) {
                self::assertSame('uncertain', $result['state']);
                self::assertSame(Backup\CompanyBackupIsdsRestorePolicy::REVIEW_REQUIRED, $result['error_code']);
                self::assertSame(Backup\CompanyBackupIsdsRestorePolicy::MESSAGE, $result['error_message']);
            } else {
                self::assertSame($source, $result);
            }
            self::assertSame($source, $row);
            self::assertSame($source['concept_dm_id'], $result['concept_dm_id']);
        }
    }

    public function testConditionsUseOriginalValuesAndStrictTypes(): void
    {
        $set = Backup\CompanyBackupRestoreOverrideSet::fromArray([
            'a_state' => ['value' => 'uncertain', 'reason' => 'restore_review',
                'when' => ['column' => 'a_state', 'values' => ['pending']]],
            'z_note' => ['value' => 'review', 'reason' => 'restore_review',
                'when' => ['column' => 'a_state', 'values' => ['pending']]],
        ], 'table:synthetic', ['a_state', 'z_note'], [], Backup\CompanyBackupReferenceSet::fromArray([], 'table:synthetic'));
        self::assertSame(['a_state' => 'uncertain', 'z_note' => 'review'], $set->apply(['a_state' => 'pending', 'z_note' => null]));
        self::assertSame(['a_state' => 0, 'z_note' => null], $set->apply(['a_state' => 0, 'z_note' => null]));
    }
}
