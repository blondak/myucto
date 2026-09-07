<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPostImportException;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportInvariant;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportInvariantRegistry;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportInvariantReport;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportValidationResult;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPostImportInvariantRegistryTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro invariantní test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public function testRunsInCanonicalOrderAndBindsReportToTarget(): void
    {
        $snapshot = $this->snapshot();
        $calls = new PostImportInvariantCallLog();
        $registry = CompanyBackupPostImportInvariantRegistry::fromInvariants([
            new TestPostImportInvariant('stock.movements', 2, $calls),
            new TestPostImportInvariant('accounting.journal', 3, $calls),
        ]);
        self::assertTrue($this->database->beginTransaction());

        $report = $registry->validate($this->database, 41, $snapshot);

        self::assertSame(
            ['accounting.journal', 'stock.movements'],
            $calls->ids,
        );
        self::assertSame([41, 41], $calls->supplierIds);
        self::assertSame(
            [$snapshot->fingerprint, $snapshot->fingerprint],
            $calls->registryFingerprints,
        );
        self::assertSame(41, $report->supplierId);
        self::assertSame(
            $snapshot->fingerprint,
            $report->targetRegistryFingerprint,
        );
        self::assertSame(2, $report->invariantCount);
        self::assertSame(5, $report->checkCount);
        self::assertSame([
            ['id' => 'accounting.journal', 'check_count' => 3],
            ['id' => 'stock.movements', 'check_count' => 2],
        ], $report->invariants);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $report->bindingSha256,
        );
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRegistrationOrderDoesNotChangeReportBinding(): void
    {
        $snapshot = $this->snapshot();
        $first = CompanyBackupPostImportInvariantRegistry::fromInvariants([
            new TestPostImportInvariant(
                'stock.movements',
                2,
                new PostImportInvariantCallLog(),
            ),
            new TestPostImportInvariant(
                'accounting.journal',
                3,
                new PostImportInvariantCallLog(),
            ),
        ]);
        $second = CompanyBackupPostImportInvariantRegistry::fromInvariants([
            new TestPostImportInvariant(
                'accounting.journal',
                3,
                new PostImportInvariantCallLog(),
            ),
            new TestPostImportInvariant(
                'stock.movements',
                2,
                new PostImportInvariantCallLog(),
            ),
        ]);
        self::assertTrue($this->database->beginTransaction());

        self::assertSame(
            $first->validate($this->database, 41, $snapshot)->bindingSha256,
            $second->validate($this->database, 41, $snapshot)->bindingSha256,
        );
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsDuplicateAndUnsafeInvariantIdentifiers(): void
    {
        $calls = new PostImportInvariantCallLog();
        try {
            CompanyBackupPostImportInvariantRegistry::fromInvariants([
                new TestPostImportInvariant('accounting.journal', 1, $calls),
                new TestPostImportInvariant('accounting.journal', 1, $calls),
            ]);
            self::fail('Duplicitní invariant nesmí vzniknout v registru.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Duplicitní', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('identifikátor');
        CompanyBackupPostImportInvariantRegistry::fromInvariants([
            new TestPostImportInvariant('../unsafe', 1, $calls),
        ]);
    }

    public function testWrapsUnexpectedFailureWithoutLeakingItsMessage(): void
    {
        $snapshot = $this->snapshot();
        $failure = new \RuntimeException('SELECT secret FROM another_tenant');
        $registry = CompanyBackupPostImportInvariantRegistry::fromInvariants([
            new TestPostImportInvariant(
                'accounting.failure',
                0,
                new PostImportInvariantCallLog(),
                failure: $failure,
            ),
        ]);
        self::assertTrue($this->database->beginTransaction());

        try {
            $registry->validate($this->database, 41, $snapshot);
            self::fail('Neočekávaná chyba invariantu musí obnovu zastavit.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame('post_import_invariant_failed', $e->errorCode);
            self::assertSame('invariant:accounting.failure', $e->registryKey);
            self::assertSame($failure, $e->getPrevious());
            self::assertStringNotContainsString('another_tenant', $e->getMessage());
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testDetectsInvariantThatEndsCallerTransaction(): void
    {
        $snapshot = $this->snapshot();
        $registry = CompanyBackupPostImportInvariantRegistry::fromInvariants([
            new TestPostImportInvariant(
                'accounting.commit',
                1,
                new PostImportInvariantCallLog(),
                commitTransaction: true,
            ),
        ]);
        self::assertTrue($this->database->beginTransaction());

        try {
            $registry->validate($this->database, 41, $snapshot);
            self::fail('Invariant nesmí ukončit transakci koordinátoru.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame('post_import_transaction_lost', $e->errorCode);
            self::assertSame('invariant:accounting.commit', $e->registryKey);
        }

        self::assertFalse($this->database->inTransaction());
    }

    public function testRejectsNegativeCheckCount(): void
    {
        $snapshot = $this->snapshot();
        $registry = CompanyBackupPostImportInvariantRegistry::fromInvariants([
            new TestPostImportInvariant(
                'accounting.invalid-result',
                -1,
                new PostImportInvariantCallLog(),
            ),
        ]);
        self::assertTrue($this->database->beginTransaction());

        try {
            $registry->validate($this->database, 41, $snapshot);
            self::fail('Záporný počet kontrol nesmí vytvořit platný report.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_invariant_result_invalid',
                $e->errorCode,
            );
            self::assertSame(
                'invariant:accounting.invalid-result',
                $e->registryKey,
            );
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testValidationResultRejectsReportForAnotherSupplier(): void
    {
        $snapshot = $this->snapshot();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('post-import kontroly');

        new CompanyBackupPostImportValidationResult(
            41,
            $snapshot->fingerprint,
            str_repeat('a', 64),
            str_repeat('b', 64),
            new CompanyBackupPostImportInvariantReport(
                42,
                $snapshot->fingerprint,
                [],
            ),
            1,
            1,
            0,
            0,
            0,
        );
    }

    public function testValidationBindingIncludesInvariantReport(): void
    {
        $snapshot = $this->snapshot();
        $withoutAgendaChecks = $this->validationResult(
            $snapshot,
            new CompanyBackupPostImportInvariantReport(
                41,
                $snapshot->fingerprint,
                [],
            ),
        );
        $withAgendaChecks = $this->validationResult(
            $snapshot,
            new CompanyBackupPostImportInvariantReport(
                41,
                $snapshot->fingerprint,
                [[
                    'id' => 'accounting.journal',
                    'check_count' => 1,
                ]],
            ),
        );

        self::assertNotSame(
            $withoutAgendaChecks->bindingSha256,
            $withAgendaChecks->bindingSha256,
        );
    }

    private function validationResult(
        TenantDataRegistrySnapshot $snapshot,
        CompanyBackupPostImportInvariantReport $report,
    ): CompanyBackupPostImportValidationResult {
        return new CompanyBackupPostImportValidationResult(
            41,
            $snapshot->fingerprint,
            str_repeat('a', 64),
            str_repeat('b', 64),
            $report,
            1,
            1,
            0,
            0,
            0,
        );
    }

    private function snapshot(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                new TenantDataDefinition(
                    'table:supplier',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantRoot,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' => [
                            'strategy' => 'selected_supplier',
                            'column' => 'id',
                        ],
                        'secrets' => [],
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }
}

/** @internal */
final class PostImportInvariantCallLog
{
    /** @var list<string> */
    public array $ids = [];

    /** @var list<int> */
    public array $supplierIds = [];

    /** @var list<string> */
    public array $registryFingerprints = [];
}

/** @internal */
final readonly class TestPostImportInvariant implements
    CompanyBackupPostImportInvariant
{
    public function __construct(
        private string $invariantId,
        private int $checkCount,
        private PostImportInvariantCallLog $calls,
        private ?\Throwable $failure = null,
        private bool $commitTransaction = false,
    ) {}

    public function id(): string
    {
        return $this->invariantId;
    }

    public function validate(
        PDO $database,
        int $supplierId,
        TenantDataRegistrySnapshot $registry,
    ): int {
        $this->calls->ids[] = $this->invariantId;
        $this->calls->supplierIds[] = $supplierId;
        $this->calls->registryFingerprints[] = $registry->fingerprint;
        if ($this->commitTransaction && !$database->commit()) {
            throw new \RuntimeException('Syntetický commit selhal.');
        }
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
        return $this->checkCount;
    }
}
