<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Uzávěrková kontrola `entries_without_description` — § 11 odst. 1 písm. b) ZoÚ.
 *
 * Účetní doklad musí obsahovat OBSAH účetního případu. U zápisu bez popisu sedí částky
 * i účty, ale z deníku nejde poznat, čeho se případ týkal — auditní stopa (§ 33a) pak
 * doloží jen kdy a kolik, ne co.
 *
 * Proč kontrola, a ne blokace při zaúčtování: {@see PostingService} si popis od commitu
 * 2359ae10 dopočítá ze zdrojového dokladu, takže novým zápisem chybět nemůže — měřeno
 * na ostrých datech: 5 zápisů z 1522 nemá popis a všech 5 vzniklo PŘED tím commitem.
 * Blokující kontrola by tedy nezachytila nic nového a jen by odmítala existující data
 * (zkoušeno: plošné vynucení popisu shodilo 221 testů, u ručních zápisů 95).
 *
 * Nález proto míří na ZÁPIS, ne na doklad — opravuje se popis v deníku.
 *
 * Izolovaný supplier v transakci s rollbackem (vzor ClosingCancelledWithEntryTest).
 */
#[Group('integration')]
final class ClosingEntryDescriptionTest extends TestCase
{
    private const YEAR = 2093;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private ClosingService $closing;
    private ClosingRepository $closingRepo;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db          = $container->get(Connection::class);
            $this->closing     = $container->get(ClosingService::class);
            $this->closingRepo = $container->get(ClosingRepository::class);
            $this->posting     = $container->get(PostingService::class);
            $this->periods     = $container->get(AccountingPeriodRepository::class);
            $seeder            = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId        = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['Popis test s.r.o.', $czId, 'popis-test@example.com', $this->currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, is_vendor)
             VALUES (?, ?, "Dodavatelska 1", "Praha", "11000", ?, ?, 1)'
        );
        $stmt->execute([$this->supplierId, 'Dodavatel popis s.r.o.', $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
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

    /**
     * Zápis bez obsahu účetního případu se nahlásí a odkazuje na SEBE, ne na doklad —
     * opravuje se popis zápisu v deníku.
     */
    public function testEntryWithoutDescriptionIsReported(): void
    {
        $entryId = $this->postedEntry();
        $this->stripDescription($entryId);

        $rows = $this->closingRepo->entriesWithoutDescription($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertCount(1, $rows, 'Zápis bez popisu musí být nahlášen.');
        self::assertSame($entryId, $rows[0]['entry_id']);
        self::assertSame('journal_entry', $rows[0]['doc_type'], 'Nález míří na zápis, ne na doklad.');
        self::assertSame($entryId, $rows[0]['doc_id']);
        self::assertSame('purchase_invoice', $rows[0]['note'], 'Původ zápisu zůstává v poznámce.');
        self::assertSame('Dodavatel popis s.r.o.', $rows[0]['partner_name']);
        self::assertEqualsWithDelta(1000.0, $rows[0]['booked'], 0.01);
        self::assertFalse($this->checkOk(), 'Uzávěrková kontrola musí být v chybovém stavu.');
    }

    /**
     * Zápis se řádným popisem kontrolu nespustí. Tohle je stav VŠECH nových zápisů —
     * PostingService popis dopočítá i tehdy, když ho volající nedodá.
     */
    public function testEntryWithDescriptionPasses(): void
    {
        $this->postedEntry();

        self::assertSame([], $this->closingRepo->entriesWithoutDescription(
            $this->supplierId,
            self::STARTS_ON,
            self::ENDS_ON,
        ));
        self::assertTrue($this->checkOk());
    }

    /**
     * Zaúčtování BEZ dodaného popisu projde — popis se dopočítá ze zdrojového dokladu.
     * Tím je doložené, proč je kontrola mířená na historii a ne na nové zápisy.
     */
    public function testPostingWithoutExplicitDescriptionStillHasContent(): void
    {
        $entryId = $this->postedEntry(description: null);

        $stmt = $this->db->pdo()->prepare('SELECT description FROM journal_entries WHERE id = ?');
        $stmt->execute([$entryId]);
        $description = (string) $stmt->fetchColumn();

        self::assertNotSame('', trim($description), 'Popis se musí dopočítat ze zdrojového dokladu.');
        self::assertStringContainsString('Dodavatel popis s.r.o.', $description);
        self::assertSame([], $this->closingRepo->entriesWithoutDescription(
            $this->supplierId,
            self::STARTS_ON,
            self::ENDS_ON,
        ));
    }

    /** Samotná mezera obsah účetního případu není. */
    public function testWhitespaceOnlyDescriptionCountsAsMissing(): void
    {
        $entryId = $this->postedEntry();
        $this->db->pdo()->prepare("UPDATE journal_entries SET description = '   ' WHERE id = ?")->execute([$entryId]);

        $rows = $this->closingRepo->entriesWithoutDescription($this->supplierId, self::STARTS_ON, self::ENDS_ON);
        self::assertCount(1, $rows);
        self::assertSame($entryId, $rows[0]['entry_id']);
    }

    /** Stornovaný zápis se nehlásí — protizápis ho vyřadil z účetnictví. */
    public function testReversedEntryIsIgnored(): void
    {
        $entryId = $this->postedEntry();
        $this->stripDescription($entryId);
        self::assertFalse($this->checkOk());

        $this->posting->reverse($this->supplierId, $entryId, ['user_id' => $this->userId]);

        $rows = $this->closingRepo->entriesWithoutDescription($this->supplierId, self::STARTS_ON, self::ENDS_ON);
        self::assertSame([], array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['entry_id'] === $entryId,
        )), 'Stornovaný zápis už nález tvořit nesmí.');
    }

    /** Zápis mimo uzavírané období se do kontroly nesmí připlést. */
    public function testEntryOutsideRangeIsIgnored(): void
    {
        $entryId = $this->postedEntry();
        $this->stripDescription($entryId);

        self::assertSame([], $this->closingRepo->entriesWithoutDescription(
            $this->supplierId,
            self::YEAR . '-01-01',
            self::YEAR . '-05-31',
        ));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Simuluje zápis vzniklý mimo aplikaci (import, přímý zásah do DB, historie před
     * commitem 2359ae10). Přes PostingService takový zápis založit NELZE — popis se
     * dopočítá — takže se popis odstraní až dodatečně.
     */
    private function stripDescription(int $entryId): void
    {
        $this->db->pdo()->prepare('UPDATE journal_entries SET description = NULL WHERE id = ?')->execute([$entryId]);
    }

    /** Přijatá faktura 1000 Kč zaúčtovaná na 518/321 v uzavíraném období. */
    private function postedEntry(?string $description = 'Přijatá faktura (test popisu)'): int
    {
        $pdo = $this->db->pdo();
        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 total_without_vat, total_vat, total_with_vat, tax_deductible, status, created_by)
             VALUES (?, ?, ?, "{}", "invoice", ?, ?, ?, ?, ?, 0, 1000.00, 0.00, 1000.00, 1, "booked", ?)'
        )->execute([
            $this->supplierId, $this->vendorId, 'PF-POPIS-' . uniqid(),
            $issue, $issue, $issue, $issue, $this->currencyId, $this->userId,
        ]);
        $pfId = (int) $pdo->lastInsertId();

        $meta = [
            'entry_date' => $issue,
            'posted'     => true,
            'user_id'    => $this->userId,
        ];
        if ($description !== null) {
            $meta['description'] = $description;
        }

        return $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pfId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.0],
        ], $meta);
    }

    private function checkOk(string $key = 'entries_without_description'): bool
    {
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, null, null);
        foreach ($result['checks'] as $c) {
            if ($c['key'] === $key) {
                return (bool) $c['ok'];
            }
        }
        self::fail('Kontrola ' . $key . ' chybí v seznamu kontrol.');
    }
}
