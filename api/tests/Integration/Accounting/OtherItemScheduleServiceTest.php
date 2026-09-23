<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\Obligations\OtherItemForecastService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class OtherItemScheduleServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private OtherItemScheduleService $schedules;
    private OtherItemForecastService $forecast;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->items = $container->get(OtherItemService::class);
        $this->schedules = $container->get(OtherItemScheduleService::class);
        $this->forecast = $container->get(OtherItemForecastService::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testMonthlyGenerationClampsDayAndNeverDuplicatesOrPosts(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'monthly'], null);
        self::assertCount(1, $schedule['occurrences']);
        $generated = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null);
        self::assertCount(2, $generated['created_ids']);
        self::assertSame([], $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null)['created_ids']);
        $dates = [];
        foreach ($generated['created_ids'] as $id) {
            $item = $this->items->get($this->supplierId, $id);
            $dates[] = [$item['issued_on'], $item['due_on']];
            self::assertSame('draft', $item['status']);
            self::assertNull($item['journal_entry_id']);
        }
        self::assertSame([['2099-02-28', '2099-03-05'], ['2099-03-31', '2099-04-05']], $dates);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM other_item_schedule_occurrences WHERE supplier_id = ? AND schedule_id = ?');
        $stmt->execute([$this->supplierId, $schedule['id']]);
        self::assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testTenantBoundaryAndPauseBlockGeneration(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'quarterly'], null);
        try {
            $this->schedules->generate($this->supplierId + 100000, (int) $schedule['id'], '2099-12-31', null);
            self::fail('Cizí rozvrh nesmí být dostupný.');
        } catch (OtherItemException $e) {
            self::assertSame('schedule_not_found', $e->errorCode);
        }
        $this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'paused');
        try {
            $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-12-31', null);
            self::fail('Pozastavený rozvrh nesmí generovat.');
        } catch (OtherItemException $e) {
            self::assertSame('schedule_paused', $e->errorCode);
        }
    }

    public function testInstallmentsReplaceSingleCashflowWithoutDuplicatingResult(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $plan = $this->schedules->setInstallments($this->supplierId, (int) $first['id'], [
            ['due_on' => '2099-02-05', 'amount' => 500],
            ['due_on' => '2099-03-05', 'amount' => 700],
        ]);
        self::assertCount(2, $plan);
        $forecast = $this->forecast->dueBetween($this->supplierId, '2099-01-01', '2099-03-31');
        self::assertSame(['2099-02-05', '2099-03-05'], array_column($forecast, 'due_on'));
        self::assertSame([500.0, 700.0], array_column($forecast, 'remaining'));
        self::assertSame(1200.0, array_sum(array_column($forecast, 'remaining')));
        try {
            $this->items->update($this->supplierId, (int) $first['id'], $this->input(['amount' => 1300]), null);
            self::fail('Změna částky nesmí rozbít rozvrh splátek.');
        } catch (OtherItemException $e) {
            self::assertSame('has_installments', $e->errorCode);
        }
        self::assertSame([], $this->schedules->setInstallments($this->supplierId, (int) $first['id'], []));
        $original = $this->forecast->dueBetween($this->supplierId, '2099-01-01', '2099-03-31');
        self::assertCount(1, $original);
        self::assertSame('2099-02-05', $original[0]['due_on']);
        self::assertSame(1200.0, $original[0]['remaining']);
    }

    public function testGeneratedItemKeepsSourceContractAndSourceCannotBeDeleted(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $userId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $documents = new DocumentRepository($this->db);
        $documentId = $documents->insert([
            'supplier_id' => $this->supplierId, 'folder_id' => null,
            'title' => 'Syntetická nájemní smlouva', 'description' => null,
            'original_name' => 'smlouva.pdf', 'filename' => str_repeat('e', 64),
            'sha256' => str_repeat('e', 64), 'mime_type' => 'application/pdf',
            'size_bytes' => 100, 'doc_type' => 'pdf', 'uploaded_by' => $userId,
            'scope' => 'company', 'owner_user_id' => null,
        ]);
        $this->pdo->prepare("INSERT INTO document_links (document_id, supplier_id, entity_type, entity_id)
            VALUES (?, ?, 'other_item', ?)")->execute([$documentId, $this->supplierId, $first['id']]);

        $schedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'monthly'], null);
        try {
            $this->items->deleteDraft($this->supplierId, (int) $first['id']);
            self::fail('Zdroj aktivního rozvrhu nesmí zmizet.');
        } catch (OtherItemException $e) {
            self::assertSame('has_schedule', $e->errorCode);
        }
        $generated = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null);
        $linked = $this->pdo->prepare("SELECT COUNT(*) FROM document_links
            WHERE supplier_id = ? AND document_id = ? AND entity_type = 'other_item' AND entity_id = ?");
        $linked->execute([$this->supplierId, $documentId, $generated['created_ids'][0]]);
        self::assertSame(1, (int) $linked->fetchColumn());
        $this->items->deleteDraft($this->supplierId, $generated['created_ids'][0]);
        self::assertSame('cancelled', $this->items->get($this->supplierId, $generated['created_ids'][0])['status']);
        self::assertSame([], $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null)['created_ids']);
    }

    public function testPaidAmountSettlesOldestInstallmentFirst(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $this->schedules->setInstallments($this->supplierId, (int) $first['id'], [
            ['due_on' => '2099-02-05', 'amount' => 500],
            ['due_on' => '2099-03-05', 'amount' => 700],
        ]);
        $hash = hash('sha256', 'synthetic-other-item-' . $this->supplierId);
        $this->pdo->prepare('INSERT INTO bank_statements
            (supplier_id, file_name, file_hash, account_number, bank_code, currency,
             statement_date, prev_balance, curr_balance, credit_total, debit_total, transaction_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 600, 600, 0, 1)')
            ->execute([$this->supplierId, 'synteticky-vypis.gpc', $hash,
                '1000000005/0100', '0100', 'CZK', '2099-02-06']);
        $statementId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO bank_transactions (statement_id, posted_at, amount, currency)
            VALUES (?, ?, 600, ?)')->execute([$statementId, '2099-02-06', 'CZK']);
        $transactionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO other_item_allocations
            (supplier_id, other_item_id, bank_transaction_id, amount, payment_on)
            VALUES (?, ?, ?, 600, ?)')->execute([$this->supplierId, $first['id'], $transactionId, '2099-02-06']);

        $remaining = $this->forecast->dueBetween($this->supplierId, '2099-01-01', '2099-03-31');
        self::assertCount(1, $remaining);
        self::assertSame('2099-03-05', $remaining[0]['due_on']);
        self::assertSame(600.0, $remaining[0]['remaining']);
    }

    private function input(array $changes = []): array
    {
        return array_replace([
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'issued_on' => '2099-01-31', 'due_on' => '2099-02-05',
            'currency' => 'CZK', 'amount' => 1200, 'counter_account_code' => '518',
        ], $changes);
    }
}
