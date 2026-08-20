<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\ChartOfAccountsTemplate;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ověřuje seed směrné účtové osnovy (Epic F1): ChartOfAccountsSeeder naplní
 * chart_of_accounts z šablony, je idempotentní a saldní účty (343…) mají
 * normal_side = NULL. Zároveň smoke-test globálního seedu posting_rules.
 *
 * Vše běží v transakci, kterou tearDown rollbackne → DB zůstane netknutá
 * (nemažeme reálná uživatelská data). Soft-skip bez cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class ChartOfAccountsSeederTest extends TestCase
{
    private Connection $db;
    private ChartOfAccountsSeeder $seeder;
    private ChartOfAccountsRepository $coaRepo;
    private PostingRuleRepository $ruleRepo;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db       = $container->get(Connection::class);
            $this->seeder   = $container->get(ChartOfAccountsSeeder::class);
            $this->coaRepo  = $container->get(ChartOfAccountsRepository::class);
            $this->ruleRepo = $container->get(PostingRuleRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }

        // Vše v transakci — v tearDown rollbackneme, DB zůstane čistá.
        $pdo->beginTransaction();
        $this->inTx = true;
        // Izoluj test od případné existující osnovy suppliera (v rámci rollbacku).
        // Deník i alokace přijatých faktur referencují účty přes RESTRICT;
        // vše se po testu obnoví rollbackem.
        $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM purchase_invoice_vat_allocations WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM chart_of_accounts WHERE supplier_id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testSeedInsertsFullTemplate(): void
    {
        $expected = ChartOfAccountsTemplate::count();
        $inserted = $this->seeder->seedForSupplier($this->supplierId);

        self::assertSame($expected, $inserted, 'Seed má vložit všechny účty šablony.');
        self::assertSame($expected, $this->coaRepo->count($this->supplierId));
        self::assertTrue($this->seeder->hasChart($this->supplierId));
    }

    public function testSeedIsIdempotent(): void
    {
        $first = $this->seeder->seedForSupplier($this->supplierId);
        self::assertGreaterThan(0, $first);

        $second = $this->seeder->seedForSupplier($this->supplierId);
        self::assertSame(0, $second, 'Druhý běh nesmí vložit nic (idempotence).');
        self::assertSame(ChartOfAccountsTemplate::count(), $this->coaRepo->count($this->supplierId));
    }

    public function testSaldoAccountsHaveNullNormalSide(): void
    {
        $this->seeder->seedForSupplier($this->supplierId);

        $vat = $this->coaRepo->findByCode($this->supplierId, '343');
        self::assertNotNull($vat);
        self::assertSame('liability', $vat['account_type']);
        self::assertNull($vat['normal_side'], '343 DPH je saldní účet → normal_side NULL.');

        $revenue = $this->coaRepo->findByCode($this->supplierId, '602');
        self::assertNotNull($revenue);
        self::assertSame('revenue', $revenue['account_type']);
        self::assertSame('credit', $revenue['normal_side']);

        $receivable = $this->coaRepo->findByCode($this->supplierId, '311');
        self::assertNotNull($receivable);
        self::assertSame('asset', $receivable['account_type']);
        self::assertSame('debit', $receivable['normal_side']);

        $offbalance = $this->coaRepo->findByCode($this->supplierId, '799');
        self::assertNotNull($offbalance);
        self::assertSame('offbalance', $offbalance['account_type']);
    }

    public function testSeedIncludesNonDeductibleExpenseAnalytics(): void
    {
        $this->seeder->seedForSupplier($this->supplierId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_code, tax_deductibility
               FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code IN ("501.990", "511.990", "518.990", "548.990")
              ORDER BY account_code'
        );
        $stmt->execute([$this->supplierId]);

        self::assertSame(
            [
                ['account_code' => '501.990', 'tax_deductibility' => 'non_deductible'],
                ['account_code' => '511.990', 'tax_deductibility' => 'non_deductible'],
                ['account_code' => '518.990', 'tax_deductibility' => 'non_deductible'],
                ['account_code' => '548.990', 'tax_deductibility' => 'non_deductible'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function testGlobalPostingRulesSeeded(): void
    {
        // Globální seed z migrace 1006 — resolve funguje i pro suppliera bez override.
        $rule = $this->ruleRepo->resolve($this->supplierId, 'invoice.services.issued');
        self::assertNotNull($rule, 'Globální kontační pravidlo musí být naseedováno.');
        self::assertNull($rule['supplier_id'], 'Bez per-tenant override je pravidlo globální.');
        self::assertSame('311', $rule['debit_account_code']);
        self::assertSame('602', $rule['credit_account_code']);

        $map = $this->ruleRepo->effectiveMap($this->supplierId);
        self::assertArrayHasKey('payment.receivable.bank', $map);
        self::assertArrayHasKey('vat.settlement.liability', $map);
    }
}
