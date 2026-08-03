<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollGeneratedDocumentsSchemaTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testImmutableArtifactDownloadAndDmsSchemaIsInstalled(): void
    {
        $connection = Bootstrap::buildContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        foreach ([
            'payroll_generated_documents',
            'payroll_document_download_grants',
            'payroll_document_dms_links',
        ] as $table) {
            self::assertTrue($connection->hasTable($table), "Missing table {$table}.");
        }

        $columns = $this->query(
            $connection->pdo(),
            'SHOW COLUMNS FROM payroll_generated_documents',
        )
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('revision_snapshot_hash', $columns);
        self::assertContains('source_snapshot_hash', $columns);
        self::assertContains('file_sha256', $columns);
        self::assertNotContains('dms_document_id', $columns);

        $triggers = $this->query($connection->pdo(), 'SHOW TRIGGERS')
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('trg_payroll_generated_document_immutable_update', $triggers);
        self::assertContains('trg_payroll_document_approved_revision_insert', $triggers);
        self::assertContains('trg_payroll_document_dms_tenant_insert', $triggers);

        $connection->close();
    }

    public function testIdempotentReplayWorksWithImmutableDocumentTrigger(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $repository = $container->get(PayrollDocumentRepository::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollDocumentRepository::class, $repository);

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $this->query(
            $pdo,
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
            $pdo->prepare(
                'INSERT INTO payroll_runs (supplier_id, period_start)
                 VALUES (?, "2099-12-01")'
            )->execute([$supplierId]);
            $runId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO payroll_run_revisions
                    (supplier_id, run_id, revision_no, status, schema_version,
                     ruleset_manifest_hash, input_snapshot_json,
                     input_snapshot_hash, result_snapshot_json,
                     result_snapshot_hash, idempotency_key_hash)
                 VALUES (?, ?, 1, "approved", "payroll-run-input.v1",
                         ?, "{}", ?, "{}", ?, UNHEX(?))'
            )->execute([
                $supplierId,
                $runId,
                str_repeat('a', 64),
                str_repeat('b', 64),
                str_repeat('c', 64),
                hash('sha256', 'synthetic-run-revision'),
            ]);
            $revisionId = (int) $pdo->lastInsertId();
            $record = [
                'supplier_id' => $supplierId,
                'run_id' => $runId,
                'revision_id' => $revisionId,
                'employee_id' => null,
                'document_kind' => 'monthly_bundle',
                'document_revision_no' => 1,
                'supersedes_document_id' => null,
                'source_snapshot_hash' => str_repeat('d', 64),
                'revision_snapshot_hash' => str_repeat('c', 64),
                'template_version' => 'synthetic-v1',
                'renderer_version' => 'synthetic-v1',
                'file_sha256' => str_repeat('e', 64),
                'size_bytes' => 42,
                'mime_type' => 'application/zip',
                'storage_key' => str_repeat('e', 64),
                'suggested_filename' => 'synthetic-payroll-bundle.zip',
                'manifest_json' => '{}',
                'idempotency_key_hash' => hash(
                    'sha256',
                    'synthetic-document-idempotency',
                ),
                'created_by' => null,
            ];

            $created = $repository->insertOrGet($record);
            $replayed = $repository->insertOrGet($record);

            self::assertSame($created['id'], $replayed['id']);

            $duplicateRevision = $record;
            $duplicateRevision['idempotency_key_hash'] = hash(
                'sha256',
                'synthetic-document-second-key',
            );
            $duplicateRevision['file_sha256'] = str_repeat('f', 64);
            $duplicateRevision['storage_key'] = str_repeat('f', 64);
            $duplicateRejected = false;
            try {
                $repository->insertOrGet($duplicateRevision);
            } catch (\RuntimeException) {
                $duplicateRejected = true;
            }
            self::assertTrue(
                $duplicateRejected,
                'Stejná revize firemního dokumentu nesmí obejít unikátnost přes NULL employee_id.',
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    public function testDocumentCannotSupersedeArtifactFromAnotherPayrollRun(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $repository = $container->get(PayrollDocumentRepository::class);
        $service = $container->get(PayrollDocumentService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollDocumentRepository::class, $repository);
        self::assertInstanceOf(PayrollDocumentService::class, $service);

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $this->query(
            $pdo,
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
            $runs = [];
            foreach (['2099-10-01', '2099-11-01'] as $index => $periodStart) {
                $pdo->prepare(
                    'INSERT INTO payroll_runs (supplier_id, period_start)
                     VALUES (?, ?)'
                )->execute([$supplierId, $periodStart]);
                $runId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO payroll_run_revisions
                        (supplier_id, run_id, revision_no, status, schema_version,
                         ruleset_manifest_hash, input_snapshot_json,
                         input_snapshot_hash, result_snapshot_json,
                         result_snapshot_hash, idempotency_key_hash)
                     VALUES (?, ?, 1, "approved", "payroll-run-input.v1",
                             ?, "{}", ?, "{}", ?, UNHEX(?))'
                )->execute([
                    $supplierId,
                    $runId,
                    str_repeat((string) ($index + 1), 64),
                    str_repeat((string) ($index + 3), 64),
                    str_repeat((string) ($index + 5), 64),
                    hash('sha256', "synthetic-run-{$index}"),
                ]);
                $runs[] = [$runId, (int) $pdo->lastInsertId()];
            }

            $previous = $repository->insertOrGet([
                'supplier_id' => $supplierId,
                'run_id' => $runs[0][0],
                'revision_id' => $runs[0][1],
                'employee_id' => null,
                'document_kind' => PayrollDocumentKind::PayrollSheet->value,
                'document_revision_no' => 1,
                'supersedes_document_id' => null,
                'source_snapshot_hash' => str_repeat('7', 64),
                'revision_snapshot_hash' => str_repeat('5', 64),
                'template_version' => 'synthetic-v1',
                'renderer_version' => 'synthetic-v1',
                'file_sha256' => str_repeat('8', 64),
                'size_bytes' => 42,
                'mime_type' => 'application/pdf',
                'storage_key' => str_repeat('8', 64),
                'suggested_filename' => 'synthetic-payroll-sheet.pdf',
                'manifest_json' => null,
                'idempotency_key_hash' => hash('sha256', 'synthetic-previous'),
                'created_by' => null,
            ]);

            $this->expectException(\RuntimeException::class);
            $service->archivePdf(
                $supplierId,
                $runs[1][0],
                $runs[1][1],
                null,
                PayrollDocumentKind::PayrollSheet,
                '%PDF-1.4 synthetic',
                str_repeat('9', 64),
                'synthetic-v2',
                'synthetic-v2',
                'synthetic-cross-run',
                null,
                (int) $previous['id'],
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    public function testMonthlyBundleReusesSameManifestAcrossClientKeys(): void
    {
        $previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $dataDir = sys_get_temp_dir() . '/myucto-payroll-bundle-' . bin2hex(random_bytes(6));
        putenv('MYINVOICE_DATA_DIR=' . $dataDir);

        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $repository = $container->get(PayrollDocumentRepository::class);
        $service = $container->get(PayrollDocumentService::class);
        $storage = $container->get(PayrollDocumentStorage::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollDocumentRepository::class, $repository);
        self::assertInstanceOf(PayrollDocumentService::class, $service);
        self::assertInstanceOf(PayrollDocumentStorage::class, $storage);

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $this->query(
            $pdo,
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
            $pdo->prepare(
                'INSERT INTO payroll_runs (supplier_id, period_start)
                 VALUES (?, "2099-09-01")'
            )->execute([$supplierId]);
            $runId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO payroll_run_revisions
                    (supplier_id, run_id, revision_no, status, schema_version,
                     ruleset_manifest_hash, input_snapshot_json,
                     input_snapshot_hash, result_snapshot_json,
                     result_snapshot_hash, idempotency_key_hash)
                 VALUES (?, ?, 1, "approved", "payroll-run-input.v1",
                         ?, "{}", ?, "{}", ?, UNHEX(?))'
            )->execute([
                $supplierId,
                $runId,
                str_repeat('a', 64),
                str_repeat('b', 64),
                str_repeat('c', 64),
                hash('sha256', 'synthetic-bundle-revision'),
            ]);
            $revisionId = (int) $pdo->lastInsertId();
            $pdf = '%PDF-1.4 synthetic payroll sheet';
            $stored = $storage->store($supplierId, $pdf);
            $repository->insertOrGet([
                'supplier_id' => $supplierId,
                'run_id' => $runId,
                'revision_id' => $revisionId,
                'employee_id' => null,
                'document_kind' => PayrollDocumentKind::PayrollSheet->value,
                'document_revision_no' => 1,
                'supersedes_document_id' => null,
                'source_snapshot_hash' => str_repeat('d', 64),
                'revision_snapshot_hash' => str_repeat('c', 64),
                'template_version' => 'synthetic-v1',
                'renderer_version' => 'synthetic-v1',
                'file_sha256' => $stored['file_sha256'],
                'size_bytes' => $stored['size_bytes'],
                'mime_type' => 'application/pdf',
                'storage_key' => $stored['storage_key'],
                'suggested_filename' => 'synthetic-payroll-sheet.pdf',
                'manifest_json' => null,
                'idempotency_key_hash' => hash('sha256', 'synthetic-sheet'),
                'created_by' => null,
            ]);

            $first = $service->generateMonthlyBundle(
                $supplierId,
                $runId,
                $revisionId,
                'client-key-one',
                null,
            );
            $second = $service->generateMonthlyBundle(
                $supplierId,
                $runId,
                $revisionId,
                'client-key-two',
                null,
            );

            self::assertSame($first['id'], $second['id']);
            self::assertSame(1, $first['document_revision_no']);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
            $this->removeDirectory($dataDir);
            $previousDataDir === false
                ? putenv('MYINVOICE_DATA_DIR')
                : putenv('MYINVOICE_DATA_DIR=' . $previousDataDir);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    private function query(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Synthetic payroll test query failed.');
        }
        return $statement;
    }
}
