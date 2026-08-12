<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vlastní číselná řada na kategorii tržeb (migrace 1333).
 *
 * Ověřuje rozhodovací pořadí resolveru — klient → kategorie tržby → dodavatel → cfg —
 * a že counter kategorie běží ve VLASTNÍ scope (invoice_counters.revenue_category_id),
 * takže se dvě kategorie navzájem nepřečíslovávají.
 *
 * Izolace: rok 2097 (mimo fixture roky ostatních testů: 2095 seed, 2098/2099 counter
 * testy), vlastní klient i vlastní kategorie, obojí se v tearDown uklidí. Soft-skip
 * bez cfg.php / DB.
 */
#[Group('integration')]
final class VarsymbolRevenueCategorySeriesTest extends TestCase
{
    private const CAT_A_TEMPLATE = 'RCA{YYYY}{CCCC}';
    private const CAT_B_TEMPLATE = 'RCB{YYYY}{CCCC}';
    private const CLIENT_TEMPLATE = 'RCC{YYYY}{CCCC}';

    private Connection $db;
    private VarsymbolGenerator $gen;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $catA = 0;
    private int $catB = 0;
    private int $catPlain = 0;
    private string $supplierTemplate = '';
    private \DateTimeImmutable $date;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->gen = $c->get(VarsymbolGenerator::class);
            $config = $c->get(Config::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }

        // Efektivní supplier-wide template (stejná priorita jako v generátoru) — proti
        // němu se ověřuje fallback kategorie bez vlastní řady.
        $this->supplierTemplate = trim((string) ($pdo->query(
            "SELECT invoice_number_format FROM supplier WHERE id = {$this->supplierId}"
        )->fetchColumn() ?: ''));
        if ($this->supplierTemplate === '') {
            $this->supplierTemplate = trim((string) $config->get('varsymbol.templates.invoice', ''));
        }
        if ($this->supplierTemplate === '' || !str_contains($this->supplierTemplate, '{C')) {
            $this->markTestSkipped('Není template s counterem ({C+}) pro vydanou fakturu.');
        }

        $this->date = new \DateTimeImmutable('2097-06-15');
        $this->cleanup();

        $countryId  = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($countryId === 0 || $currencyId === 0) {
            $this->markTestSkipped('Chybí CZ země / CZK měna.');
        }

        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  currency_default_id, main_email, is_customer, is_vendor)
             VALUES (?, 'Kategorie řad test s.r.o.', 'Testovací 1', 'Praha', '11000', ?, ?,
                     'rc-series@example.test', 1, 0)"
        )->execute([$this->supplierId, $countryId, $currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $this->catA     = $this->createCategory('rc1333a', self::CAT_A_TEMPLATE);
        $this->catB     = $this->createCategory('rc1333b', self::CAT_B_TEMPLATE);
        $this->catPlain = $this->createCategory('rc1333c', null);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function createCategory(string $code, ?string $template): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO revenue_categories (supplier_id, code, label, invoice_number_format)
             VALUES (?, ?, ?, ?)'
        )->execute([$this->supplierId, $code, 'Test ' . $code, $template]);
        return (int) $pdo->lastInsertId();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        // Counter scope roku 2097 — nezávisle na period (year "2097" / month "209706").
        $pdo->prepare("DELETE FROM invoice_counters WHERE supplier_id = ? AND period LIKE '2097%'")
            ->execute([$this->supplierId]);
        $pdo->prepare("DELETE FROM revenue_categories WHERE supplier_id = ? AND code LIKE 'rc1333%'")
            ->execute([$this->supplierId]);
        $pdo->prepare("DELETE FROM clients WHERE supplier_id = ? AND main_email = 'rc-series@example.test'")
            ->execute([$this->supplierId]);
        $this->clientId = 0;
    }

    private function counterRows(): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT client_id, revenue_category_id, last_number
               FROM invoice_counters
              WHERE supplier_id = ? AND period LIKE '2097%'
              ORDER BY client_id, revenue_category_id"
        );
        $stmt->execute([$this->supplierId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testCategoryTemplateBeatsSupplierWideSeries(): void
    {
        $vs = $this->gen->next($this->supplierId, 'invoice', $this->date, $this->clientId, $this->catA);

        self::assertSame('RCA20970001', $vs, 'Faktura s kategorií mající vlastní řadu musí dostat číslo z ní.');
    }

    public function testClientSeriesBeatsCategorySeries(): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET invoice_number_format = ? WHERE id = ?')
            ->execute([self::CLIENT_TEMPLATE, $this->clientId]);

        $vs = $this->gen->next($this->supplierId, 'invoice', $this->date, $this->clientId, $this->catA);

        self::assertSame('RCC20970001', $vs, 'Vlastní řada klienta je specifičtější než řada kategorie.');

        $rows = $this->counterRows();
        self::assertCount(1, $rows);
        self::assertSame($this->clientId, (int) $rows[0]['client_id']);
        self::assertSame(0, (int) $rows[0]['revenue_category_id'], 'Vyhraje-li klient, counter kategorie se nesmí použít.');
    }

    public function testCategoryWithoutTemplateFallsBackToSupplierSeries(): void
    {
        $vs = $this->gen->next($this->supplierId, 'invoice', $this->date, $this->clientId, $this->catPlain);

        self::assertSame(
            $this->gen->render($this->supplierTemplate, $this->date, 1),
            $vs,
            'Kategorie bez vlastní šablony musí spadnout na supplier-wide řadu.',
        );

        $rows = $this->counterRows();
        self::assertCount(1, $rows);
        self::assertSame(0, (int) $rows[0]['client_id']);
        self::assertSame(0, (int) $rows[0]['revenue_category_id'], 'Fallback musí zůstat v supplier-wide scope.');
    }

    public function testTwoCategoriesKeepIndependentCounters(): void
    {
        self::assertSame('RCA20970001', $this->gen->next($this->supplierId, 'invoice', $this->date, $this->clientId, $this->catA));
        self::assertSame('RCA20970002', $this->gen->next($this->supplierId, 'invoice', $this->date, $this->clientId, $this->catA));

        // Druhá kategorie startuje od jedničky — jinak by řady sdílely jeden counter.
        self::assertSame('RCB20970001', $this->gen->next($this->supplierId, 'invoice', $this->date, $this->clientId, $this->catB));
        self::assertSame('RCA20970003', $this->gen->next($this->supplierId, 'invoice', $this->date, $this->clientId, $this->catA));

        $rows = $this->counterRows();
        $byCategory = [];
        foreach ($rows as $r) {
            $byCategory[(int) $r['revenue_category_id']] = (int) $r['last_number'];
        }
        self::assertSame(3, $byCategory[$this->catA] ?? null);
        self::assertSame(1, $byCategory[$this->catB] ?? null);
    }

    public function testForeignCategoryCannotDriveSeries(): void
    {
        // Tenant izolace: kategorie jiného dodavatele nesmí ovlivnit číslování ani
        // otevřít vlastní counter — resolver ji čte výhradně přes supplier_id.
        $pdo = $this->db->pdo();
        $sel = $pdo->prepare('SELECT id FROM supplier WHERE id <> ? ORDER BY id LIMIT 1');
        $sel->execute([$this->supplierId]);
        $otherSupplier = (int) ($sel->fetchColumn() ?: 0);
        if ($otherSupplier === 0) {
            self::markTestSkipped('V testovací DB je jen jeden dodavatel.');
        }

        $pdo->prepare(
            'INSERT INTO revenue_categories (supplier_id, code, label, invoice_number_format)
             VALUES (?, ?, ?, ?)'
        )->execute([$otherSupplier, 'rc1333x', 'Cizí kategorie', 'RCX{YYYY}{CCCC}']);
        $foreignId = (int) $pdo->lastInsertId();

        try {
            $vs = $this->gen->next($this->supplierId, 'invoice', $this->date, $this->clientId, $foreignId);
            self::assertSame(
                $this->gen->render($this->supplierTemplate, $this->date, 1),
                $vs,
                'Cizí kategorie nesmí dodat šablonu — číslo pochází ze supplier-wide řady.',
            );
        } finally {
            $pdo->prepare('DELETE FROM revenue_categories WHERE id = ?')->execute([$foreignId]);
        }
    }
}
