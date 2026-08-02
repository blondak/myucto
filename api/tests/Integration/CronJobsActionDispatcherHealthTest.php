<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\CronJobsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronHealth;
use MyInvoice\Service\Cron\CronScheduleMode;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Zapojení {@see CronHealth} do přehledu úloh.
 *
 * Scénář, kvůli kterému stav IDLE vznikl: v režimu dispatcheru se
 * `cron-epo-status` (max_age 1 h) nespouští, když nemá práci — heartbeat proto
 * stárne a UI ho hlásilo jako `overdue`, přestože je všechno v pořádku. Test
 * ověřuje, že se ticho promlčí jen tehdy, když je naživu sám dispatcher.
 *
 * Původní režim i heartbeaty se v tearDown vrací do původního stavu.
 */
#[Group('integration')]
final class CronJobsActionDispatcherHealthTest extends TestCase
{
    private const GATED = 'cron-epo-status';

    private Connection $db;
    private CronJobsAction $action;
    private string $savedMode = CronScheduleMode::INDIVIDUAL;
    /** @var array<string,array<string,mixed>|null> */
    private array $savedHeartbeats = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(CronJobsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->savedMode = CronScheduleMode::current($pdo);
        foreach ([CronCatalog::DISPATCHER_SCRIPT, self::GATED] as $script) {
            $stmt = $pdo->prepare('SELECT * FROM cron_heartbeat WHERE script = ?');
            $stmt->execute([$script]);
            $this->savedHeartbeats[$script] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        CronScheduleMode::set($pdo, CronScheduleMode::DISPATCHER, null);
        // Gatovaná úloha mlčí půl dne — sama o sobě dávno po limitu.
        $this->writeHeartbeat(self::GATED, 'noop', '-12 hours');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        CronScheduleMode::set($pdo, $this->savedMode, null);
        foreach ($this->savedHeartbeats as $script => $row) {
            $pdo->prepare('DELETE FROM cron_heartbeat WHERE script = ?')->execute([$script]);
            if ($row === null) {
                continue;
            }
            $cols = array_keys($row);
            $pdo->prepare(sprintf(
                'INSERT INTO cron_heartbeat (%s) VALUES (%s)',
                implode(',', $cols),
                implode(',', array_fill(0, count($cols), '?')),
            ))->execute(array_values($row));
        }
    }

    public function testGatedJobIsIdleWhileDispatcherLives(): void
    {
        $this->writeHeartbeat(CronCatalog::DISPATCHER_SCRIPT, 'noop', '-30 seconds');

        $job = $this->fetchJob(self::GATED);
        self::assertSame(CronHealth::IDLE, $job['health']);
        self::assertSame(CronHealth::SOURCE_DISPATCHER, $job['health_source']);
    }

    public function testGatedJobIsOverdueAgainWhenDispatcherStops(): void
    {
        $this->writeHeartbeat(CronCatalog::DISPATCHER_SCRIPT, 'noop', '-5 hours');

        $job = $this->fetchJob(self::GATED);
        self::assertSame(CronHealth::OVERDUE, $job['health']);
        self::assertSame(CronHealth::SOURCE_SELF, $job['health_source']);
    }

    /** Selhávající dispatcher nesmí ticho podřízené úlohy zakrýt. */
    public function testFailingDispatcherDoesNotMaskSilence(): void
    {
        $this->writeHeartbeat(CronCatalog::DISPATCHER_SCRIPT, 'error', '-5 hours', '-30 seconds');

        $job = $this->fetchJob(self::GATED);
        self::assertSame(CronHealth::OVERDUE, $job['health']);
    }

    /** V režimu jednotlivých úloh se nic nepromlčuje — úloha se spouští vždy. */
    public function testIndividualModeKeepsOverdue(): void
    {
        $pdo = $this->db->pdo();
        CronScheduleMode::set($pdo, CronScheduleMode::INDIVIDUAL, null);
        $this->writeHeartbeat(CronCatalog::DISPATCHER_SCRIPT, 'noop', '-30 seconds');

        $job = $this->fetchJob(self::GATED);
        self::assertSame(CronHealth::OVERDUE, $job['health']);
    }

    /** @return array<string,mixed> */
    private function fetchJob(string $script): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/admin/cron-jobs', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'admin']);

        $response = $this->action->__invoke($request, (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $jobs = $this->json($response)['data']['jobs'] ?? $this->json($response)['jobs'] ?? [];
        foreach ($jobs as $job) {
            if (($job['script'] ?? null) === $script) {
                return $job;
            }
        }
        self::fail("Úloha {$script} v odpovědi chybí.");
    }

    /**
     * @param 'ok'|'noop'|'error' $status
     * @param string $tickAgo relativní čas posledního ticku
     * @param string|null $okAgo relativní čas posledního úspěchu (default = tick)
     */
    private function writeHeartbeat(string $script, string $status, string $tickAgo, ?string $okAgo = null): void
    {
        $tick = date('Y-m-d H:i:s', (int) strtotime($tickAgo));
        $ok   = $status === 'error' && $okAgo === null
            ? null
            : date('Y-m-d H:i:s', (int) strtotime($okAgo ?? $tickAgo));

        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM cron_heartbeat WHERE script = ?')->execute([$script]);
        $pdo->prepare(
            'INSERT INTO cron_heartbeat
                    (script, last_tick_at, last_status, last_started_at, last_finished_at,
                     last_duration_ms, last_exit_code, last_ok_at)
             VALUES (?, ?, ?, ?, ?, 5, ?, ?)'
        )->execute([$script, $tick, $status, $tick, $tick, $status === 'error' ? 1 : 0, $ok]);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return (array) json_decode((string) $response->getBody(), true);
    }
}
