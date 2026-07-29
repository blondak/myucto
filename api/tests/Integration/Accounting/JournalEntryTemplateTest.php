<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryTemplateRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\TemplateCsvMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integrační testy šablon ručních zápisů (Fáze F, mzdový můstek, audit 2026-07
 * nález „Ruční zápis nemá šablony ani opakování").
 *
 * Ověřuje:
 *  - uložení šablony (řádky s volitelnou výchozí částkou),
 *  - lazy seed doporučené šablony „Mzdy" (idempotentní, nezdvojí se),
 *  - že vytvoření zápisu z řádků šablony jde přes běžný PostingService::postDocument
 *    (source_type='manual') beze změny jeho chování — manuální zápisy zůstávají
 *    NEidempotentní (bez source_id), takže dvojí uložení vytvoří dva zápisy.
 *
 * Vše v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class JournalEntryTemplateTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private JournalEntryTemplateRepository $templates;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

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
            $this->templates = $container->get(JournalEntryTemplateRepository::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $this->seeder     = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $baseSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($baseSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $iso = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $iso->execute(['Sablony test s.r.o.', 'sablony@example.com', $baseSupplier]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $this->seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testCreateTemplateStoresLinesWithOptionalAmounts(): void
    {
        $id = $this->templates->create($this->supplierId, 'Leasing', 'Měsíční splátka leasingu', $this->userId, [
            ['account_code' => '518', 'side' => 'debit',  'amount' => 5000.0, 'label' => 'Splátka leasingu', 'cost_center' => null],
            ['account_code' => '321', 'side' => 'credit', 'amount' => null,   'label' => 'Závazek dodavateli', 'cost_center' => null],
        ]);

        $tpl = $this->templates->find($this->supplierId, $id);
        self::assertNotNull($tpl);
        self::assertSame('Leasing', $tpl['name']);
        self::assertFalse($tpl['is_seeded']);
        self::assertCount(2, $tpl['lines']);
        self::assertSame(1, $tpl['lines'][0]['line_no']);
        self::assertSame('518', $tpl['lines'][0]['account_code']);
        self::assertSame(5000.0, $tpl['lines'][0]['default_amount']);
        self::assertSame('Splátka leasingu', $tpl['lines'][0]['label']);
        self::assertNull($tpl['lines'][1]['default_amount'], 'Prázdná částka zůstává NULL — doplní se při vložení.');

        $list = $this->templates->listForSupplier($this->supplierId);
        $names = array_column($list, 'name');
        self::assertContains('Leasing', $names);
    }

    public function testEnsurePayrollSeedIsIdempotentAndHasNullAmounts(): void
    {
        $this->templates->ensurePayrollSeed($this->supplierId);
        $this->templates->ensurePayrollSeed($this->supplierId); // druhé volání nesmí zdvojit

        $list = $this->templates->listForSupplier($this->supplierId);
        $seeded = array_values(array_filter($list, static fn (array $t) => $t['is_seeded']));
        self::assertCount(1, $seeded, 'Doporučená šablona „Mzdy" se seedne přesně jednou.');
        self::assertSame('Mzdy', $seeded[0]['name']);
        self::assertSame(5, $seeded[0]['line_count']);

        $tpl = $this->templates->find($this->supplierId, $seeded[0]['id']);
        self::assertNotNull($tpl);
        $codes = array_column($tpl['lines'], 'account_code');
        self::assertSame(['521', '524', '331', '336', '342'], $codes);
        foreach ($tpl['lines'] as $line) {
            self::assertNull($line['default_amount'], 'Mzdové částky se nedopočítávají — čekají na ruční doplnění.');
        }
    }

    public function testEnsureClosingTemplatesSeedIsIdempotentAndCoversAllRecipes(): void
    {
        $this->templates->ensureClosingTemplatesSeed($this->supplierId);
        $this->templates->ensureClosingTemplatesSeed($this->supplierId); // druhé volání nesmí zdvojit

        $list = $this->templates->listForSupplier($this->supplierId);
        $seeded = array_values(array_filter($list, static fn (array $t) => $t['is_seeded']));
        self::assertCount(15, $seeded, 'Task 34 — 15 obecných předuzávěrkových šablon se seedne přesně jednou.');

        $names = array_column($seeded, 'name');
        self::assertContains('Dohadná položka pasivní', $names);
        self::assertContains('Dohadná položka aktivní', $names);
        self::assertContains('Odpis pohledávky', $names);

        $liability = current(array_filter($list, static fn (array $t) => $t['name'] === 'Dohadná položka pasivní'));
        self::assertNotFalse($liability);
        $tpl = $this->templates->find($this->supplierId, $liability['id']);
        self::assertNotNull($tpl);
        self::assertSame(['518', '389'], array_column($tpl['lines'], 'account_code'));
        self::assertSame(['debit', 'credit'], array_column($tpl['lines'], 'side'));
        foreach ($tpl['lines'] as $line) {
            self::assertNull($line['default_amount'], 'Částka se nedopočítává — čeká na ruční doplnění.');
        }
    }

    public function testEntryFromTemplateLinesPostsViaPostingServiceAndStaysNonIdempotent(): void
    {
        $id = $this->templates->create($this->supplierId, 'Leasing', null, $this->userId, [
            ['account_code' => '518', 'side' => 'debit',  'amount' => 5000.0, 'label' => null, 'cost_center' => null],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 5000.0, 'label' => null, 'cost_center' => null],
        ]);
        $tpl = $this->templates->find($this->supplierId, $id);
        self::assertNotNull($tpl);

        // FE by tímhle předvyplnila ManualEntry — sestav ManualLinePayload-tvar
        // z řádků šablony a pošli přes PostingService stejně jako JournalAction::create.
        $lines = array_map(static fn (array $l) => [
            'account_code' => $l['account_code'],
            'side'          => $l['side'],
            'amount'        => (float) $l['default_amount'],
        ], $tpl['lines']);

        $meta = ['entry_date' => self::YEAR . '-05-15', 'posted_by' => $this->userId, 'user_id' => $this->userId];
        $entry1 = $this->posting->postDocument($this->supplierId, 'manual', null, $lines, $meta);
        $entry2 = $this->posting->postDocument($this->supplierId, 'manual', null, $lines, $meta);

        // Manuální zápisy bez source_id se NEidempotují (PostingService dokblok) —
        // šablona na tuhle sémantiku nesmí nic měnit: dvě uložení = dva zápisy.
        self::assertNotSame($entry1, $entry2, 'Šablona nemění idempotenci ručních zápisů — dvě uložení = dva zápisy.');

        $count = (int) $this->row(
            "SELECT COUNT(*) AS c FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual' AND id IN (?, ?)",
            [$this->supplierId, $entry1, $entry2],
        )['c'];
        self::assertSame(2, $count);

        foreach ([$entry1, $entry2] as $entryId) {
            $sums = $this->row(
                "SELECT SUM(CASE WHEN side='debit' THEN amount ELSE 0 END) AS md,
                        SUM(CASE WHEN side='credit' THEN amount ELSE 0 END) AS d
                   FROM journal_entry_lines WHERE entry_id = ?",
                [$entryId],
            );
            self::assertSame(500000, self::cents($sums['md']), 'Σ MD = 5000 z řádku šablony.');
            self::assertSame(self::cents($sums['md']), self::cents($sums['d']), 'Zápis ze šablony zůstává vyvážený.');
        }
    }

    public function testCsvMatchPrefillsTemplateAmountsWithoutWritingToDb(): void
    {
        $id = $this->templates->create($this->supplierId, 'Mzdy vlastní', null, $this->userId, [
            ['account_code' => '521', 'side' => 'debit',  'amount' => null, 'label' => 'Hrubé mzdy', 'cost_center' => null],
            ['account_code' => '331', 'side' => 'credit', 'amount' => null, 'label' => 'Závazek vůči zaměstnancům', 'cost_center' => null],
        ]);
        $tpl = $this->templates->find($this->supplierId, $id);
        self::assertNotNull($tpl);

        $matcher = new TemplateCsvMatcher();
        $result = $matcher->match($tpl['lines'], "521;62000\n331;62000\n");

        $byLine = [];
        foreach ($result['lines'] as $l) {
            $byLine[$l['line_no']] = $l['amount'];
        }
        self::assertSame(62000.0, $byLine[1]);
        self::assertSame(62000.0, $byLine[2]);

        // Náhled nic nezapisuje — šablona v DB má pořád NULL výchozí částky.
        $reloaded = $this->templates->find($this->supplierId, $id);
        self::assertNotNull($reloaded);
        foreach ($reloaded['lines'] as $line) {
            self::assertNull($line['default_amount']);
        }
    }

    public function testDeleteRemovesTemplateAndLines(): void
    {
        $id = $this->templates->create($this->supplierId, 'Ke smazání', null, $this->userId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 100.0, 'label' => null, 'cost_center' => null],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.0, 'label' => null, 'cost_center' => null],
        ]);

        self::assertTrue($this->templates->delete($this->supplierId, $id));
        self::assertNull($this->templates->find($this->supplierId, $id));

        $lineCount = (int) $this->row('SELECT COUNT(*) AS c FROM journal_entry_template_lines WHERE template_id = ?', [$id])['c'];
        self::assertSame(0, $lineCount, 'ON DELETE CASCADE smaže i řádky šablony.');

        self::assertFalse($this->templates->delete($this->supplierId, $id), 'Druhé smazání téhož ID už nic nenajde.');
    }

    public function testUpdateReplacesHeaderAndLinesWithinSupplier(): void
    {
        $id = $this->templates->create($this->supplierId, 'Původní', null, $this->userId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => null, 'label' => null, 'cost_center' => null],
        ]);

        self::assertTrue($this->templates->update($this->supplierId, $id, 'Leasing', 'Pravidelná splátka', [
            ['account_code' => '518', 'side' => 'debit',  'amount' => 2500.0, 'label' => 'Služba', 'cost_center' => 'PRAHA'],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 2500.0, 'label' => 'Závazek', 'cost_center' => null],
        ]));

        $tpl = $this->templates->find($this->supplierId, $id);
        self::assertNotNull($tpl);
        self::assertSame('Leasing', $tpl['name']);
        self::assertSame('Pravidelná splátka', $tpl['description']);
        self::assertCount(2, $tpl['lines']);
        self::assertSame('PRAHA', $tpl['lines'][0]['cost_center']);
        self::assertSame(2500.0, $tpl['lines'][1]['default_amount']);
        self::assertFalse($this->templates->update($this->supplierId, PHP_INT_MAX, 'Cizí', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => null, 'label' => null, 'cost_center' => null],
        ]));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? [] : $r;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
