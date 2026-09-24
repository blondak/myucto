<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Assets\AccountSummaryCardService;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Souhrnná karta majetku z účtu bez karet (portfolio pozemků jen v deníku): vstupní
 * cena = počáteční stav účtu, pohyby deníku = zvýšení a snížení ceny, karta sedí na
 * zůstatek účtu a opakované srovnání ji přepočte.
 */
#[Group('integration')]
final class AccountSummaryCardTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private AccountSummaryCardService $summary;
    private AssetService $assets;
    private PostingService $posting;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->summary = $container->get(AccountSummaryCardService::class);
            $this->assets = $container->get(AssetService::class);
            $this->posting = $container->get(PostingService::class);
            $periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('assets', 'summary_account_code')) {
            $this->markTestSkipped('Chybí migrace assets.summary_account_code.');
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
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

    public function testSummaryCardMatchesAccountBalanceAndResyncsWithJournal(): void
    {
        $y1 = self::YEAR;
        $y2 = self::YEAR + 1;
        $this->post('opening', "$y1-01-01", '031', '701', 500000.00);
        $this->post('manual', "$y1-05-10", '031', '321', 200000.00);
        $this->post('manual', "$y1-09-01", '541', '031', 150000.00);
        $this->post('opening', "$y2-01-01", '031', '701', 550000.00);
        $this->post('manual', "$y2-03-01", '031', '321', 30000.00);

        $candidate = $this->candidate('031');
        self::assertSame([580000.0, true, null], [$candidate['balance'], $candidate['eligible'], $candidate['summary_asset_id']]);

        $result = $this->summary->sync($this->supplierId, '031', $this->userId);
        $card = $result['asset'];
        self::assertTrue($result['created']);
        self::assertSame([], $result['warnings']);
        self::assertSame(['in_use', 'none', null, '031', "$y1-01-01", 500000.0, 580000.0],
            [$card['status'], $card['tax_method'], $card['accumulated_account_code'], $card['summary_account_code'],
                $card['put_into_use_date'], (float) $card['input_price'], (float) $card['increased_input_price']]);
        self::assertSame([200000.0, -150000.0, 30000.0], array_map(static fn (array $i): float => (float) $i['amount'], $card['improvements']));

        // Nový pohyb účtu: srovnání kartu přepočte, druhá karta nevznikne.
        $this->post('manual', "$y2-06-01", '031', '321', 10000.00);
        $again = $this->summary->sync($this->supplierId, '031', $this->userId);
        self::assertFalse($again['created']);
        self::assertSame((int) $card['id'], (int) $again['asset']['id']);
        self::assertSame(590000.0, (float) $again['asset']['increased_input_price']);
        self::assertSame(590000.0, $this->candidate('031')['summary_value']);
    }

    public function testOpeningBalanceGapIsCarriedAsMovementWithWarning(): void
    {
        $y1 = self::YEAR;
        $this->post('opening', "$y1-01-01", '031', '701', 100000.00);
        $this->post('opening', ($y1 + 1) . '-01-01', '031', '701', 120000.00);

        $result = $this->summary->sync($this->supplierId, '031', $this->userId);
        self::assertSame(120000.0, (float) $result['asset']['increased_input_price'], 'Karta sedí na poslední stav účtu.');
        self::assertSame(['opening_gap'], array_column($result['warnings'], 'code'));
    }

    public function testAccountWithOwnCardsOrDepreciableAccountIsRefused(): void
    {
        $this->post('opening', self::YEAR . '-01-01', '031', '701', 100000.00);
        $this->assets->create($this->supplierId, [
            'inventory_number' => 'POZ-001', 'name' => 'Pozemek', 'asset_account_code' => '031', 'accumulated_account_code' => null,
            'input_price' => 40000.00, 'acquisition_date' => self::YEAR . '-01-01', 'put_into_use_date' => self::YEAR . '-01-01',
            'status' => 'in_use', 'tax_method' => 'none',
        ], ['user_id' => $this->userId]);

        self::assertFalse($this->candidate('031')['eligible']);
        foreach ([['031', 'account_has_cards'], ['022', 'validation_failed']] as [$code, $error]) {
            try {
                $this->summary->sync($this->supplierId, $code, $this->userId);
                self::fail('Účet ' . $code . ' souhrnnou kartu dostat nesmí.');
            } catch (AssetException $e) {
                self::assertSame($error, $e->errorCode);
            }
        }
    }

    /** @return array<string,mixed> */
    private function candidate(string $code): array
    {
        $rows = array_column($this->summary->candidates($this->supplierId), null, 'account_code');
        self::assertArrayHasKey($code, $rows);
        return $rows[$code];
    }

    private function post(string $source, string $date, string $debit, string $credit, float $amount): void
    {
        $this->posting->postDocument($this->supplierId, $source, null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date' => $date,
            'document_no' => 'T-' . $date,
            'description' => 'Pohyb pozemků',
            'posted' => true,
            'posted_by' => $this->userId,
            'user_id' => $this->userId,
        ]);
    }
}
