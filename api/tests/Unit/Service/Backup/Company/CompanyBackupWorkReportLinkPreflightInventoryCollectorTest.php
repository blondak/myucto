<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkPreflightInventoryCollector;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportLinkPreflightInventoryCollectorTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef';

    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('CREATE TABLE work_report_links (id INTEGER PRIMARY KEY, supplier_id INTEGER, token TEXT UNIQUE, revoked_at TEXT)');
    }

    public function testBindsProtectedTokensToRowsAndIncludesRevokedTargetCollisions(): void
    {
        $collector = new CompanyBackupWorkReportLinkPreflightInventoryCollector();
        $collector->acceptSecret(self::secret(11, self::TOKEN));
        $collector->acceptSecret(self::secret(12, self::TOKEN));
        $collector->acceptRow(self::row(12));
        $collector->acceptRow(self::row(11));
        $clean = $collector->finish($this->database);
        self::assertSame([11, 12], array_column($clean->entries(), 'source_link_id'));
        self::assertSame(2, $clean->collisionCount()); // Duplicate source tokens are both collisions.
        $firstEntry = $clean->entry(11);
        self::assertNotNull($firstEntry);
        self::assertSame(hash('sha256', self::TOKEN), $firstEntry['token_fingerprint']);
        self::assertStringNotContainsString(self::TOKEN, CanonicalJson::encode($clean->toArray()));

        $this->database->exec("INSERT INTO work_report_links VALUES (90, 999, '" . self::TOKEN . "', '2026-01-01 00:00:00')");
        $target = new CompanyBackupWorkReportLinkPreflightInventoryCollector();
        $target->acceptSecret(self::secret(11, self::TOKEN));
        $target->acceptRow(self::row(11));
        $inventory = $target->finish($this->database);
        $entry = $inventory->entry(11);
        self::assertNotNull($entry);
        self::assertTrue($entry['collision']);
        self::assertStringNotContainsString(self::TOKEN, CanonicalJson::encode($inventory->toArray()));
    }

    public function testTargetCollisionChangesPreflightBindingAndEmptyInventoryIsBound(): void
    {
        $clean = $this->collectOne();
        $first = self::preflightResult($clean);
        $this->database->exec("INSERT INTO work_report_links VALUES (90, 999, '" . self::TOKEN . "', '2026-01-01 00:00:00')");
        $collision = $this->collectOne();
        $second = self::preflightResult($collision);
        self::assertNotSame($first->bindingSha256, $second->bindingSha256);
        self::assertSame($collision->sha256(), $second->toArray()['work_report_link_inventory_sha256']);
        self::assertStringNotContainsString(self::TOKEN, CanonicalJson::encode($second->toArray()));

        $empty = (new CompanyBackupWorkReportLinkPreflightInventoryCollector())->finish($this->database);
        self::assertSame(0, $empty->count());
        self::assertArrayHasKey('work_report_link_inventory', self::preflightResult($empty)->toArray());
        self::assertArrayNotHasKey('work_report_link_inventory', self::preflightResult(null)->toArray());
        self::assertNotSame(self::preflightResult($empty)->bindingSha256, self::preflightResult(null)->bindingSha256);
    }

    public function testRejectsMissingOrOrphanSecretAndRepeatedIds(): void
    {
        $cases = [
            ['work_report_link_secret_row_mismatch', static function (self $test, CompanyBackupWorkReportLinkPreflightInventoryCollector $collector): void {
                $collector->acceptRow(self::row(11));
            }],
            ['work_report_link_secret_row_mismatch', static function (self $test, CompanyBackupWorkReportLinkPreflightInventoryCollector $collector): void {
                $collector->acceptSecret(self::secret(11, self::TOKEN));
            }],
            ['work_report_link_secret_duplicate', static function (self $test, CompanyBackupWorkReportLinkPreflightInventoryCollector $collector): void {
                $collector->acceptSecret(self::secret(11, self::TOKEN));
                $collector->acceptSecret(self::secret(11, self::TOKEN));
            }],
            ['work_report_link_source_duplicate', static function (self $test, CompanyBackupWorkReportLinkPreflightInventoryCollector $collector): void {
                $collector->acceptRow(self::row(11));
                $collector->acceptRow(self::row(11));
            }],
            ['work_report_link_secret_row_mismatch', static function (self $test, CompanyBackupWorkReportLinkPreflightInventoryCollector $collector): void {
                $collector->acceptSecret(self::secret(12, self::TOKEN));
                $collector->acceptRow(self::row(11));
            }],
        ];
        foreach ($cases as [$code, $fill]) {
            $collector = new CompanyBackupWorkReportLinkPreflightInventoryCollector();
            try {
                $fill($this, $collector);
                $collector->finish($this->database);
                self::fail('Neplatná bijekce musí skončit chybou.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame($code, $e->errorCode);
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            }
        }
    }

    public function testRejectsPlaintextRowsInvalidScopeTokenAndBoundedCounts(): void
    {
        $cases = [
            ['work_report_link_plaintext_row_forbidden', static function (CompanyBackupWorkReportLinkPreflightInventoryCollector $c): void {
                $c->acceptRow(self::row(11) + ['token' => self::TOKEN]);
            }],
            ['work_report_link_scope_invalid', static function (CompanyBackupWorkReportLinkPreflightInventoryCollector $c): void {
                $c->acceptRow(array_replace(self::row(11), ['project_id' => 1]));
            }],
            ['work_report_link_token_invalid', static function (CompanyBackupWorkReportLinkPreflightInventoryCollector $c): void {
                $c->acceptSecret(self::secret(11, 'BAD'));
            }],
            ['work_report_link_secret_invalid', static function (CompanyBackupWorkReportLinkPreflightInventoryCollector $c): void {
                $c->acceptSecret(self::secret(0, self::TOKEN));
            }],
        ];
        foreach ($cases as [$code, $apply]) {
            try {
                $apply(new CompanyBackupWorkReportLinkPreflightInventoryCollector());
                self::fail('Neplatný vstup musí skončit chybou.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame($code, $e->errorCode);
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            }
        }

        $limits = new CompanyBackupArchiveLimits(maxReferenceRequirements: 1);
        foreach (['row', 'secret'] as $kind) {
            $collector = new CompanyBackupWorkReportLinkPreflightInventoryCollector($limits);
            try {
                if ($kind === 'row') {
                    $collector->acceptRow(self::row(11));
                    $collector->acceptRow(self::row(12));
                } else {
                    $collector->acceptSecret(self::secret(11, self::TOKEN));
                    $collector->acceptSecret(self::secret(12, str_repeat('a', 48)));
                }
                self::fail('Limit musí zastavit obě sady před druhým vložením.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('work_report_link_inventory_limit_exceeded', $e->errorCode);
            }
        }
    }

    public function testPreflightResultRejectsInventoryLargerThanTotalRows(): void
    {
        $collector = new CompanyBackupWorkReportLinkPreflightInventoryCollector();
        $collector->acceptSecret(self::secret(11, self::TOKEN));
        $collector->acceptSecret(self::secret(12, str_repeat('a', 48)));
        $collector->acceptRow(self::row(11));
        $collector->acceptRow(self::row(12));
        $inventory = $collector->finish($this->database);
        $this->expectException(\InvalidArgumentException::class);
        new CompanyBackupDataPreflightResult(
            new CompanyBackupExternalReferenceInventory([]), 1, 1, 1, 16, 0,
            'sha256:' . str_repeat('a', 64), str_repeat('b', 64),
            workReportLinkInventory: $inventory,
        );
    }

    private function collectOne(): \MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkInventory
    {
        $collector = new CompanyBackupWorkReportLinkPreflightInventoryCollector();
        $collector->acceptSecret(self::secret(11, self::TOKEN));
        $collector->acceptRow(self::row(11));
        return $collector->finish($this->database);
    }

    private static function secret(int $id, string $token): CompanyBackupSecretValue
    {
        return CompanyBackupSecretValue::fromPlaintext(
            'table:work_report_links', CompanyBackupSecretScope::Column, 'token', ['id' => $id], $token,
        );
    }

    /** @return array<string,mixed> */
    private static function row(int $id): array
    {
        return ['id' => $id, 'scope' => 'client', 'project_id' => null];
    }

    private static function preflightResult(?\MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkInventory $inventory): CompanyBackupDataPreflightResult
    {
        return new CompanyBackupDataPreflightResult(
            new CompanyBackupExternalReferenceInventory([]), 1, 1, 1, 16, 0,
            'sha256:' . str_repeat('a', 64), str_repeat('b', 64),
            workReportLinkInventory: $inventory,
        );
    }
}
