<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDeadlineGroupsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Seskupený přehled termínů a hromadné odškrtnutí checklistu.
 *
 * Import docházky založil stovkám lidí nástupní checklist s termíny v minulosti.
 * Plochý přehled z toho udělal stovky skoro stejných dlaždic a odškrtnout je šlo
 * jen po jedné na kartě každého vztahu.
 */
#[Group('integration')]
final class PayrollDeadlineGroupsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const NOTE = 'Vyřízeno v předchozím zpracování mezd';

    private Connection $db;
    private PayrollDeadlineGroupsAction $action;
    private int $sourceSupplierId;
    private int $supplierId;
    private int $userId;
    private string $overdueOn;
    private string $soonOn;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollDeadlineGroupsAction::class);
        if (!$db instanceof Connection || !$action instanceof PayrollDeadlineGroupsAction) {
            throw new \RuntimeException('Služby přehledu termínů nejsou dostupné.');
        }
        $this->db = $db;
        $this->action = $action;
        foreach ([
            'payroll_employments',
            'payroll_employees',
            'payroll_employment_checklist_items',
            'payroll_employment_events',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->sourceSupplierId = $this->firstId('supplier');
        $this->userId = $this->firstId('users');
        if ($this->sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));
        $this->overdueOn = $today->sub(new \DateInterval('P104D'))->format('Y-m-d');
        $this->soonOn = $today->add(new \DateInterval('P3D'))->format('Y-m-d');

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->supplierId = $this->createPayrollSupplier();
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

    /**
     * 225 lidí se stejnou nástupní povinností = jeden řádek s počtem, ne 225
     * dlaždic. Skutečně nový nástup (termín za tři dny) se v tom neztratí.
     */
    public function testManyPeopleCollapseIntoOneGroupPerItemKey(): void
    {
        $this->seedPeople($this->supplierId, 225, ['employment_contract', 'tax_declaration'], $this->overdueOn);
        $fresh = $this->seedPeople($this->supplierId, 1, ['employment_contract'], $this->soonOn, 'NOVY');

        $started = microtime(true);
        $overview = $this->call('groups', 'GET', '/api/payroll/deadlines/groups');
        $elapsed = microtime(true) - $started;
        fwrite(STDERR, sprintf("\n[deadline groups] 451 položek → %.0f ms\n", $elapsed * 1000));

        self::assertSame(450, $overview['summary']['overdue']);
        self::assertSame(1, $overview['summary']['due_soon']);
        self::assertSame(451, $overview['summary']['total']);

        $groups = [];
        foreach ($overview['groups'] as $group) {
            $groups[$group['key']] = $group;
        }
        $contract = $groups['overdue:checklist:employment_contract'] ?? null;
        self::assertNotNull($contract);
        self::assertSame(225, $contract['count']);
        self::assertTrue($contract['per_person']);
        self::assertSame($this->overdueOn, $contract['oldest_due_on']);
        self::assertSame(-104, $contract['min_days_to_due']);
        // Seznam lidí se v odpovědi neposílá — dotahuje se stránkovaně.
        self::assertSame([], $contract['items']);
        self::assertSame(225, $groups['overdue:checklist:tax_declaration']['count'] ?? null);

        $soon = $groups['due_soon:checklist:employment_contract'] ?? null;
        self::assertNotNull($soon, 'Nový nástup se nesmí schovat ve skupině zmeškaných.');
        self::assertSame(1, $soon['count']);
        self::assertCount(1, $soon['items']);
        self::assertSame($fresh[0]['employee_id'], $soon['items'][0]['employee_id']);

        self::assertSame('overdue', $overview['groups'][0]['phase']);
        self::assertLessThan(5.0, $elapsed, 'Seskupený přehled pro stovky lidí musí zůstat rychlý.');
    }

    public function testGroupItemsArePagedAndSearchable(): void
    {
        $people = $this->seedPeople($this->supplierId, 225, ['employment_contract'], $this->overdueOn);
        $params = ['phase' => 'overdue', 'source' => 'checklist', 'title' => 'employment_contract'];

        $last = $this->call('items', 'GET', '/api/payroll/deadlines/items', $params + ['offset' => '200', 'limit' => '50']);
        self::assertSame(225, $last['total']);
        self::assertCount(25, $last['items']);
        self::assertArrayHasKey('item_id', $last['items'][0]);
        self::assertNotNull($last['items'][0]['personal_number']);

        $clamped = $this->call('items', 'GET', '/api/payroll/deadlines/items', $params + ['limit' => '5000']);
        self::assertCount(200, $clamped['items'], 'Stránka má strop, i když si klient řekne o víc.');

        $byCode = $this->call('items', 'GET', '/api/payroll/deadlines/items', $params + ['q' => strtolower($people[6]['code'])]);
        self::assertSame(1, $byCode['total']);
        self::assertSame($people[6]['employee_id'], $byCode['items'][0]['employee_id']);

        // Bez diakritiky a bez ohledu na velikost písmen.
        $byName = $this->call('items', 'GET', '/api/payroll/deadlines/items', $params + ['q' => 'SYNTETICKA OSOBA 0007']);
        self::assertSame(1, $byName['total']);
    }

    /**
     * Celá skupina se projde po dávkách s kurzorem a KAŽDÁ položka jde cestou
     * jednotlivé změny: událost na časové ose i záznam v auditu. Druhý průchod
     * nic neudělá.
     */
    public function testWholeGroupIsCompletedInBatchesWithEventAndAuditPerItem(): void
    {
        $this->seedPeople($this->supplierId, 130, ['employment_contract', 'tax_declaration'], $this->overdueOn);
        $this->seedPeople($this->supplierId, 1, ['employment_contract'], $this->soonOn, 'NOVY');

        $body = ['phase' => 'overdue', 'item_key' => 'employment_contract', 'note' => self::NOTE];
        $completed = 0;
        $requests = 0;
        $afterId = 0;
        do {
            $result = $this->call('completeChecklist', 'POST', '/api/payroll/deadlines/checklist/complete', [], $body + ['after_id' => $afterId]);
            $completed += count($result['completed']);
            self::assertSame([], $result['failed']);
            $afterId = $result['next_after_id'];
            ++$requests;
        } while (!$result['complete'] && $requests < 20);

        self::assertSame(130, $completed);
        self::assertGreaterThanOrEqual(2, $requests, 'Skupina nad strop dávky se musí projít po částech.');
        self::assertSame(0, $this->pendingCount($this->supplierId, 'employment_contract', $this->overdueOn));
        self::assertSame(130, $this->pendingCount($this->supplierId, 'tax_declaration', $this->overdueOn));
        self::assertSame(1, $this->pendingCount($this->supplierId, 'employment_contract', $this->soonOn), 'Jiná fáze se nedotkne.');
        self::assertSame(130, $this->scalar(
            'SELECT COUNT(*) FROM payroll_employment_events
              WHERE supplier_id = ? AND event_type = "checklist_changed" AND note = ?',
            [$this->supplierId, self::NOTE],
        ));
        self::assertSame(130, $this->scalar(
            'SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = ? AND action = "payroll.employment.checklist_changed"',
            [$this->supplierId],
        ));
        self::assertSame(130, $this->scalar(
            'SELECT COUNT(*) FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND status = "completed" AND completed_by = ? AND note = ?',
            [$this->supplierId, $this->userId, self::NOTE],
        ));

        $again = $this->call('completeChecklist', 'POST', '/api/payroll/deadlines/checklist/complete', [], $body);
        self::assertSame([], $again['completed']);
        self::assertTrue($again['complete']);
    }

    /**
     * Výčet id: co nesplní předpoklad, vrátí se s důvodem; vyřízené se
     * přeskočí; cizí firma je pro tuhle firmu neexistující položka.
     */
    public function testSelectedItemsRespectPrerequisiteIdempotenceAndTenant(): void
    {
        $ok = $this->seedPeople($this->supplierId, 1, ['employment_contract'], $this->overdueOn)[0];
        $noStart = $this->seedPeople($this->supplierId, 1, ['legacy_start_date'], $this->overdueOn, 'BEZDATA', false)[0];
        $done = $this->seedPeople($this->supplierId, 1, ['tax_declaration'], $this->overdueOn, 'HOTOVO')[0];
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_checklist_items SET status = "completed"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $done['items']['tax_declaration']]);
        $otherSupplier = $this->createPayrollSupplier();
        $foreign = $this->seedPeople($otherSupplier, 1, ['employment_contract'], $this->overdueOn, 'CIZI')[0];

        $ids = [
            $ok['items']['employment_contract'],
            $noStart['items']['legacy_start_date'],
            $done['items']['tax_declaration'],
            $foreign['items']['employment_contract'],
        ];
        $result = $this->call('completeChecklist', 'POST', '/api/payroll/deadlines/checklist/complete', [], [
            'item_ids' => $ids,
            'note' => self::NOTE,
        ]);

        self::assertSame([$ok['items']['employment_contract']], $result['completed']);
        self::assertSame([$done['items']['tax_declaration']], array_column($result['skipped'], 'item_id'));
        $failed = array_column($result['failed'], null, 'item_id');
        self::assertSame('prerequisite_failed', $failed[$noStart['items']['legacy_start_date']]['code'] ?? null);
        self::assertStringContainsString('datum nástupu', $failed[$noStart['items']['legacy_start_date']]['message']);
        self::assertSame('not_found', $failed[$foreign['items']['employment_contract']]['code'] ?? null);
        self::assertSame('', $failed[$foreign['items']['employment_contract']]['subject'], 'Jméno z cizí firmy nesmí uniknout.');
        self::assertSame(1, $this->pendingCount($otherSupplier, 'employment_contract', $this->overdueOn));
        self::assertSame(1, $this->pendingCount($this->supplierId, 'legacy_start_date', $this->overdueOn));
    }

    public function testNoteIsRequired(): void
    {
        $person = $this->seedPeople($this->supplierId, 1, ['employment_contract'], $this->overdueOn)[0];

        $response = $this->respond('completeChecklist', $this->request('POST', '/api/payroll/deadlines/checklist/complete')
            ->withParsedBody(['item_ids' => [$person['items']['employment_contract']], 'note' => '   ']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(1, $this->pendingCount($this->supplierId, 'employment_contract', $this->overdueOn));
    }

    public function testBulkCompletionNeedsTheEmploymentWritePermission(): void
    {
        $this->seedPeople($this->supplierId, 2, ['employment_contract'], $this->overdueOn);
        $request = $this->request('POST', '/api/payroll/deadlines/checklist/complete')
            ->withAttribute('auth.effective_role', new EffectiveRole(
                43,
                'Jen podání',
                'staff',
                true,
                [
                    'payroll' => AccessLevel::WRITE->value,
                    'payroll.submissions' => AccessLevel::WRITE->value,
                ],
            ))
            ->withParsedBody(['phase' => 'overdue', 'item_key' => 'employment_contract', 'note' => self::NOTE]);

        $response = $this->respond('completeChecklist', $request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(2, $this->pendingCount($this->supplierId, 'employment_contract', $this->overdueOn));

        // Čtení skupin stejnou rolí jde — je to totéž právo jako plochý přehled.
        $read = $this->respond('groups', $this->request('GET', '/api/payroll/deadlines/groups')
            ->withAttribute('auth.effective_role', new EffectiveRole(
                43,
                'Jen podání',
                'staff',
                true,
                ['payroll' => AccessLevel::READ->value, 'payroll.submissions' => AccessLevel::READ->value],
            )));
        self::assertSame(200, $read->getStatusCode());
    }

    private function createPayrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);

        return $supplierId;
    }

    /**
     * @param list<string> $itemKeys
     * @return list<array{employee_id:int,employment_id:int,code:string,items:array<string,int>}>
     */
    private function seedPeople(
        int $supplierId,
        int $count,
        array $itemKeys,
        string $dueOn,
        string $prefix = 'SYN',
        bool $withStartDate = true,
    ): array {
        $pdo = $this->db->pdo();
        $employee = $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        );
        $employment = $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, is_primary, start_date)
             VALUES (?, ?, ?, "employment", "active", 1, ?)'
        );
        $item = $pdo->prepare(
            'INSERT INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, status, due_date)
             VALUES (?, ?, "onboarding", ?, "pending", ?)'
        );
        $people = [];
        for ($index = 1; $index <= $count; ++$index) {
            $name = sprintf('%s Syntetická osoba %04d', $prefix, $index);
            $employee->execute([$supplierId, $name]);
            $employeeId = (int) $pdo->lastInsertId();
            $code = sprintf('%s-%04d', $prefix, $index);
            $employment->execute([$supplierId, $employeeId, $code, $withStartDate ? '2026-06-01' : null]);
            $employmentId = (int) $pdo->lastInsertId();
            $items = [];
            foreach ($itemKeys as $itemKey) {
                $item->execute([$supplierId, $employmentId, $itemKey, $dueOn]);
                $items[$itemKey] = (int) $pdo->lastInsertId();
            }
            $people[] = [
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'code' => $code,
                'items' => $items,
            ];
        }

        return $people;
    }

    private function pendingCount(int $supplierId, string $itemKey, string $dueOn): int
    {
        return $this->scalar(
            'SELECT COUNT(*) FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND item_key = ? AND due_date = ? AND status = "pending"',
            [$supplierId, $itemKey, $dueOn],
        );
    }

    /** @param list<int|string> $params */
    private function scalar(string $sql, array $params): int
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string,string> $query
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function call(string $method, string $httpMethod, string $uri, array $query = [], ?array $body = null): array
    {
        $request = $this->request($httpMethod, $uri)->withQueryParams($query);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $this->respond($method, $request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function respond(string $method, ServerRequestInterface $request): ResponseInterface
    {
        return match ($method) {
            'groups' => $this->action->groups($request, new Response()),
            'items' => $this->action->items($request, new Response()),
            'completeChecklist' => $this->action->completeChecklist($request, new Response()),
            default => throw new \InvalidArgumentException($method),
        };
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function firstId(string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $statement = $this->db->pdo()->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($statement === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }
        $value = $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }
}
