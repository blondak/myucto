<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\CronJobsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Cron\CronHealth;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Konec issue #6: kvůli špatnému jménu wrapperu v Dockeru neběžela žádná
 * plánovaná úloha a stránka to celou dobu tiše ukazovala jako "ještě
 * neběželo" — bez jediného varování. Oprava (CronHealth::PENDING) zajišťuje,
 * že "nikdy neběželo" je poplach jen tehdy, když instalace už měla na první
 * běh dost času (víc než periodu té konkrétní úlohy); na čerstvé instalaci je
 * to jen přechodný stav.
 *
 * Test se záměrně opírá o dva katalogové skripty s VELMI rozdílnou periodou
 * (`cron-payroll-post`, 792 h / 33 dní, vs. `cron-cleanup`, 36 h) a bez cizí
 * manipulace s tabulkou `migrations` čte skutečné stáří test DB — dokud
 * není starší než 33 dní (test DB se v praxi rebuilduje mnohem dřív), obě
 * větve ověřují, že STEJNÁ instalace hodnotí "nikdy neběželo" jinak podle
 * periody konkrétní úlohy, ne podle nějakého globálního přepínače.
 */
#[Group('integration')]
final class CronJobsActionInstallAgeTest extends TestCase
{
    private const LONG_PERIOD_SCRIPT = 'cron-payroll-post';
    private const SHORT_PERIOD_SCRIPT = 'cron-cleanup';

    private Connection $db;
    private CronJobsAction $action;

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
    }

    public function testNeverRunJobsAreClassifiedByTheirOwnPeriodAgainstRealInstallAge(): void
    {
        $pdo = $this->db->pdo();
        $firstMigratedAt = $pdo->query('SELECT MIN(applied_at) FROM migrations')->fetchColumn();
        self::assertIsString($firstMigratedAt, 'Test DB musí mít aspoň jednu migraci.');
        $installAgeSec = CronHealth::installAgeSec($firstMigratedAt, time());
        self::assertNotNull($installAgeSec);

        $jobs = $this->fetchJobs();
        $longPeriod = $this->findJob($jobs, self::LONG_PERIOD_SCRIPT);
        $shortPeriod = $this->findJob($jobs, self::SHORT_PERIOD_SCRIPT);

        // Oba skripty musí být v test DB skutečně bez heartbeatu, jinak test
        // netestuje to, co má — vybraly se schválně jako "obskurní" katalogové
        // položky, které se v testovací sadě samy nespouští.
        self::assertNull($longPeriod['last_ok_started_at'], self::LONG_PERIOD_SCRIPT . ' by v test DB neměl mít heartbeat — vyber jiný pár skriptů.');
        self::assertNull($shortPeriod['last_ok_started_at'], self::SHORT_PERIOD_SCRIPT . ' by v test DB neměl mít heartbeat — vyber jiný pár skriptů.');

        // Dlouhá perioda (33 dní): dokud instalace tuhle periodu nepřerostla,
        // "nikdy neběželo" MUSÍ zůstat PENDING, ne poplach.
        if ($installAgeSec <= (int) $longPeriod['max_age_hours'] * 3600) {
            self::assertSame(CronHealth::PENDING, $longPeriod['health']);
        }

        // Krátká perioda (36 h): instalace ji dávno přerostla (test DB je v
        // praxi vždy starší), takže tohle MUSÍ být skutečný nález.
        if ($installAgeSec > (int) $shortPeriod['max_age_hours'] * 3600) {
            self::assertSame(CronHealth::NEVER_RAN, $shortPeriod['health']);
        } else {
            self::assertSame(CronHealth::PENDING, $shortPeriod['health']);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchJobs(): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/admin/cron-jobs', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'admin']);

        $response = $this->action->__invoke($request, (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->json($response);
        return $body['data']['jobs'] ?? $body['jobs'] ?? [];
    }

    /**
     * @param array<int,array<string,mixed>> $jobs
     * @return array<string,mixed>
     */
    private function findJob(array $jobs, string $script): array
    {
        foreach ($jobs as $job) {
            if (($job['script'] ?? null) === $script) {
                return $job;
            }
        }
        self::fail("Úloha {$script} v odpovědi chybí.");
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return (array) json_decode((string) $response->getBody(), true);
    }
}
