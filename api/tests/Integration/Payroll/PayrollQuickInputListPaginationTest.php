<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollQuickInputRepository;
use MyInvoice\Tests\Fixtures\Payroll\PayrollRunScaleFixture;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `GET /payroll/quick-inputs` čte jeden řádek na pracovní vztah.
 *
 * Seznam tedy roste s velikostí firmy a ke každému řádku se ještě dopočítávají
 * vstupy a opakující se složky. Strop stránky je proto tvrdý.
 *
 * Nejcitlivější místo úpravy je UKLÁDÁNÍ: dřív si ověřovalo příslušnost vztahu
 * proti celému měsíci. Kdyby si k tomu bralo stránku, uložení kohokoli za
 * koncem první stránky by skončilo hláškou „vztah nepatří této firmě".
 */
#[Group('integration')]
final class PayrollQuickInputListPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';

    private Connection $db;
    private PayrollQuickInputsAction $action;
    private PayrollQuickInputRepository $quickInputs;
    private int $supplierId;
    private int $userId;
    /** @var list<int> */
    private array $employmentIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollQuickInputsAction::class);
            $this->quickInputs = $container->get(PayrollQuickInputRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_employments', 'payroll_inputs'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /** Strop je tvrdý a `total` počítá všechny vztahy měsíce. */
    public function testCapCannotBeLiftedFromTheUrl(): void
    {
        $this->seedEmployments(6);

        $payload = $this->list(['limit' => '10000']);
        $month = (array) $payload['month'];

        self::assertLessThanOrEqual(
            PayrollQuickInputRepository::LIST_MAX_LIMIT,
            count((array) $month['items']),
        );
        self::assertSame(PayrollQuickInputRepository::LIST_MAX_LIMIT, $payload['limit']);
        self::assertSame(6, $month['total']);
        self::assertSame(6, $payload['total'], 'Total je i na vrchní úrovni, stejně jako u ostatních seznamů.');
    }

    /** Offset musí seznam skutečně posunout. */
    public function testOffsetShiftsThePage(): void
    {
        $this->seedEmployments(5);

        $first = (array) $this->list(['limit' => '2', 'offset' => '0'])['month'];
        $second = (array) $this->list(['limit' => '2', 'offset' => '2'])['month'];

        self::assertCount(2, (array) $first['items']);
        self::assertCount(2, (array) $second['items']);
        self::assertSame(5, $first['total']);
        self::assertSame(
            [],
            array_intersect($this->ids($first), $this->ids($second)),
        );
    }

    /** Klíč `month` i jeho `items` zůstávají, aby stávající volající nespadli. */
    public function testPayloadShapeIsPreserved(): void
    {
        $this->seedEmployments(1);

        $payload = $this->list([]);
        $month = (array) $payload['month'];

        self::assertArrayHasKey('month', $payload);
        self::assertArrayHasKey('items', $month);
        self::assertSame(self::PERIOD, $month['period']);
        self::assertSame(1, $month['total']);
        self::assertSame(PayrollQuickInputRepository::LIST_DEFAULT_LIMIT, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    /**
     * Uložení musí projít i vztahu, který je až za koncem první stránky.
     *
     * Tohle je celá pointa toho, proč si ukládání ověřuje PRÁVĚ ty vztahy,
     * které přišly v požadavku, a ne stránku měsíce.
     */
    public function testSaveWorksForAnEmploymentBeyondTheFirstPage(): void
    {
        $this->seedEmployments(5);
        $employmentId = $this->employmentIds[4];
        $rowVersion = $this->employmentRowVersion($employmentId);

        $saved = $this->quickInputs->save(
            $this->supplierId,
            self::PERIOD,
            [[
                'employment_id' => $employmentId,
                'employment_row_version' => $rowVersion,
                'base_amount_minor' => 3_500_000,
                'overtime_mode' => 'amount',
                'overtime_hours_milli' => null,
                'overtime_amount_minor' => null,
                'bonus_amount_minor' => 0,
                'overtime_average_snapshot_id' => null,
                'overtime_average_snapshot_version' => null,
                'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
            ]],
            $this->userId,
            2,
            0,
        );

        // Vrací se táž stránka, na které uživatel byl — ne natvrdo první.
        self::assertCount(2, $saved['items']);
        self::assertSame(5, $saved['total']);

        $stored = $this->quickInputs->month($this->supplierId, self::PERIOD, 5, 0);
        $row = null;
        foreach ($stored['items'] as $item) {
            if ((int) $item['employment_id'] === $employmentId) {
                $row = $item;
            }
        }
        self::assertIsArray($row, 'Uložený vztah musí ve výpisu měsíce být.');
        self::assertSame(3_500_000, $row['base_amount_minor']);
    }

    /**
     * Cena stránky se neřídí velikostí firmy.
     *
     * Doprovodné dotazy na vstupy a opakující se složky se dřív ptaly na CELÝ
     * měsíc; teď se ptají jen na řádky stránky. Rovnost počtu dotazů chytí
     * i to, kdyby se do seznamu vrátil dotaz na řádek.
     */
    public function testQueryCountDoesNotGrowWithHeadcount(): void
    {
        $pdo = $this->db->pdo();
        $this->seedEmployments(4);
        $before = PayrollRunScaleFixture::statementRoundTrips($pdo);
        $small = $this->quickInputs->month($this->supplierId, self::PERIOD, 4, 0);
        $smallCost = PayrollRunScaleFixture::statementRoundTrips($pdo) - $before;

        $this->seedEmployments(12);
        $before = PayrollRunScaleFixture::statementRoundTrips($pdo);
        $large = $this->quickInputs->month($this->supplierId, self::PERIOD, 4, 0);
        $largeCost = PayrollRunScaleFixture::statementRoundTrips($pdo) - $before;

        self::assertCount(4, $small['items']);
        self::assertCount(4, $large['items']);
        self::assertSame(4, $small['total']);
        self::assertSame(16, $large['total']);
        self::assertSame(
            $smallCost,
            $largeCost,
            'Nábor dalších lidí nesmí zdražit stránku o čtyřech řádcích.',
        );
    }

    /**
     * @param array<string,mixed> $month
     * @return list<int>
     */
    private function ids(array $month): array
    {
        $ids = [];
        foreach ((array) $month['items'] as $item) {
            self::assertIsArray($item);
            $ids[] = (int) $item['employment_id'];
        }

        return $ids;
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function list(array $query): array
    {
        $response = $this->action->list(
            $this->request()->withQueryParams(['period' => self::PERIOD, ...$query]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    private function seedEmployments(int $count): void
    {
        $pdo = $this->db->pdo();
        for ($i = 0; $i < $count; ++$i) {
            $ordinal = count($this->employmentIds) + 1;
            $pdo->prepare(
                'INSERT INTO payroll_employees
                    (supplier_id, full_name, taxpayer_type, is_active)
                 VALUES (?, ?, "employee", 1)'
            )->execute([
                $this->supplierId,
                sprintf('Syntetický rychlozadaný %03d', $ordinal),
            ]);
            $employeeId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO payroll_employments
                    (supplier_id, employee_id, code, relation_type, status,
                     start_date, monthly_gross_minor, is_legacy_projection)
                 VALUES (?, ?, ?, "employment", "active", "2026-01-01", 3000000, 0)'
            )->execute([$this->supplierId, $employeeId, 'SYN-QI-' . $ordinal]);
            $this->employmentIds[] = (int) $pdo->lastInsertId();
        }
    }

    private function employmentRowVersion(int $employmentId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $employmentId]);

        return (int) $statement->fetchColumn();
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/quick-inputs')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
