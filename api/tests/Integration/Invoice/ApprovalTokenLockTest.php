<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\ApprovalTokenLock;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ApprovalTokenLockTest extends TestCase
{
    private ?PDO $first = null;
    private ?PDO $second = null;
    private int $clientId = 0;
    private int $invoiceId = 0;

    protected function setUp(): void
    {
        $name = getenv('MYINVOICE_DB_NAME');
        $host = getenv('MYINVOICE_DB_HOST');
        $user = getenv('MYINVOICE_DB_USER');
        if ($name === false || $host === false || $user === false) {
            self::markTestSkipped('Izolovaná testovací databáze není nastavena.');
        }
        if (!str_ends_with($name, '_test')) {
            throw new \RuntimeException('Test zámku smí běžet pouze na databázi s příponou _test.');
        }
        $port = getenv('MYINVOICE_DB_PORT') ?: '3306';
        $password = getenv('MYINVOICE_DB_PASS') ?: '';
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3];
        $this->first = new PDO($dsn, $user, $password, $options);
        $this->second = new PDO($dsn, $user, $password, $options);
    }

    protected function tearDown(): void
    {
        foreach ([$this->first, $this->second] as $pdo) {
            if ($pdo === null) {
                continue;
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->query('SELECT RELEASE_ALL_LOCKS()');
        }
        if ($this->second !== null && $this->invoiceId > 0) {
            $this->second->prepare('DELETE FROM invoices WHERE id = ?')->execute([$this->invoiceId]);
        }
        if ($this->second !== null && $this->clientId > 0) {
            $this->second->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->first = null;
        $this->second = null;
    }

    public function testHeldLockBlocksOtherApprovalButNotUnrelatedInvoiceWrite(): void
    {
        $first = self::pdo($this->first);
        $second = self::pdo($this->second);
        $invoiceId = $this->createInvoiceFixture($second);
        $called = false;
        $result = ApprovalTokenLock::run($first, function () use ($second, $invoiceId, &$called): string {
            try {
                ApprovalTokenLock::run($second, function () use (&$called): void {
                    $called = true;
                }, 0);
                self::fail('Další schvalovací zápis musí na zámku skončit.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Schvalovací tokeny právě mění', $error->getMessage());
            }
            self::assertFalse($called);
            $statement = $second->prepare('UPDATE invoices SET approval_reminder_count = approval_reminder_count + 1 WHERE id = ?');
            $statement->execute([$invoiceId]);
            self::assertSame(1, $statement->rowCount(), 'Nezávislý zápis faktury musí skutečně proběhnout.');
            return 'done';
        });
        self::assertSame('done', $result);
        $count = $second->prepare('SELECT approval_reminder_count FROM invoices WHERE id = ?');
        $count->execute([$invoiceId]);
        self::assertSame(1, (int) $count->fetchColumn());
        self::assertSame('available', ApprovalTokenLock::run($second, static fn (): string => 'available', 0));
    }

    public function testAllFourRepositoryApprovalWritersWaitForSharedLock(): void
    {
        $first = self::pdo($this->first);
        $second = self::pdo($this->second);
        $invoiceId = $this->createInvoiceFixture($second);
        $config = new Config(['db' => [
            'host' => (string) getenv('MYINVOICE_DB_HOST'),
            'port' => (int) (getenv('MYINVOICE_DB_PORT') ?: 3306),
            'name' => (string) getenv('MYINVOICE_DB_NAME'),
            'user' => (string) getenv('MYINVOICE_DB_USER'),
            'pass' => (string) (getenv('MYINVOICE_DB_PASS') ?: ''),
        ]]);
        $connection = Connection::withoutSharedTestConnection(static fn (): Connection => new Connection($config));
        $repository = new InvoiceRepository($connection);
        $database = $first->query('SELECT DATABASE()');
        self::assertNotFalse($database);
        $name = hash('sha256', 'myucto:approval-token-lock:' . strtolower((string) $database->fetchColumn()));
        $hold = $first->prepare('SELECT GET_LOCK(?, 0)');
        $hold->execute([$name]);
        self::assertSame(1, (int) $hold->fetchColumn());
        try {
            try {
                $writers = [
                    static fn () => $repository->setApprovalRequested($invoiceId),
                    static fn () => $repository->setApprovalDecision($invoiceId, 'approved', null, null),
                    static fn () => $repository->decideIfRequested($invoiceId, 'synthetic-approval-token', 'approved', null, null),
                    static fn () => $repository->resetApproval($invoiceId),
                ];
                foreach ($writers as $writer) {
                    try {
                        $writer();
                        self::fail('Schvalovací zápis nesmí obejít zámek obnovy.');
                    } catch (\RuntimeException $error) {
                        self::assertStringContainsString('Schvalovací tokeny právě mění', $error->getMessage());
                    }
                }
                $unchanged = $second->prepare('SELECT approval_status, approval_token, approval_receipt_hash FROM invoices WHERE id = ?');
                $unchanged->execute([$invoiceId]);
                self::assertSame(['none', null, null], $unchanged->fetch(PDO::FETCH_NUM));
            } finally {
                $unlock = $first->prepare('SELECT RELEASE_LOCK(?)');
                $unlock->execute([$name]);
                self::assertSame(1, (int) $unlock->fetchColumn());
            }

            $token = $repository->setApprovalRequested($invoiceId);
            self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $token);
            self::assertTrue($repository->decideIfRequested($invoiceId, $token, 'approved', null, null));
            self::assertFalse($repository->decideIfRequested($invoiceId, $token, 'approved', null, null));
            $repository->setApprovalDecision($invoiceId, 'rejected', null, 'Synthetic rejection');
            $receipt = $second->prepare('SELECT approval_status, approval_token, approval_receipt_hash FROM invoices WHERE id = ?');
            $receipt->execute([$invoiceId]);
            self::assertSame(['rejected', null, hash('sha256', $token)], $receipt->fetch(PDO::FETCH_NUM));
            $repository->resetApproval($invoiceId);
            $reset = $second->prepare('SELECT approval_status, approval_token, approval_receipt_hash FROM invoices WHERE id = ?');
            $reset->execute([$invoiceId]);
            self::assertSame(['none', null, null], $reset->fetch(PDO::FETCH_NUM));
        } finally {
            $connection->close();
        }
    }

    public function testNestedRunAndExceptionReleaseExactlyOnce(): void
    {
        $first = self::pdo($this->first);
        $second = self::pdo($this->second);
        try {
            ApprovalTokenLock::run($first, function () use ($first, $second): void {
                ApprovalTokenLock::run($first, function () use ($second): void {
                    try {
                        ApprovalTokenLock::run($second, static fn (): bool => true, 0);
                        self::fail('Vnořený callback nesmí pustit druhé spojení.');
                    } catch (\RuntimeException $error) {
                        self::assertStringContainsString('Schvalovací tokeny právě mění', $error->getMessage());
                    }
                });
                throw new \LogicException('synthetic callback failure');
            });
        } catch (\LogicException $error) {
            self::assertSame('synthetic callback failure', $error->getMessage());
        }
        self::assertSame(1, (int) ApprovalTokenLock::run($second, static fn (): mixed => self::selectOne($second), 0));
    }

    public function testOuterTransactionRetainsNamedLockBeyondCallbackAndCommit(): void
    {
        $first = self::pdo($this->first);
        $second = self::pdo($this->second);
        $first->beginTransaction();
        ApprovalTokenLock::run($first, static fn (): null => null);
        $first->commit();
        try {
            ApprovalTokenLock::run($second, static fn (): bool => true, 0);
            self::fail('COMMIT nesmí skrytě uvolnit pojmenovaný zámek.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Schvalovací tokeny právě mění', $error->getMessage());
        }
        // COMMIT sám pojmenovaný zámek neuvolní; uzavření poslední reference
        // skutečného PDO spojení ano.
        $this->first = null;
        unset($first);
        self::assertSame(1, (int) ApprovalTokenLock::run($second, static fn (): mixed => self::selectOne($second), 0));
    }

    private static function pdo(?PDO $pdo): PDO
    {
        self::assertInstanceOf(PDO::class, $pdo);
        return $pdo;
    }

    private static function selectOne(PDO $pdo): mixed
    {
        $statement = $pdo->query('SELECT 1');
        if ($statement === false) {
            throw new \RuntimeException('Synthetic SELECT failed.');
        }
        return $statement->fetchColumn();
    }

    private function createInvoiceFixture(PDO $pdo): int
    {
        $owner = $pdo->query('SELECT id, country_id, default_currency_id FROM supplier WHERE default_currency_id IS NOT NULL ORDER BY id LIMIT 1');
        self::assertNotFalse($owner);
        $supplier = $owner->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($supplier, 'Izolovaná DB potřebuje základní syntetický tenant.');
        $createClient = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $createClient->execute([
            $supplier['id'], 'Synthetic Approval Client', 'Test Street 1', 'Test City', '10000',
            $supplier['country_id'], $supplier['default_currency_id'],
        ]);
        $this->clientId = (int) $pdo->lastInsertId();
        $createInvoice = $pdo->prepare(
            'INSERT INTO invoices (supplier_id, invoice_type, client_id, issue_date, due_date, currency_id)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $createInvoice->execute([
            $supplier['id'], 'invoice', $this->clientId, '2026-01-01', '2026-01-15',
            $supplier['default_currency_id'],
        ]);
        $this->invoiceId = (int) $pdo->lastInsertId();
        return $this->invoiceId;
    }
}
