<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Syntetika s JEDINOU analytikou se účtuje na tu analytiku (PostingService::singleAnalyticMap).
 *
 * Engine volí spoustu účtů natvrdo podle druhu operace (563/663 kurzové rozdíly,
 * 648/548 dorovnání, 261 převod mezi vlastními účty), takže je nejde přesměrovat
 * kontací. Když má syntetika právě jednu analytiku, je odpověď jednoznačná.
 *
 * Nejdůležitější je tady to, co se přesměrovat NESMÍ — proto většina testů ověřuje
 * právě neaktivitu pravidla.
 */
#[Group('integration')]
final class SingleAnalyticRedirectTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private ChartOfAccountsRepository $accounts;
    private JournalEntryRepository $journal;

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
            $this->posting  = $container->get(PostingService::class);
            $this->accounts = $container->get(ChartOfAccountsRepository::class);
            $this->journal  = $container->get(JournalEntryRepository::class);
            $periods        = $container->get(AccountingPeriodRepository::class);
            $seeder         = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── pomocníci ─────────────────────────────────────────────────────────────

    private function addAnalytic(string $parentCode, string $code, bool $active = true): void
    {
        $parent = $this->accounts->findByCode($this->supplierId, $parentCode);
        self::assertNotNull($parent, "Syntetika {$parentCode} musí být v osnově.");
        $this->accounts->insert($this->supplierId, [
            'account_code' => $code,
            'name'         => 'Analytika ' . $code,
            'account_type' => $parent['account_type'],
            'normal_side'  => $parent['normal_side'],
            'is_synthetic' => false,
            'parent_id'    => (int) $parent['id'],
            'is_active'    => $active,
        ]);
    }

    /** Zaúčtuje zápis a vrátí kódy účtů, na kterých řádky reálně skončily. */
    private function postAndReadCodes(string $debit, string $credit, int $sourceId = 0): array
    {
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', $sourceId ?: null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => 100.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'description' => 'Test']);

        $codes = [];
        foreach ($this->accounts->listForTenant($this->supplierId, true) as $account) {
            $codes[(int) $account['id']] = (string) $account['account_code'];
        }
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $out[(string) $line['side']] = $codes[(int) $line['account_id']] ?? '?';
        }

        return $out;
    }

    private function setRedirect(bool $enabled): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, single_analytic_redirect)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE single_analytic_redirect = VALUES(single_analytic_redirect)'
        )->execute([$this->supplierId, $enabled ? 1 : 0]);
    }

    // ── testy ─────────────────────────────────────────────────────────────────

    /** Jádro: 563 s jedinou analytikou 563.100 → kurzová ztráta padne na analytiku. */
    public function testSyntheticWithSingleAnalyticRedirects(): void
    {
        $this->addAnalytic('563', '563.100');

        $codes = $this->postAndReadCodes('563', '311');

        self::assertSame('563.100', $codes['debit'], 'Syntetika s jedinou analytikou se přesměruje.');
        self::assertSame('311', $codes['credit'], 'Účet bez analytik zůstává beze změny.');
    }

    /** 261 — přesně cesta převodu mezi vlastními účty (TransferPairService). */
    public function testCashInTransitRedirectsToItsOnlyAnalytic(): void
    {
        $this->addAnalytic('261', '261.100');

        $codes = $this->postAndReadCodes('261', '311');

        self::assertSame('261.100', $codes['debit']);
    }

    /** Firma bez analytik nesmí zaznamenat ŽÁDNOU změnu — to je hlavní bezpečnostní podmínka. */
    public function testTenantWithoutAnalyticsIsUnaffected(): void
    {
        $codes = $this->postAndReadCodes('563', '311');

        self::assertSame('563', $codes['debit']);
        self::assertSame('311', $codes['credit']);
    }

    /** Dvě analytiky = volba není jednoznačná → rozhoduje dál kontace/kontext. */
    public function testTwoAnalyticsLeaveTheSyntheticAlone(): void
    {
        $this->addAnalytic('563', '563.100');
        $this->addAnalytic('563', '563.900');

        $codes = $this->postAndReadCodes('563', '311');

        self::assertSame('563', $codes['debit'], 'Se dvěma analytikami se nehádá — nechá syntetiku.');
    }

    /** Neaktivní analytika se nepočítá — jinak by přesměr mířil na vypnutý účet. */
    public function testInactiveAnalyticIsNotCounted(): void
    {
        $this->addAnalytic('563', '563.100');
        $this->addAnalytic('563', '563.900', false);

        $codes = $this->postAndReadCodes('563', '311');

        self::assertSame('563.100', $codes['debit'], 'Jediná AKTIVNÍ analytika přesměr spustí.');
    }

    /**
     * REGRESE, kvůli které pravidlo nesmí být jen „počet == 1": šablona osnovy veze
     * pod 311 jedinou analytiku `311D` (dlouhodobé pohledávky). To je úzce účelová
     * PODMNOŽINA, ne náhrada — bez tvarové podmínky by se každému tenantovi hned po
     * naseedování přesypaly VŠECHNY pohledávky na dlouhodobé.
     */
    public function testTemplateLetterAnalyticsNeverCapturTheirSynthetic(): void
    {
        $codes = $this->postAndReadCodes('311', '602');
        self::assertSame('311', $codes['debit'], '311D nesmí spolknout běžné pohledávky.');

        $codes = $this->postAndReadCodes('461', '221', 0);
        self::assertSame('461', $codes['debit'], '461K nesmí spolknout dlouhodobé úvěry.');
    }

    /** 345.100 je OSS daň jiného členského státu — daň z nemovitostí na ni nepatří. */
    public function testOssAnalyticNeverCapturesAccount345(): void
    {
        $codes = $this->postAndReadCodes('345', '221');

        self::assertSame('345', $codes['debit'], '345 je kontextové — analytiku volí OSS logika.');
    }

    /** Banka a pokladna: analytiku vybírá výpis/pokladna dokladu, ne osnova. */
    public function testBankAndCashStayContextDriven(): void
    {
        $this->addAnalytic('221', '221.100');
        $this->addAnalytic('211', '211.100');

        $codes = $this->postAndReadCodes('221', '211');

        self::assertSame('221', $codes['debit'], 'Bankovní nohu řeší BankAnalyticResolver.');
        self::assertSame('211', $codes['credit'], 'Pokladní nohu řeší doklad pokladny.');
    }

    /** DPH má vlastní logiku směru (vstup/výstup/zúčtování). */
    public function testVatSyntheticStaysContextDriven(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE chart_of_accounts SET is_active = 0
              WHERE supplier_id = ? AND account_code IN ('343.200', '343.900')"
        )->execute([$this->supplierId]);

        $codes = $this->postAndReadCodes('343', '221');

        self::assertSame('343', $codes['debit'], 'I s jedinou zbylou analytikou zůstává 343 na kontextu.');
    }

    public function testKillSwitchDisablesTheRedirect(): void
    {
        $this->addAnalytic('563', '563.100');
        $this->setRedirect(false);

        $codes = $this->postAndReadCodes('563', '311');

        self::assertSame('563', $codes['debit'], 'Vypnutý přepínač vrací chování před migrací 1326.');
    }

    public function testRedirectIsOnByDefaultWhenSettingsRowExists(): void
    {
        $this->addAnalytic('563', '563.100');
        $this->setRedirect(true);

        $codes = $this->postAndReadCodes('563', '311');

        self::assertSame('563.100', $codes['debit']);
    }

    /** Přesměr musí být stabilní: re-post téhož dokladu nesmí účet přehodit. */
    public function testRedirectIsStableAcrossRepost(): void
    {
        $this->addAnalytic('648', '648.100');

        $first = $this->postAndReadCodes('311', '648', 770001);
        $second = $this->postAndReadCodes('311', '648', 770001);

        self::assertSame('648.100', $first['credit']);
        self::assertSame($first['credit'], $second['credit']);
    }
}
