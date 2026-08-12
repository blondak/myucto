<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoiceSeriesCompletenessService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FR3 (vendor audit 2026-08) — report úplnosti číselné řady vydaných dokladů.
 *
 * Klíčový nález z bugreportu, který tenhle test musí ověřit: faktury a dobropisy mohou
 * sdílet jednu řadu (stejná šablona), a kontrola úplnosti to MUSÍ brát dohromady — jinak
 * hlásí falešné mezery přesně tam, kde číslo ve skutečnosti použil ten druhý typ dokladu.
 *
 * Izolováno pod existujícím supplierem (číslovací šablony dočasně přepsané a v tearDown
 * vrácené), doklady rok 2098 uklizené v tearDown. Soft-skip pokud chybí cfg.php.
 */
#[Group('integration')]
final class InvoiceSeriesCompletenessTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private InvoiceSeriesCompletenessService $service;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $clientId = 0;
    private int $clientWithOwnSeriesId = 0;

    /** @var list<int> */
    private array $categoryIds = [];

    /** @var array<string,mixed> */
    private array $originalSupplierRow = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container     = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->service = $container->get(InvoiceSeriesCompletenessService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $stmt = $pdo->prepare(
            'SELECT invoice_number_format, credit_note_number_format, invoice_number_period FROM supplier WHERE id = ?'
        );
        $stmt->execute([$this->supplierId]);
        $this->originalSupplierRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "FR3 Test Client", "Test 1", "Praha", "11000", ?, "10000004",
                     "fr3@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();

        $pdo->prepare(
            'UPDATE supplier SET invoice_number_format = ?, credit_note_number_format = ?, invoice_number_period = ?
              WHERE id = ?'
        )->execute([
            $this->originalSupplierRow['invoice_number_format'] ?? null,
            $this->originalSupplierRow['credit_note_number_format'] ?? null,
            $this->originalSupplierRow['invoice_number_period'] ?? null,
            $this->supplierId,
        ]);

        foreach ([$this->clientId, $this->clientWithOwnSeriesId] as $clientId) {
            if ($clientId === 0) {
                continue;
            }
            $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? AND client_id = ?')
                ->execute([$this->supplierId, $clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$clientId]);
        }
        foreach ($this->categoryIds as $categoryId) {
            $pdo->prepare('DELETE FROM revenue_categories WHERE id = ?')->execute([$categoryId]);
        }
        $this->db->close();
    }

    /** Kategorie tržby s VLASTNÍ číselnou řadou (migrace 1333). */
    private function createCategory(string $code, string $invoiceTpl, string $period = 'year'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO revenue_categories (supplier_id, code, label, invoice_number_format, invoice_number_period)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $code, "FR3 {$code}", $invoiceTpl, $period]);
        $id = (int) $pdo->lastInsertId();
        $this->categoryIds[] = $id;
        return $id;
    }

    /** Klient s VLASTNÍ číselnou řadou — přebíjí řadu kategorie i dodavatele. */
    private function createClientWithOwnSeries(string $invoiceTpl, string $period = 'year'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic,
                                  main_email, language, currency_default_id, is_customer, is_vendor,
                                  invoice_number_format, invoice_number_period)
             VALUES (?, "FR3 Own Series Client", "Test 2", "Praha", "11000", ?, "10000004",
                     "fr3own@example.com", "cs", ?, 1, 0, ?, ?)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId, $invoiceTpl, $period]);
        $this->clientWithOwnSeriesId = (int) $pdo->lastInsertId();
        return $this->clientWithOwnSeriesId;
    }

    private function setTemplates(string $invoiceTpl, string $creditNoteTpl, string $period = 'year'): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET invoice_number_format = ?, credit_note_number_format = ?, invoice_number_period = ?
              WHERE id = ?'
        )->execute([$invoiceTpl, $creditNoteTpl, $period, $this->supplierId]);
    }

    private function insertInvoice(
        string $varsymbol,
        string $type = 'invoice',
        ?int $revenueCategoryId = null,
        ?int $clientId = null,
    ): void {
        $issue = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, revenue_category_id,
                 issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1000.00, 210.00, 1210.00, "issued", "1", ?)'
        )->execute([
            $this->supplierId, $varsymbol, $type, $clientId ?? $this->clientId, $revenueCategoryId,
            $issue, $issue, $issue,
            $this->currencyId, $this->userId,
        ]);
    }

    /** @param list<array<string,mixed>> $series */
    private static function groupFor(array $series, int $clientId, int $categoryId): array
    {
        foreach ($series as $group) {
            if ($group['client_id'] === $clientId && $group['revenue_category_id'] === $categoryId) {
                return $group;
            }
        }
        self::fail("Řada pro client_id={$clientId}, revenue_category_id={$categoryId} v reportu chybí.");
    }

    public function testSharedSeriesCombinesInvoiceAndCreditNoteNumbers(): void
    {
        // Sdílená šablona (stejný digit skeleton) pro invoice i credit_note.
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'invoice');
        // 000003 chybí u FAKTUR, ale číslo použil DOBROPIS — nesmí to být hlášeno jako mezera.
        $this->insertInvoice("{$y}000003", 'credit_note');
        $this->insertInvoice("{$y}000004", 'invoice');
        // 000005 chybí OPRAVDU — ani faktura, ani dobropis ho nepoužily.
        $this->insertInvoice("{$y}000006", 'invoice');

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(1, $series, 'Sdílený skeleton musí sloučit invoice+credit_note do JEDNÉ řady.');
        $group = $series[0];
        self::assertSame(['invoice', 'credit_note'], $group['types']);
        self::assertCount(1, $group['buckets']);
        $bucket = $group['buckets'][0];
        self::assertSame([5], $bucket['missing'], 'Jen 000005 je skutečná mezera — 000003 pokryl dobropis.');
        self::assertSame(6, $bucket['range_to']);
        self::assertSame(5, $bucket['used_count']);
    }

    public function testDistinctSeriesAreReportedIndependently(): void
    {
        // Odlišné šablony (jiný skeleton) → faktury a dobropisy NESMÍ se míchat.
        $this->setTemplates('F{YYYY}{CCC}', 'D{YYYY}{CCCC}');

        $y = self::YEAR;
        $this->insertInvoice("F{$y}001", 'invoice');
        $this->insertInvoice("F{$y}003", 'invoice'); // 002 chybí

        $this->insertInvoice("D{$y}0001", 'credit_note');
        $this->insertInvoice("D{$y}0002", 'credit_note'); // bez mezery

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(2, $series, 'Odlišné šablony zůstávají DVĚ samostatné řady.');
        $byType = [];
        foreach ($series as $s) {
            $byType[$s['types'][0]] = $s;
        }
        self::assertSame([2], $byType['invoice']['buckets'][0]['missing']);
        self::assertSame([], $byType['credit_note']['buckets'][0]['missing']);
    }

    public function testNoGapsReportsEmptyMissingList(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'credit_note');
        $this->insertInvoice("{$y}000003", 'invoice');

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(1, $series);
        self::assertSame([], $series[0]['buckets'][0]['missing']);
    }

    /**
     * Řada kategorie tržby (migrace 1333) je vlastní scope. Šablona je schválně zvolená
     * tak, aby se vyrenderovaná čísla CHYTLA i do regexu supplier-wide řady ("2098" + 9 +
     * counter) — reálný vzor „kategorii vyhradím číselné pásmo 9xx". Bez vyloučení
     * kategorie ze supplier-wide skenu by tenhle sken viděl counter 903 jako svoje
     * nejvyšší číslo a nahlásil stovky neexistujících mezer.
     */
    public function testRevenueCategorySeriesIsOwnScopeAndLeavesSupplierWideIntact(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $categoryId = $this->createCategory('FR3PREDPL', '{YYYY}9{CC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'invoice');
        // Řada kategorie: counter 1 a 3, číslo 2 v ní opravdu chybí.
        $this->insertInvoice("{$y}901", 'invoice', $categoryId);
        $this->insertInvoice("{$y}903", 'invoice', $categoryId);

        $series = $this->service->build($this->supplierId, self::YEAR);

        $supplierGroup = self::groupFor($series, 0, 0);
        self::assertSame([], $supplierGroup['buckets'][0]['missing'], 'Doklady kategorie nesmí do supplier-wide řady vůbec vstoupit.');
        self::assertSame(2, $supplierGroup['buckets'][0]['range_to']);
        self::assertSame(2, $supplierGroup['buckets'][0]['used_count']);

        $categoryGroup = self::groupFor($series, 0, $categoryId);
        self::assertSame('FR3 FR3PREDPL', $categoryGroup['revenue_category_name']);
        self::assertSame(['invoice'], $categoryGroup['types']);
        self::assertSame([2], $categoryGroup['buckets'][0]['missing'], 'Mezera uvnitř řady kategorie se hlásit MUSÍ.');
        self::assertSame(3, $categoryGroup['buckets'][0]['range_to']);
    }

    /**
     * Priorita resolveru je klient > kategorie > dodavatel, takže doklad klienta s vlastní
     * řadou nepatří do skenu kategorie, i když kategorii nese. Obě šablony jsou schválně
     * identické — bez vyloučení klienta by sken kategorie spolkl jeho číslo 900007 a
     * vyrobil mezery 2..6 v řadě, kde žádné nejsou.
     */
    public function testClientOwnSeriesBeatsRevenueCategoryScope(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $categoryId = $this->createCategory('FR3SDILENA', '{YYYY}9{CCCCC}');
        $ownClientId = $this->createClientWithOwnSeries('{YYYY}9{CCCCC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}900001", 'invoice', $categoryId);
        // Klient s vlastní řadou, doklad NESE tutéž kategorii — vyhrává klient.
        $this->insertInvoice("{$y}900007", 'invoice', $categoryId, $ownClientId);

        $series = $this->service->build($this->supplierId, self::YEAR);

        $categoryGroup = self::groupFor($series, 0, $categoryId);
        self::assertSame([], $categoryGroup['buckets'][0]['missing'], 'Doklad klienta s vlastní řadou nesmí do řady kategorie.');
        self::assertSame(1, $categoryGroup['buckets'][0]['range_to']);

        $clientGroup = self::groupFor($series, $ownClientId, 0);
        self::assertSame(7, $clientGroup['buckets'][0]['range_to']);
        self::assertSame([1, 2, 3, 4, 5, 6], $clientGroup['buckets'][0]['missing']);
    }

    /**
     * Konfigurace číslování se mění v čase: kategorii tržby přibude vlastní řada až
     * uprostřed období, doklady vystavené předtím ale nesou čísla ze staré (dodavatelské)
     * řady. Kdyby se příslušnost k řadě určovala podle DNEŠNÍHO `revenue_category_id`,
     * tyhle starší doklady by ze supplier-wide skenu vypadly a zůstaly by po nich falešné
     * mezery — a v nové řadě by se nezapočítaly, protože jejímu vzoru neodpovídají.
     * Rozhoduje proto tvar VS, ne aktuální nastavení.
     */
    public function testDocumentsKeepOldSeriesAfterCategoryGetsItsOwn(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $categoryId = $this->createCategory('FR3POZDE', '5{YYYY}{CCC}');

        $y = self::YEAR;
        // Vystaveno JEŠTĚ ve staré řadě dodavatele, kategorie tehdy vlastní řadu neměla.
        $this->insertInvoice("{$y}000001", 'invoice', $categoryId);
        $this->insertInvoice("{$y}000002", 'invoice', $categoryId);
        $this->insertInvoice("{$y}000003", 'invoice');
        // Až tenhle doklad vznikl po zavedení vlastní řady kategorie.
        $this->insertInvoice("5{$y}001", 'invoice', $categoryId);

        $series = $this->service->build($this->supplierId, self::YEAR);

        $supplierGroup = self::groupFor($series, 0, 0);
        self::assertSame([], $supplierGroup['buckets'][0]['missing'], 'Starší doklady kategorie zůstávají v dodavatelské řadě.');
        self::assertSame(3, $supplierGroup['buckets'][0]['range_to']);
        self::assertSame(3, $supplierGroup['buckets'][0]['used_count']);

        $categoryGroup = self::groupFor($series, 0, $categoryId);
        self::assertSame([], $categoryGroup['buckets'][0]['missing'], 'Nová řada kategorie začíná od 1 a je úplná.');
        self::assertSame(1, $categoryGroup['buckets'][0]['range_to']);
        self::assertSame(1, $categoryGroup['buckets'][0]['used_count']);
    }

    /**
     * Jediné ručně zadané (nebo importem rozbité) číslo posune horní hranici řady o
     * několik řádů. Výčet mezer se proto usekne, ale `missing_total` musí zůstat
     * přesný — jinak by sestava tvrdila, že mezer je 500, a účetní by podle toho
     * hledal 500 dokladů místo jednoho špatného čísla.
     */
    public function testHugeGapIsTruncatedButCountedExactly(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'invoice');
        $this->insertInvoice("{$y}090000", 'invoice'); // překlep — řada vystřelí na 90 000

        $series = $this->service->build($this->supplierId, self::YEAR);
        $bucket = self::groupFor($series, 0, 0)['buckets'][0];

        self::assertSame(90000, $bucket['range_to']);
        self::assertSame(89997, $bucket['missing_total'], 'Počet mezer je 90000 - 3 obsazená čísla.');
        self::assertTrue($bucket['missing_truncated']);
        self::assertCount(500, $bucket['missing'], 'Výčet se musí useknout na strop.');
        self::assertCount(500, $bucket['missing_preview']);
        self::assertSame(3, $bucket['missing'][0], 'Useknutý výčet začíná od první skutečné mezery.');
    }

    public function testSmallGapIsNotMarkedTruncated(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000003", 'invoice');

        $bucket = self::groupFor($this->service->build($this->supplierId, self::YEAR), 0, 0)['buckets'][0];

        self::assertSame([2], $bucket['missing']);
        self::assertSame(1, $bucket['missing_total']);
        self::assertFalse($bucket['missing_truncated']);
    }

    public function testDifferentYearIsNotPolluted(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $this->insertInvoice(self::YEAR . '000001', 'invoice');

        // Report za JINÝ rok nesmí najít doklady z self::YEAR (roční period bucketing).
        $series = $this->service->build($this->supplierId, self::YEAR + 1);

        self::assertSame([], $series, 'Rok bez dokladů nesmí vyrobit falešnou zprávu o mezerách.');
    }
}
