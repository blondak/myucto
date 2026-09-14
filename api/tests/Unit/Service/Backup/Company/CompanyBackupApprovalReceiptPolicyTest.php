<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupApprovalReceiptPolicy;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupApprovalReceiptPolicyTest extends TestCase
{
    public function testPreservesOriginalHashWithoutCollision(): void
    {
        $hash = str_repeat('a', 32) . str_repeat('f', 32);

        self::assertSame($hash, CompanyBackupApprovalReceiptPolicy::restoreHash($hash, false));
        self::assertNull(CompanyBackupApprovalReceiptPolicy::restoreHash(null, false));
    }

    public function testExplicitInvalidationOnlyRemovesCollidingRestoredHash(): void
    {
        $hash = str_repeat('b', 64);

        self::assertNull(CompanyBackupApprovalReceiptPolicy::restoreHash($hash, true, 'invalidate'));
        self::assertSame(str_repeat('b', 64), $hash);
    }

    #[DataProvider('rejectedCases')]
    public function testRejectsInvalidContextAndDecisionWithoutLeakingReceipt(
        mixed $hash,
        bool $collision,
        ?string $decision,
        string $code,
    ): void {
        $original = $hash;
        try {
            CompanyBackupApprovalReceiptPolicy::restoreHash($hash, $collision, $decision);
            self::fail('Neplatný kontext schvalovacího potvrzení nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame('table:invoices', $e->registryKey);
            self::assertSame('approval_receipt_hash', $e->column);
            self::assertSame($code . ': table:invoices.approval_receipt_hash', $e->getMessage());
            if (is_string($hash) && $hash !== '') {
                self::assertStringNotContainsString($hash, $e->getMessage());
            }
            self::assertSame($original, $hash);
        }
    }

    /** @return iterable<string,array{mixed,bool,?string,string}> */
    public static function rejectedCases(): iterable
    {
        $hash = str_repeat('c', 64);
        yield 'missing collision decision' => [$hash, true, null, 'approval_receipt_decision_required'];
        yield 'reject whole restore' => [$hash, true, 'reject', 'approval_receipt_collision_rejected'];
        yield 'stale invalidation' => [$hash, false, 'invalidate', 'approval_receipt_decision_out_of_scope'];
        yield 'stale rejection' => [$hash, false, 'reject', 'approval_receipt_decision_out_of_scope'];
        yield 'null stale decision' => [null, false, 'invalidate', 'approval_receipt_decision_out_of_scope'];
        yield 'null collision' => [null, true, null, 'approval_receipt_collision_context_invalid'];
        yield 'null collision with decision' => [null, true, 'invalidate', 'approval_receipt_collision_context_invalid'];
        yield 'unknown decision without collision' => [$hash, false, 'preserve', 'approval_receipt_decision_invalid'];
        yield 'unknown decision with collision' => [$hash, true, 'preserve', 'approval_receipt_decision_invalid'];
        yield 'unknown decision without hash' => [null, false, 'preserve', 'approval_receipt_decision_invalid'];
        yield 'uppercase hash' => [str_repeat('A', 64), false, null, 'approval_receipt_hash_invalid'];
        yield 'short hash' => ['abc', false, null, 'approval_receipt_hash_invalid'];
        yield 'long hash' => [str_repeat('a', 65), true, 'invalidate', 'approval_receipt_hash_invalid'];
        yield 'non-string hash' => [42, false, null, 'approval_receipt_hash_invalid'];
        yield 'boolean hash' => [false, false, null, 'approval_receipt_hash_invalid'];
    }
}
