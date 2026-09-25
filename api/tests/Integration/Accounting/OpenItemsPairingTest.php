<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\AccountStatementService;
use MyInvoice\Service\Accounting\Reports\OpenItemsService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Otevřené položky a okruhy párování na libovolném účtu (migrace 1895).
 *
 * Hlavní invariant: Σ otevřených částek k datu = zůstatek účtu k datu spočtený
 * cestou opisu účtu, ať jsou okruhy vyrovnané, částečné, nebo žádné.
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne.
 */
#[Group('integration')]
final class OpenItemsPairingTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private OpenItemsService $openItems;
    private AccountStatementService $statement;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    /** @var array{user_id:?int, ip:?string, user_agent:?string} */
    private array $meta = ['user_id' => null, 'ip' => null, 'user_agent' => null];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db        = $container->get(Connection::class);
            $this->posting   = $container->get(PostingService::class);
            $this->openItems = $container->get(OpenItemsService::class);
            $this->statement = $container->get(AccountStatementService::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $this->journal   = $container->get(JournalEntryRepository::class);
            $this->seeder    = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $baseSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($baseSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $this->meta['user_id'] = $this->userId;

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierId = $this->isolatedSupplier($baseSupplier, 'Párování test s.r.o.');
        $this->otherSupplierId = $this->isolatedSupplier($baseSupplier, 'Cizí firma s.r.o.');
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

    public function testCreateAndCancelPairingOnTransitAccount(): void
    {
        $transit = $this->accountId('261');
        $out = $this->manual([self::l('261', 'debit', 1000.00), self::l('221', 'credit', 1000.00)], '-03-10');
        $in  = $this->manual([self::l('221', 'debit', 1000.00), self::l('261', 'credit', 1000.00)], '-03-11');

        $before = $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', true, 1, 100);
        self::assertSame(2, $before['open_count']);
        self::assertSame(0, self::cents($before['open_total']));
        self::assertSame(0, self::cents($before['difference']));

        $lines = [$this->lineId($out, '261'), $this->lineId($in, '261')];
        $pairing = $this->openItems->create($this->supplierId, $transit, $lines, 'Převod mezi účty', $this->meta);
        self::assertTrue($pairing['balanced']);
        self::assertCount(2, $pairing['items']);

        $after = $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', true, 1, 100);
        self::assertSame(0, $after['open_count'], 'Vyrovnaný okruh nemá otevřené položky.');
        self::assertSame([], $after['items']);

        $all = $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', false, 1, 100);
        self::assertSame(2, $all['total']);
        foreach ($all['items'] as $it) {
            self::assertSame($pairing['id'], $it['pairing_id']);
            self::assertSame(0, self::cents($it['open_amount']));
        }

        self::assertSame(1, $this->openItems->delete($this->supplierId, [$pairing['id']], $this->meta));
        $reopened = $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', true, 1, 100);
        self::assertSame(2, $reopened['open_count'], 'Zrušený okruh vrací řádky mezi otevřené.');
    }

    public function testPartialPairingKeepsOpenTotalEqualToBalance(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual([self::l('261', 'debit', 1000.00), self::l('221', 'credit', 1000.00)], '-01-10');
        $b = $this->manual([self::l('221', 'debit', 600.00), self::l('261', 'credit', 600.00)], '-01-20');
        $this->manual([self::l('261', 'debit', 300.00), self::l('221', 'credit', 300.00)], '-02-05');

        $this->openItems->create($this->supplierId, $transit, [$this->lineId($a, '261'), $this->lineId($b, '261')], null, $this->meta);

        $data = $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', true, 1, 100);
        self::assertSame(2, $data['open_count']);
        self::assertSame(self::cents(700.00), self::cents($data['open_total']));
        self::assertSame(self::cents(700.00), self::cents($data['balance']));
        self::assertSame(0, self::cents($data['difference']));
        self::assertSame(self::cents(400.00), self::cents($data['items'][0]['open_amount']), 'Z nevyrovnaného okruhu zůstává otevřený rozdíl.');
        self::assertSame(self::cents(700.00), self::cents($data['open_md']));
        self::assertSame(0, self::cents($data['open_d']));
        self::assertSame(self::cents(700.00), self::cents($data['items'][1]['open_balance']), 'Otevřený zůstatek běží přes stránku.');

        $statement = $this->statement->build($this->supplierId, $transit, self::YEAR . '-01-01', self::YEAR . '-12-31', 1, 50);
        self::assertSame(self::cents($statement['closing_balance']), self::cents($data['open_total']), 'Σ otevřených = konečný zůstatek opisu účtu.');
    }

    public function testAsOfDateExcludesLaterMembers(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual([self::l('261', 'debit', 500.00), self::l('221', 'credit', 500.00)], '-06-29');
        $b = $this->manual([self::l('221', 'debit', 500.00), self::l('261', 'credit', 500.00)], '-07-02');
        $this->openItems->create($this->supplierId, $transit, [$this->lineId($a, '261'), $this->lineId($b, '261')], null, $this->meta);

        $june = $this->openItems->build($this->supplierId, $transit, self::YEAR . '-06-30', true, 1, 100);
        self::assertSame(1, $june['open_count'], 'K 30. 6. peníze ještě nedorazily.');
        self::assertSame(self::cents(500.00), self::cents($june['open_total']));
        self::assertSame(0, self::cents($june['difference']));
    }

    public function testOpeningEntryLineIsAnOpenItemAndPairable(): void
    {
        $advances = $this->accountId('395');
        $opening = $this->technical([self::l('395', 'debit', 2500.00), self::l('701', 'credit', 2500.00)], '-01-01', 'opening');
        $settle = $this->manual([self::l('221', 'debit', 2500.00), self::l('395', 'credit', 2500.00)], '-04-15');

        $data = $this->openItems->build($this->supplierId, $advances, self::YEAR . '-12-31', true, 1, 100);
        self::assertSame(2, $data['open_count'], 'Počáteční stav je otevřená položka.');
        self::assertSame(0, self::cents($data['difference']));

        $this->openItems->create($this->supplierId, $advances, [$this->lineId($opening, '395'), $this->lineId($settle, '395')], null, $this->meta);
        $after = $this->openItems->build($this->supplierId, $advances, self::YEAR . '-12-31', true, 1, 100);
        self::assertSame(0, $after['open_count']);

        $march = $this->openItems->build($this->supplierId, $advances, self::YEAR . '-03-31', true, 1, 100);
        self::assertSame(1, $march['open_count']);
        self::assertSame(self::cents(2500.00), self::cents($march['open_total']));
        self::assertSame(self::cents(2500.00), self::cents($march['balance']));
    }

    public function testSuggestionsPairSameAmountOppositeSidesWithinWindow(): void
    {
        $transit = $this->accountId('261');
        $this->manual([self::l('261', 'debit', 1500.00), self::l('221', 'credit', 1500.00)], '-05-02');
        $this->manual([self::l('221', 'debit', 1500.00), self::l('261', 'credit', 1500.00)], '-05-04');
        $this->manual([self::l('261', 'debit', 800.00), self::l('221', 'credit', 800.00)], '-05-10');
        $this->manual([self::l('221', 'debit', 800.00), self::l('261', 'credit', 800.00)], '-08-10');
        $this->manual([self::l('261', 'debit', 90.00), self::l('221', 'credit', 90.00)], '-05-11');

        $suggestions = $this->openItems->suggestions($this->supplierId, $transit, self::YEAR . '-12-31', 7);
        self::assertCount(1, $suggestions, 'Mimo okno 7 dní se nepáruje.');
        self::assertSame('amount', $suggestions[0]['kind']);
        self::assertSame(self::cents(1500.00), self::cents($suggestions[0]['amount']));
        self::assertSame(2, $suggestions[0]['days_apart']);

        self::assertSame(1, $this->openItems->applySuggestions($this->supplierId, $transit, self::YEAR . '-12-31', 7, null, $this->meta));
        $data = $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', true, 1, 100);
        self::assertSame(3, $data['open_count']);
        self::assertSame(0, self::cents($data['difference']));

        self::assertCount(1, $this->openItems->suggestions($this->supplierId, $transit, self::YEAR . '-12-31', 120), 'Širší okno najde i srpnový převod.');
    }

    public function testLinesOfDifferentAccountsAreRejected(): void
    {
        $transit = $this->accountId('261');
        $entry = $this->manual([self::l('261', 'debit', 100.00), self::l('221', 'credit', 100.00)], '-02-01');

        $this->expectRejection('account_mismatch', fn () => $this->openItems->create(
            $this->supplierId, $transit, [$this->lineId($entry, '261'), $this->lineId($entry, '221')], null, $this->meta,
        ));
    }

    public function testDifferentAnalyticsUnderOneSyntheticAreRejected(): void
    {
        $parent = $this->accountId('395');
        $this->analytic('395', '395.001');
        $this->analytic('395', '395.002');
        $a = $this->manual([self::l('395.001', 'debit', 100.00), self::l('221', 'credit', 100.00)], '-02-01');
        $b = $this->manual([self::l('221', 'debit', 100.00), self::l('395.002', 'credit', 100.00)], '-02-02');
        $c = $this->manual([self::l('221', 'debit', 100.00), self::l('395.001', 'credit', 100.00)], '-02-03');

        $this->expectRejection('account_mismatch', fn () => $this->openItems->create(
            $this->supplierId, $parent, [$this->lineId($a, '395.001'), $this->lineId($b, '395.002')], null, $this->meta,
        ));

        $pairing = $this->openItems->create($this->supplierId, $parent, [$this->lineId($a, '395.001'), $this->lineId($c, '395.001')], null, $this->meta);
        self::assertSame($this->accountId('395.001'), $pairing['account_id'], 'Okruh leží na analytice, ne na syntetice.');

        $data = $this->openItems->build($this->supplierId, $parent, self::YEAR . '-12-31', true, 1, 100);
        self::assertSame(1, $data['open_count']);
        self::assertSame('395.002', $data['items'][0]['account_code']);
    }

    public function testLineCanBeInOnlyOnePairing(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual([self::l('261', 'debit', 100.00), self::l('221', 'credit', 100.00)], '-02-01');
        $b = $this->manual([self::l('221', 'debit', 100.00), self::l('261', 'credit', 100.00)], '-02-02');
        $c = $this->manual([self::l('221', 'debit', 50.00), self::l('261', 'credit', 50.00)], '-02-03');
        $this->openItems->create($this->supplierId, $transit, [$this->lineId($a, '261'), $this->lineId($b, '261')], null, $this->meta);

        $this->expectRejection('line_already_paired', fn () => $this->openItems->create(
            $this->supplierId, $transit, [$this->lineId($a, '261'), $this->lineId($c, '261')], null, $this->meta,
        ));
    }

    public function testSupplierIsolation(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual([self::l('261', 'debit', 100.00), self::l('221', 'credit', 100.00)], '-02-01');
        $b = $this->manual([self::l('221', 'debit', 100.00), self::l('261', 'credit', 100.00)], '-02-02');
        $pairing = $this->openItems->create($this->supplierId, $transit, [$this->lineId($a, '261'), $this->lineId($b, '261')], null, $this->meta);

        $foreignTransit = $this->accountId('261', $this->otherSupplierId);
        $this->expectRejection('not_found', fn () => $this->openItems->create(
            $this->otherSupplierId, $foreignTransit, [$this->lineId($a, '261'), $this->lineId($b, '261')], null, $this->meta,
        ));
        $this->expectRejection('not_found', fn () => $this->openItems->pairing($this->otherSupplierId, $pairing['id']));
        $this->expectRejection('not_found', fn () => $this->openItems->delete($this->otherSupplierId, [$pairing['id']], $this->meta));
        $this->expectRejection('account_not_found', fn () => $this->openItems->build($this->otherSupplierId, $transit, self::YEAR . '-12-31', true, 1, 100));

        self::assertCount(2, $this->openItems->pairing($this->supplierId, $pairing['id'])['items'], 'Cizí firma okruh nezrušila.');
    }

    public function testReversalReleasesOriginalAndSuggestsItWithStorno(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual([self::l('261', 'debit', 400.00), self::l('221', 'credit', 400.00)], '-03-01');
        $b = $this->manual([self::l('221', 'debit', 400.00), self::l('261', 'credit', 400.00)], '-03-02');
        $pairing = $this->openItems->create($this->supplierId, $transit, [$this->lineId($a, '261'), $this->lineId($b, '261')], null, $this->meta);

        $storno = $this->posting->reverse($this->supplierId, $a, ['entry_date' => self::YEAR . '-09-30', 'user_id' => $this->userId]);

        $this->expectRejection('not_found', fn () => $this->openItems->pairing($this->supplierId, $pairing['id']));

        $suggestions = $this->openItems->suggestions($this->supplierId, $transit, self::YEAR . '-12-31', 0);
        self::assertCount(1, $suggestions);
        self::assertSame('reversal', $suggestions[0]['kind'], 'Originál se stornem se navrhne bez ohledu na datum.');
        self::assertEqualsCanonicalizing([$a, $storno], array_column($suggestions[0]['lines'], 'entry_id'));

        $data = $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', false, 1, 100);
        self::assertSame(0, self::cents($data['difference']));
        $partner = array_values(array_filter($data['items'], static fn (array $i): bool => $i['entry_id'] === $b));
        self::assertNull($partner[0]['pairing_id'], 'Protějšek stornovaného řádku je znovu volný, ne v okruhu o jednom řádku.');
        self::assertSame(self::cents(400.00), self::cents($partner[0]['open_amount']));
        self::assertSame($this->auditCount('accounting.pairing_released', $a), 1);

        $c = $this->manual([self::l('261', 'debit', 400.00), self::l('221', 'credit', 400.00)], '-03-05');
        $again = $this->openItems->create($this->supplierId, $transit, [$this->lineId($b, '261'), $this->lineId($c, '261')], null, $this->meta);
        self::assertTrue($again['balanced'], 'Uvolněný protějšek jde spárovat s opraveným zápisem.');
    }

    public function testRepostKeepsPairingUnlessLineMovedToAnotherAccount(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual([self::l('261', 'debit', 700.00), self::l('221', 'credit', 700.00)], '-03-01');
        $b = $this->manual([self::l('221', 'debit', 700.00), self::l('261', 'credit', 700.00)], '-03-02');
        $pairing = $this->openItems->create($this->supplierId, $transit, [$this->lineId($a, '261'), $this->lineId($b, '261')], null, $this->meta);
        $oldLineId = $this->lineId($a, '261');

        $this->rewrite($a, [
            ['account_id' => $transit, 'side' => 'debit', 'amount' => 700.00, 'line_no' => 0],
            ['account_id' => $this->accountId('221'), 'side' => 'credit', 'amount' => 700.00, 'line_no' => 1],
        ]);
        self::assertNotSame($oldLineId, $this->lineId($a, '261'), 'Přepis zápisu mění id řádků.');
        self::assertCount(2, $this->openItems->pairing($this->supplierId, $pairing['id'])['items'], 'Okruh přeúčtování přežil.');
        self::assertSame(0, $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', true, 1, 100)['open_count']);

        $this->rewrite($a, [
            ['account_id' => $this->accountId('211'), 'side' => 'debit', 'amount' => 700.00, 'line_no' => 0],
            ['account_id' => $this->accountId('221'), 'side' => 'credit', 'amount' => 700.00, 'line_no' => 1],
        ]);
        $this->expectRejection('not_found', fn () => $this->openItems->pairing($this->supplierId, $pairing['id']));
        $open = $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', true, 1, 100);
        self::assertSame(1, $open['open_count'], 'Řádek přesunutý na jiný účet z okruhu vypadl a protějšek je otevřený.');
        self::assertNull($open['items'][0]['pairing_id']);
        self::assertSame(1, $this->auditCount('accounting.pairing_released', $a));
    }

    public function testRemovingLineDissolvesPairingLeftWithOneLine(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual([self::l('261', 'debit', 300.00), self::l('221', 'credit', 300.00)], '-03-01');
        $b = $this->manual([self::l('221', 'debit', 300.00), self::l('261', 'credit', 300.00)], '-03-02');
        $pairing = $this->openItems->create($this->supplierId, $transit, [$this->lineId($a, '261'), $this->lineId($b, '261')], null, $this->meta);

        $lineNo = (int) $this->db->pdo()->query('SELECT line_no FROM journal_entry_lines WHERE id = ' . $this->lineId($b, '261'))->fetchColumn();
        self::assertNull($this->openItems->removeLine($this->supplierId, $pairing['id'], $b, $lineNo, $this->meta));
        $this->expectRejection('not_found', fn () => $this->openItems->pairing($this->supplierId, $pairing['id']));
        self::assertCount(1, $this->openItems->suggestions($this->supplierId, $transit, self::YEAR . '-12-31', 7));
    }

    public function testDeletedEntryLeavesPartnerFreeForSuggestionsAndNewPairing(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual([self::l('261', 'debit', 900.00), self::l('221', 'credit', 900.00)], '-03-01');
        $b = $this->manual([self::l('221', 'debit', 900.00), self::l('261', 'credit', 900.00)], '-03-02');
        $pairing = $this->openItems->create($this->supplierId, $transit, [$this->lineId($a, '261'), $this->lineId($b, '261')], null, $this->meta);

        $this->db->pdo()->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?')->execute([$a, $this->supplierId]);

        $open = $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', true, 1, 100);
        self::assertSame(1, $open['open_count']);
        self::assertNull($open['items'][0]['pairing_id'], 'Zbytek okruhu po smazání zápisu se čte jako nespárovaný.');

        $c = $this->manual([self::l('261', 'debit', 900.00), self::l('221', 'credit', 900.00)], '-03-03');
        $suggestions = $this->openItems->suggestions($this->supplierId, $transit, self::YEAR . '-12-31', 7);
        self::assertCount(1, $suggestions);
        self::assertEqualsCanonicalizing([$b, $c], array_column($suggestions[0]['lines'], 'entry_id'));

        self::assertSame(1, $this->openItems->applySuggestions($this->supplierId, $transit, self::YEAR . '-12-31', 7, null, $this->meta));
        $this->expectRejection('not_found', fn () => $this->openItems->pairing($this->supplierId, $pairing['id']));
        self::assertSame(0, $this->openItems->build($this->supplierId, $transit, self::YEAR . '-12-31', true, 1, 100)['open_count']);
    }

    public function testStatementCarriesCounterAccountAndPairing(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual([self::l('261', 'debit', 100.00), self::l('221', 'credit', 100.00)], '-02-01');
        $b = $this->manual([self::l('221', 'debit', 100.00), self::l('261', 'credit', 100.00)], '-02-02');
        $pairing = $this->openItems->create($this->supplierId, $transit, [$this->lineId($a, '261'), $this->lineId($b, '261')], null, $this->meta);

        $data = $this->statement->build($this->supplierId, $transit, self::YEAR . '-01-01', self::YEAR . '-12-31', 1, 50);
        self::assertCount(2, $data['items']);
        self::assertSame('221', $data['items'][0]['counter_accounts']);
        self::assertSame($pairing['id'], $data['items'][0]['pairing_id']);
        self::assertSame('CZK', $data['items'][0]['currency']);
        self::assertSame(self::cents(100.00), self::cents($data['items'][0]['balance']));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param callable():mixed $fn */
    private function expectRejection(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail('Očekávána ReportException ' . $code . '.');
        } catch (ReportException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    private function isolatedSupplier(int $baseSupplier, string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, "izolace@example.com", default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        )->execute([$name, $baseSupplier]);
        $id = (int) $pdo->lastInsertId();
        $this->seeder->seedForSupplier($id);
        $this->periods->create($id, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        return $id;
    }

    private function accountId(string $code, ?int $supplierId = null): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$supplierId ?? $this->supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    private function analytic(string $parentCode, string $code): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             SELECT supplier_id, ?, ?, account_type, normal_side, 0, id, 1 FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?'
        )->execute([$code, 'Analytika ' . $code, $this->supplierId, $parentCode]);
    }

    private function lineId(int $entryId, string $accountCode): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.entry_id = ? AND ca.account_code = ? ORDER BY l.line_no LIMIT 1'
        );
        $stmt->execute([$entryId, $accountCode]);
        return (int) $stmt->fetchColumn();
    }

    private function auditCount(string $action, int $entryId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE action = ? AND entity_type = 'journal_entry' AND entity_id = ? AND supplier_id = ?"
        );
        $stmt->execute([$action, $entryId, $this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Přepis zápisu na místě — stejná cesta, jakou jde přeúčtování dokladu.
     *
     * @param list<array{account_id:int, side:string, amount:float, line_no:int}> $lines
     */
    private function rewrite(int $entryId, array $lines): void
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertNotNull($entry);
        $this->journal->replace($entryId, [
            'supplier_id' => $this->supplierId,
            'period_id'   => (int) $entry['period_id'],
            'entry_date'  => (string) $entry['entry_date'],
            'document_no' => $entry['document_no'],
            'description' => $entry['description'],
            'source_type' => (string) $entry['source_type'],
            'source_id'   => $entry['source_id'],
            'posted_at'   => $entry['posted_at'],
            'posted_by'   => $entry['posted_by'],
        ], $lines);
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function manual(array $lines, string $monthDay): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, $lines, [
            'entry_date' => self::YEAR . $monthDay,
            'posted_by'  => $this->userId,
            'user_id'    => $this->userId,
        ]);
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function technical(array $lines, string $monthDay, string $sourceType): int
    {
        return $this->posting->postDocument($this->supplierId, $sourceType, null, $lines, [
            'entry_date'  => self::YEAR . $monthDay,
            'document_no' => 'OT-' . self::YEAR . '-0001',
            'posted_by'   => $this->userId,
            'user_id'     => $this->userId,
        ]);
    }

    /** @return array{account_code:string, side:string, amount:float} */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
