<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Action\Payroll\PayrollDocumentAction;
use PHPUnit\Framework\TestCase;

final class PayrollDocumentActionTest extends TestCase
{
    public function testPublicMetadataDoesNotExposeStorageOrInternalHashes(): void
    {
        $method = new \ReflectionMethod(PayrollDocumentAction::class, 'publicDocument');
        $result = $method->invoke(null, [
            'id' => 42,
            'run_id' => 7,
            'revision_id' => 8,
            'employee_id' => 9,
            'employee_name' => 'Testovací Zaměstnanec',
            'document_kind' => 'payslip',
            'file_sha256' => str_repeat('a', 64),
            'size_bytes' => 1234,
            'mime_type' => 'application/pdf',
            'suggested_filename' => 'vyplatni-paska-2026-07-abcdef123456.pdf',
            'created_at' => '2026-08-03 12:00:00',
            'storage_key' => str_repeat('a', 64),
            'source_snapshot_hash' => str_repeat('b', 64),
            'revision_snapshot_hash' => str_repeat('c', 64),
            'idempotency_key_hash' => 'internal',
            'manifest' => ['private' => true],
        ]);

        self::assertIsArray($result);
        self::assertSame(42, $result['id']);
        self::assertSame('Testovací Zaměstnanec', $result['employee_name']);
        self::assertArrayHasKey('file_sha256', $result);
        self::assertArrayNotHasKey('storage_key', $result);
        self::assertArrayNotHasKey('source_snapshot_hash', $result);
        self::assertArrayNotHasKey('revision_snapshot_hash', $result);
        self::assertArrayNotHasKey('idempotency_key_hash', $result);
        self::assertArrayNotHasKey('manifest', $result);
    }
}
