<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupJournalBalancePostImportInvariant;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportException;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupJournalBalancePostImportInvariantTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro invariantní test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->database->exec(
            'CREATE TABLE journal_entries ('
                . 'id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL,'
                . 'source_type TEXT NOT NULL, source_id INTEGER NULL,'
                . 'document_no TEXT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE journal_entry_lines ('
                . 'id INTEGER PRIMARY KEY, entry_id INTEGER NOT NULL,'
                . 'supplier_id INTEGER NOT NULL, side TEXT NOT NULL,'
                . 'amount NUMERIC NOT NULL)',
        );
    }

    public function testAcceptsBalancedJournalInsideCallerTransaction(): void
    {
        $this->insertEntry(31, 100.25, 100.25);
        self::assertTrue($this->database->beginTransaction());

        $checks = (new CompanyBackupJournalBalancePostImportInvariant())
            ->validate($this->database, 7, $this->snapshot());

        self::assertSame(1, $checks);
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsUnbalancedJournalBeforeCommit(): void
    {
        $this->insertEntry(31, 100.00, 40.00);
        self::assertTrue($this->database->beginTransaction());

        try {
            (new CompanyBackupJournalBalancePostImportInvariant())->validate(
                $this->database,
                7,
                $this->snapshot(),
            );
            self::fail('Nevyvážený deník nesmí být commitnut po obnově.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_accounting_journal_unbalanced',
                $e->errorCode,
            );
            self::assertSame('table:journal_entries', $e->registryKey);
            self::assertNull($e->getPrevious());
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsJournalHeaderWithoutLines(): void
    {
        $this->database->exec(
            "INSERT INTO journal_entries"
                . " (id, supplier_id, source_type, source_id, document_no)"
                . " VALUES (31, 7, 'manual', NULL, 'SYNTHETIC-31')",
        );
        self::assertTrue($this->database->beginTransaction());

        try {
            (new CompanyBackupJournalBalancePostImportInvariant())->validate(
                $this->database,
                7,
                $this->snapshot(),
            );
            self::fail('Prázdná hlavička deníku nesmí být považována za vyváženou.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_accounting_journal_unbalanced',
                $e->errorCode,
            );
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsPartialJournalRegistryBeforeReadingData(): void
    {
        self::assertTrue($this->database->beginTransaction());

        try {
            (new CompanyBackupJournalBalancePostImportInvariant())->validate(
                $this->database,
                7,
                $this->snapshot(includeLines: false),
            );
            self::fail('Neúplná dvojice tabulek deníku musí selhat uzavřeně.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_accounting_journal_registry_invalid',
                $e->errorCode,
            );
            self::assertSame('table:journal_entries', $e->registryKey);
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    private function insertEntry(
        int $entryId,
        float $debit,
        float $credit,
    ): void {
        $entry = $this->database->prepare(
            'INSERT INTO journal_entries'
                . ' (id, supplier_id, source_type, source_id, document_no)'
                . " VALUES (?, 7, 'manual', NULL, ?)",
        );
        $entry->execute([$entryId, 'SYNTHETIC-' . $entryId]);
        $line = $this->database->prepare(
            'INSERT INTO journal_entry_lines'
                . ' (id, entry_id, supplier_id, side, amount)'
                . ' VALUES (?, ?, 7, ?, ?)',
        );
        $line->execute([$entryId * 10, $entryId, 'debit', $debit]);
        $line->execute([$entryId * 10 + 1, $entryId, 'credit', $credit]);
    }

    private function snapshot(bool $includeLines = true): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $definitions = [
            $this->definition('table:supplier', TenantDataPolicy::TenantRoot),
            $this->definition(
                'table:journal_entries',
                TenantDataPolicy::TenantOwned,
            ),
        ];
        if ($includeLines) {
            $definitions[] = $this->definition(
                'table:journal_entry_lines',
                TenantDataPolicy::TenantOwned,
            );
        }
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            $definitions,
            [$profile],
        ), $profile);
    }

    private function definition(
        string $key,
        TenantDataPolicy $policy,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => $policy === TenantDataPolicy::TenantRoot
                        ? 'selected_supplier'
                        : 'supplier_id',
                    'column' => $policy === TenantDataPolicy::TenantRoot
                        ? 'id'
                        : 'supplier_id',
                ],
                'secrets' => [],
            ],
        );
    }
}
