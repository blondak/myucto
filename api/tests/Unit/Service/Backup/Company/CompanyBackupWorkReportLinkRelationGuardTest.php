<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkRelationGuard;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportLinkRelationGuardTest extends TestCase
{
    /** @param array<string,mixed> $metadata */
    #[DataProvider('invalidMetadata')]
    public function testRejectsMalformedMetadataBeforeDatabaseAccess(array $metadata, string $code): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSafeError($code, static fn () => CompanyBackupWorkReportLinkRelationGuard::assertValid($pdo, $metadata));
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invalidMetadata(): iterable
    {
        $valid = ['supplier_id' => 1, 'client_id' => 11, 'project_id' => null, 'scope' => 'client'];
        yield 'missing client' => [array_diff_key($valid, ['client_id' => true]), 'work_report_link_relation_invalid'];
        yield 'extra token' => [$valid + ['token' => str_repeat('a', 48)], 'work_report_link_relation_invalid'];
        yield 'string supplier' => [array_replace($valid, ['supplier_id' => '1']), 'work_report_link_relation_invalid'];
        yield 'zero supplier' => [array_replace($valid, ['supplier_id' => 0]), 'work_report_link_relation_invalid'];
        yield 'negative client' => [array_replace($valid, ['client_id' => -1]), 'work_report_link_relation_invalid'];
        yield 'string client' => [array_replace($valid, ['client_id' => '11']), 'work_report_link_relation_invalid'];
        yield 'invalid scope' => [array_replace($valid, ['scope' => 'all']), 'work_report_link_scope_invalid'];
        yield 'client scope with project' => [array_replace($valid, ['project_id' => 21]), 'work_report_link_scope_invalid'];
        yield 'project scope without project' => [array_replace($valid, ['scope' => 'project']), 'work_report_link_scope_invalid'];
        yield 'project scope with zero' => [array_replace($valid, ['scope' => 'project', 'project_id' => 0]), 'work_report_link_scope_invalid'];
        yield 'project scope with string id' => [array_replace($valid, ['scope' => 'project', 'project_id' => '21']), 'work_report_link_scope_invalid'];
    }

    public function testDatabaseFailuresFailClosedWithoutDriverDiagnostics(): void
    {
        $metadata = ['supplier_id' => 1, 'client_id' => 11, 'project_id' => null, 'scope' => 'client'];
        foreach ([PDO::ERRMODE_EXCEPTION, PDO::ERRMODE_SILENT] as $mode) {
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => $mode]);
            $this->assertSafeError('work_report_link_relation_lookup_failed',
                static fn () => CompanyBackupWorkReportLinkRelationGuard::assertValid($pdo, $metadata));
        }
    }

    private function assertSafeError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatná vazba veřejného odkazu nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame('table:work_report_links', $e->registryKey);
            self::assertStringNotContainsString(str_repeat('a', 48), $e->getMessage());
            self::assertStringNotContainsString('SQLSTATE', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }
}
