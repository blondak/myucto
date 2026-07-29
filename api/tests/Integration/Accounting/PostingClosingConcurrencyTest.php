<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EP-3 — souběh účtování × uzávěrky. Bariérový scénář nad DVĚMA nezávislými PDO
 * spojeními (dva kontejnery = dvě Connection = dvě DB sessions):
 *
 *   A (uzávěrka): v transakci zamkne řádek období SELECT … FOR UPDATE a přepne ho
 *                 na 'closing'. NEcommitne (drží zámek).
 *   B (účtování): pokusí se zaúčtovat do TÉHOŽ období. Jeho postDocument otevře
 *                 vlastní transakci a v ní čte období rovněž FOR UPDATE → BLOKUJE na
 *                 zámku A. S krátkým innodb_lock_wait_timeout to po ~1 s spadne (1205),
 *                 čímž je prokázáno, že se souběh SERIALIZUJE (dřív B běžel bez zámku
 *                 a stav ověřoval PŘED začátkem transakce → propadl by do uzavíraného
 *                 období). Po commitu A vidí B pod (uvolněným) zámkem 'closing' a
 *                 zápis ODMÍTNE (period_not_open).
 *
 * Akceptační kritérium: po zahájení uzávěrky NELZE zaúčtovat další zápis do stejného
 * období — v deníku nezůstane žádný zápis B.
 *
 * COMMITNUTÝ seed (druhé spojení musí seed vidět) → explicitní úklid v tearDown
 * (DELETE dodavatele → CASCADE + activity_log). Soft-skip bez cfg.php / DB.
 */
#[Group('integration')]
final class PostingClosingConcurrencyTest extends TestCase
{
    private const YEAR = 2099;
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $dbA;
    private Connection $dbB;
    private AccountingPeriodRepository $periodsA;
    private PostingService $postingB;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            // Dva NEZÁVISLÉ kontejnery → dvě Connection (dvě DB sessions) pro souběh.
            // Kontejner B se staví v nesdílené zóně: testovací běh jinak recykluje jedno
            // PDO přes všechny Connection a obě „strany" souběhu by seděly v téže DB
            // session — zámek by se sám sobě nikdy nepostavil do cesty a test by
            // serializaci jen předstíral.
            $containerA = Bootstrap::buildApp()->getContainer();
            $containerB = Connection::withoutSharedTestConnection(
                static fn () => Bootstrap::buildApp()->getContainer()
            );
            $this->dbA       = $containerA->get(Connection::class);
            $this->dbB       = $containerB->get(Connection::class);
            $this->periodsA  = $containerA->get(AccountingPeriodRepository::class);
            $this->postingB  = $containerB->get(PostingService::class);
            $seederA         = $containerA->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdoA = $this->dbA->pdo();
        $this->userId = (int) ($pdoA->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdoA->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdoA->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdoA->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        // COMMITNUTÝ seed přes A — spojení B ho musí vidět (uncommitted je session-privátní).
        $stmt = $pdoA->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        );
        $stmt->execute(['EP-3 souběh s.r.o.', $czId, 'ep3-concurrency-' . uniqid() . '@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdoA->lastInsertId();

        $seederA->seedForSupplier($this->supplierId);
        $this->periodId = $this->periodsA->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);
    }

    protected function tearDown(): void
    {
        if (isset($this->dbB)) {
            $pdoB = $this->dbB->pdo();
            if ($pdoB->inTransaction()) {
                $pdoB->rollBack();
            }
            $this->dbB->close();
        }
        if (isset($this->dbA) && $this->supplierId !== 0) {
            $pdoA = $this->dbA->pdo();
            if ($pdoA->inTransaction()) {
                $pdoA->rollBack();
            }
            $pdoA->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdoA->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
            $this->dbA->close();
        }
    }

    public function testConcurrentClosingStartBlocksThenRejectsPosting(): void
    {
        $pdoA = $this->dbA->pdo();
        $pdoB = $this->dbB->pdo();

        // A: zahájení uzávěrky — zamkni řádek období FOR UPDATE a přepni na 'closing'.
        // Bez commitu (drží zámek), přesně jako ClosingService::start uvnitř své tx.
        $pdoA->beginTransaction();
        $locked = $this->periodsA->findForUpdate($this->periodId, $this->supplierId);
        self::assertNotNull($locked, 'A zamkne řádek období FOR UPDATE.');
        $pdoA->prepare(
            "UPDATE accounting_periods SET status = 'closing', row_version = row_version + 1
              WHERE id = ? AND supplier_id = ?"
        )->execute([$this->periodId, $this->supplierId]);

        // B: pokus zaúčtovat do téhož období. postDocument čte období FOR UPDATE ve
        // vlastní tx → blokuje na zámku A. Krátký timeout → 1205 (serializace prokázána).
        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            $this->postDocumentB();
        } catch (PostingException $e) {
            self::fail('B nesmí projít validací stavu — má být zablokováno zámkem A, ne odmítnuto (' . $e->errorCode . ').');
        } catch (\PDOException $e) {
            // Lock wait timeout (1205) — B čeká na zámek období, který drží uzávěrka A.
            $blocked = true;
        } catch (\Throwable $e) {
            $blocked = true;
        }
        self::assertTrue($blocked, 'Účtování B je serializováno zámkem období, který drží uzávěrka A (EP-3).');
        if ($pdoB->inTransaction()) {
            $pdoB->rollBack();
        }

        // A dokončí uzávěrku (commit → 'closing' je trvale viditelné pro B).
        $pdoA->commit();

        // B zkusí znovu: teď pod (uvolněným) zámkem uvidí 'closing' → period_not_open.
        try {
            $this->postDocumentB();
            self::fail('Po zahájení uzávěrky NELZE zaúčtovat do stejného období.');
        } catch (PostingException $e) {
            self::assertSame('period_not_open', $e->errorCode, 'Zápis do uzavíraného období je odmítnut.');
        }

        // Do uzavíraného období se nezaúčtoval žádný zápis (akceptační kritérium).
        $stmt = $this->dbB->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual'"
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Žádný zápis B se do uzavíraného období nepropsal.');
    }

    private function postDocumentB(): void
    {
        $this->postingB->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
        ], ['entry_date' => self::ENDS_ON, 'posted_by' => $this->userId]);
    }
}
