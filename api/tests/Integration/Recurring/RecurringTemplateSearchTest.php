<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Recurring;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RecurringTemplateRepository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hledání v seznamu pravidelných faktur (issue #114): název šablony, klient (název,
 * e-mail), text položek a pevný VS. Šablona s více shodnými položkami se nesmí
 * v seznamu ani v počtu zdvojit. Jen syntetická data, úklid v tearDown.
 */
#[Group('integration')]
final class RecurringTemplateSearchTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private RecurringTemplateRepository $repo;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    /** @var list<int> */
    private array $clientIds = [];
    /** @var list<int> */
    private array $templateIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->pdo = $this->db->pdo();
            $this->repo = $c->get(RecurringTemplateRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($this->pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1")->fetchColumn() ?: 0);
        $stmt = $this->pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1");
        $stmt->execute([$this->supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        $czId = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (!$this->supplierId || !$this->userId || !$this->vatRateId || !$this->currencyId || !$czId) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $alfa = $this->client('TEST Qzx Alfa s.r.o. (PHPUnit)', 'qzx-fakturace@example.test', $czId);
        $beta = $this->client('TEST Qzx Beta s.r.o. (PHPUnit)', 'beta@example.test', $czId);
        $this->template($alfa, 'Qzx hosting', null, ['Správa serveru Qzxkappa', 'Zálohování Qzxkappa']);
        $this->template($beta, 'Qzx podpora', '7319001', ['Paušál podpory']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach ($this->templateIds as $id) {
            $this->pdo->prepare('DELETE FROM recurring_invoice_template_items WHERE template_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function queries(): iterable
    {
        yield 'název šablony' => ['Qzx hosting', ['Qzx hosting']];
        yield 'název klienta' => ['Qzx Beta', ['Qzx podpora']];
        yield 'e-mail klienta' => ['qzx-fakturace@', ['Qzx hosting']];
        yield 'text položky' => ['Qzxkappa', ['Qzx hosting']];
        yield 'variabilní symbol' => ['7319001', ['Qzx podpora']];
        yield 'společný prefix' => ['Qzx', ['Qzx hosting', 'Qzx podpora']];
        yield 'wildcard není zástupný znak' => ['Qzx%Beta', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('queries')]
    public function testSearchFiltersTemplates(string $q, array $expected): void
    {
        $res = $this->repo->list(['supplier_id' => $this->supplierId, 'q' => $q], 1, 50, 'client');
        $names = array_column($res['data'], 'name');
        sort($names);

        self::assertSame($expected, $names);
        self::assertSame(count($expected), $res['meta']['total'], 'Shoda ve více položkách nesmí šablonu zdvojit.');
        self::assertSame(count($expected), $res['meta']['status_counts']['all']);
    }

    private function client(string $name, string $email, int $countryId): int
    {
        $this->pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $name, 'Ulice 1', 'Praha', '11000', $countryId, $email, $this->currencyId]);
        return $this->clientIds[] = (int) $this->pdo->lastInsertId();
    }

    /** @param list<string> $items */
    private function template(int $clientId, string $name, ?string $vs, array $items): void
    {
        $this->pdo->prepare(
            "INSERT INTO recurring_invoice_templates
                (supplier_id, client_id, name, frequency, day_of_month, anchor_date, next_run_date,
                 currency_id, payment_variable_symbol, created_by)
             VALUES (?, ?, ?, 'monthly', 1, '2026-01-01', '2026-11-01', ?, ?, ?)"
        )->execute([$this->supplierId, $clientId, $name, $this->currencyId, $vs, $this->userId]);
        $id = $this->templateIds[] = (int) $this->pdo->lastInsertId();
        foreach ($items as $i => $description) {
            $this->pdo->prepare(
                'INSERT INTO recurring_invoice_template_items
                    (template_id, description, quantity, unit_price_without_vat, vat_rate_id, order_index)
                 VALUES (?, ?, 1, 1000, ?, ?)'
            )->execute([$id, $description, $this->vatRateId, $i]);
        }
    }
}
