<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollTimeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollTimeApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTimeAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employmentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollTimeAction::class);
        foreach ([
            'payroll_work_calendars',
            'payroll_shifts',
            'payroll_time_entries',
            'payroll_time_months',
            'payroll_time_imports',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace MZ-06 neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)'
        )->execute([$this->supplierId, $this->otherSupplierId]);

        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 1, 1, 0, 42000, 0, 1)'
        )->execute([
            $this->supplierId,
            'Syntetická zaměstnankyně',
            'employee',
            'hpp',
        ]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, 'legacy')"
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, 'SYN-TIME-1', 'employment', 'active',
                     '2026-01-01', 4200000, 0)"
        )->execute([$this->supplierId, $employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
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

    public function testCalendarShiftAndActualTimeBuildMonthlySummary(): void
    {
        $calendar = $this->action->calendar(
            $this->request('PUT', '/api/payroll/time/calendars/' . $this->employmentId)
                ->withParsedBody($this->calendarPayload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $calendar->getStatusCode());
        $calendarId = (int) $this->json($calendar)['calendar']['id'];

        $shift = $this->action->shift(
            $this->request('POST', '/api/payroll/time/shifts')->withParsedBody([
                'employment_id' => $this->employmentId,
                'calendar_id' => $calendarId,
                'starts_at' => '2026-05-04T08:00:00+02:00',
                'ends_at' => '2026-05-04T16:00:00+02:00',
                'timezone' => 'Europe/Prague',
                'break_minutes' => 30,
                'remote_work' => true,
                'standby_minutes' => 0,
                'publish' => true,
                'row_version' => 0,
                'month_row_version' => 0,
                'supersedes_id' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $shift->getStatusCode());
        self::assertSame(2, $this->json($shift)['month']['row_version']);

        $entry = $this->saveEntry(monthVersion: 2);
        self::assertSame(201, $entry->getStatusCode());

        $month = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        self::assertSame(200, $month->getStatusCode());
        $items = $this->json($month)['items'];
        self::assertCount(1, $items);
        self::assertSame(450, $items[0]['summary']['planned_minutes']);
        self::assertSame(450, $items[0]['summary']['actual_minutes']);
        self::assertSame(0, $items[0]['summary']['difference_minutes']);
        self::assertSame(19 * 480, $items[0]['summary']['fund_minutes']);
        self::assertTrue($items[0]['shifts'][0]['remote_work']);
    }

    public function testApprovedMonthRejectsChangesAndReopenCreatesRevision(): void
    {
        $entryResponse = $this->saveEntry(monthVersion: 0);
        $entry = $this->json($entryResponse)['entry'];
        $month = $this->json($entryResponse)['month'];

        $approved = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $month['row_version'],
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(200, $approved->getStatusCode());
        $approvedMonth = $this->json($approved)['month'];
        self::assertSame('approved', $approvedMonth['status']);

        $locked = $this->saveEntry(
            monthVersion: (int) $approvedMonth['row_version'],
            startsAt: '2026-05-05T08:00:00+02:00',
            endsAt: '2026-05-05T16:00:00+02:00',
        );
        self::assertSame(409, $locked->getStatusCode());
        self::assertSame('payroll_time_locked', $this->json($locked)['error']['code']);

        $lockedCalendarPayload = $this->calendarPayload();
        $lockedCalendarPayload['valid_from'] = '2026-05-01';
        $lockedCalendarPayload['month_row_version'] = $approvedMonth['row_version'];
        $lockedCalendar = $this->action->calendar(
            $this->request('PUT', '/api/payroll/time/calendars/' . $this->employmentId)
                ->withParsedBody($lockedCalendarPayload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(409, $lockedCalendar->getStatusCode());
        self::assertSame(
            'payroll_time_locked',
            $this->json($lockedCalendar)['error']['code'],
        );

        $reopened = $this->action->reopen(
            $this->request('POST', '/api/payroll/time/months/2026-05/reopen')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $approvedMonth['row_version'],
                    'reason' => 'Oprava syntetického záznamu',
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(200, $reopened->getStatusCode());
        $openMonth = $this->json($reopened)['month'];
        self::assertSame(2, $openMonth['revision_no']);

        $correction = $this->saveEntry(
            monthVersion: (int) $openMonth['row_version'],
            startsAt: '2026-05-04T08:00:00+02:00',
            endsAt: '2026-05-04T15:30:00+02:00',
            supersedesId: (int) $entry['id'],
            rowVersion: (int) $entry['row_version'],
        );
        self::assertSame(201, $correction->getStatusCode());
        self::assertSame(2, $this->json($correction)['entry']['revision_no']);

        $events = $this->db->pdo()->prepare(
            'SELECT action FROM payroll_time_month_events
              WHERE supplier_id = ? ORDER BY id'
        );
        $events->execute([$this->supplierId]);
        self::assertSame(
            ['created', 'changed', 'approved', 'reopened', 'changed'],
            $events->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testCsvPreviewPartialImportAndReplayAreIdempotent(): void
    {
        $csv = implode("\n", [
            'employment_code;starts_at;ends_at;timezone;category;break_minutes;external_id',
            'SYN-TIME-1;2026-05-04T08:00:00+02:00;2026-05-04T16:00:00+02:00;Europe/Prague;regular;30;EXT-1',
            'SYN-TIME-1;2026-05-04T08:00:00+02:00;2026-05-04T16:00:00+02:00;Europe/Prague;regular;30;EXT-1',
            'UNKNOWN;2026-05-05T08:00:00+02:00;2026-05-05T16:00:00+02:00;Europe/Prague;regular;30;EXT-2',
            'SYN-TIME-1;2026-05-06T08:00:00+02:00;2026-05-06T16:00:00+02:00;Europe/Prague;unsupported;30;EXT-3',
        ]);
        $payload = [
            'period' => '2026-05',
            'format' => 'csv',
            'original_name' => 'synthetic-time.csv',
            'content' => $csv,
        ];
        $preview = $this->action->previewImport(
            $this->request('POST', '/api/payroll/time/imports/preview')
                ->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(200, $preview->getStatusCode());
        $summary = $this->json($preview)['preview'];
        self::assertSame(1, $summary['accepted_rows']);
        self::assertSame(2, $summary['rejected_rows']);
        self::assertSame(1, $summary['duplicate_rows']);

        $first = $this->action->import(
            $this->request('POST', '/api/payroll/time/imports')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $first->getStatusCode());
        $firstImport = $this->json($first)['import'];
        self::assertSame('partial', $firstImport['status']);
        self::assertSame(1, $firstImport['accepted_rows']);
        self::assertSame(2, $firstImport['rejected_rows']);
        self::assertSame(1, $firstImport['duplicate_rows']);

        $replay = $this->action->import(
            $this->request('POST', '/api/payroll/time/imports')->withParsedBody($payload),
            new Response(),
        );
        $replayed = $this->json($replay)['import'];
        self::assertSame($firstImport['id'], $replayed['id']);
        self::assertTrue($replayed['replayed']);
        self::assertSame(1, $this->countRows('payroll_time_entries'));
    }

    public function testCrossMonthEntryBelongsOnlyToLocalStartMonth(): void
    {
        $entry = $this->saveEntry(
            monthVersion: 0,
            startsAt: '2026-05-31T22:00:00+02:00',
            endsAt: '2026-06-01T06:00:00+02:00',
        );
        self::assertSame(201, $entry->getStatusCode());

        $may = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        $june = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-06']),
            new Response(),
        );

        self::assertSame(450, $this->json($may)['items'][0]['summary']['actual_minutes']);
        self::assertCount(1, $this->json($may)['items'][0]['entries']);
        self::assertSame(0, $this->json($june)['items'][0]['summary']['actual_minutes']);
        self::assertCount(0, $this->json($june)['items'][0]['entries']);
    }

    public function testTenantIsolationAndBearerAreFailClosed(): void
    {
        $foreign = $this->action->month(
            $this->request(
                'GET',
                '/api/payroll/time/month',
                supplierId: $this->otherSupplierId,
            )->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        self::assertSame([], $this->json($foreign)['items']);

        $bearer = $this->action->month(
            $this->request('GET', '/api/payroll/time/month', authMethod: 'bearer')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
    }

    private function saveEntry(
        int $monthVersion,
        string $startsAt = '2026-05-04T08:00:00+02:00',
        string $endsAt = '2026-05-04T16:00:00+02:00',
        ?int $supersedesId = null,
        int $rowVersion = 0,
    ): Response {
        return $this->action->entry(
            $this->request('POST', '/api/payroll/time/entries')->withParsedBody([
                'employment_id' => $this->employmentId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => 'Europe/Prague',
                'category' => 'regular',
                'break_minutes' => 30,
                'row_version' => $rowVersion,
                'month_row_version' => $monthVersion,
                'supersedes_id' => $supersedesId,
            ]),
            new Response(),
        );
    }

    /** @return array<string,mixed> */
    private function calendarPayload(): array
    {
        return [
            'name' => 'Syntetický pravidelný týden',
            'timezone' => 'Europe/Prague',
            'schedule_type' => 'regular',
            'week_pattern' => [
                '1' => 480,
                '2' => 480,
                '3' => 480,
                '4' => 480,
                '5' => 480,
                '6' => 0,
                '7' => 0,
            ],
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'row_version' => 0,
            'month_row_version' => 0,
            'days' => [],
        ];
    }

    private function countRows(string $table): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?"
        );
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function request(
        string $method,
        string $uri,
        ?int $supplierId = null,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
