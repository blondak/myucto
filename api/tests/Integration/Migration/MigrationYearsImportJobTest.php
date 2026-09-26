<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImportJobService;
use MyInvoice\Service\Migration\Premier\PremierUploads;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Job převodu víc roků (POHODA, PREMIER): roky běží vzestupně, každý má vlastní běh
 * a protokol, po roce s chybou nebo zrušením se další roky nespouštějí a job nese
 * nejhorší stav. Job se starším parametrem `year` převede jeden rok. Syntetická data,
 * izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class MigrationYearsImportJobTest extends TestCase
{
    private Connection $db;
    private ImportJobRepository $jobs;
    private PohodaImportJobService $pohoda;
    private PremierImportJobService $premier;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    /** @var list<array{string,int,string}> nahrané exporty k úklidu: systém, firma, token */
    private array $uploads = [];
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->jobs = $container->get(ImportJobRepository::class);
            $this->pohoda = $container->get(PohodaImportJobService::class);
            $this->premier = $container->get(PremierImportJobService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('pohoda_import_map', 'pohoda_key') || !$this->db->hasColumn('premier_import_map', 'premier_key')) {
            $this->markTestSkipped('Chybí migrace převodů z POHODY a PREMIER.');
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->uploads as [$system, $supplierId, $token]) {
            if ($system === 'pohoda') {
                PohodaUploads::purge($supplierId, $token);
                @rmdir(PohodaUploads::base($supplierId));
            } else {
                PremierUploads::purge($supplierId, $token);
                @rmdir(PremierUploads::base($supplierId));
            }
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /** Roky zadané sestupně běží vzestupně, každý s vlastním protokolem; log jobu ukazuje „rok X z N". */
    public function testPremierYearsRunAscendingEachWithOwnRun(): void
    {
        $supplierId = $this->supplier(SyntheticPremierBackup::ICO, SyntheticPremierBackup::NAME);
        $token = $this->premierUpload($supplierId);
        $jobId = $this->job($supplierId, PremierImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'import', 'ico' => SyntheticPremierBackup::ICO,
            'years' => [SyntheticPremierBackup::YEAR2, SyntheticPremierBackup::YEAR1],
        ]);
        $this->premier->run($jobId);

        $runs = $this->runs('premier_imports', $jobId);
        self::assertSame([SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2], array_column($runs, 'year'));
        $job = $this->jobs->findById($jobId);
        self::assertSame(\MyInvoice\Service\Migration\ImportYears::worstStatus(array_column($runs, 'status')), $job['status']);
        self::assertContains($job['status'], ['completed', 'completed_with_warnings'], (string) $job['log_text'] . (string) $job['last_error']);
        self::assertStringContainsString('Rok ' . SyntheticPremierBackup::YEAR1 . ' (1 z 2)', (string) $job['log_text']);
        self::assertStringContainsString('Rok ' . SyntheticPremierBackup::YEAR2 . ' (2 z 2)', (string) $job['log_text']);
        self::assertSame((int) $job['total_items'], (int) $job['processed']);
        $protocol = $this->protocol('premier_imports', $runs[1]['id']);
        self::assertSame(['years' => [SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2], 'index' => 2, 'dry_run_isolated' => false], $protocol['job_years'] ?? null);
    }

    /** Rok s chybou zastaví roky po něm; job skončí chybou. */
    public function testPremierStopsAfterFailedYear(): void
    {
        // IČO firmy nesedí se zálohou: kontrola před převodem prvního roku selže.
        $supplierId = $this->supplier('99999994', SyntheticPremierBackup::NAME);
        $token = $this->premierUpload($supplierId);
        $jobId = $this->job($supplierId, PremierImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'dry_run', 'ico' => SyntheticPremierBackup::ICO,
            'years' => [SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2],
        ]);
        $this->premier->run($jobId);

        self::assertSame([[SyntheticPremierBackup::YEAR1, 'failed']], array_map(static fn (array $r): array => [$r['year'], $r['status']], $this->runs('premier_imports', $jobId)));
        $job = $this->jobs->findById($jobId);
        self::assertSame('failed', $job['status']);
        self::assertStringContainsString('roky ' . SyntheticPremierBackup::YEAR2 . ' se nespouštějí', (string) $job['log_text']);
        self::assertStringContainsString('Zkouška nanečisto převádí každý rok samostatně', (string) $job['log_text']);
    }

    /** Zrušení během prvního roku zastaví i zbylé roky. */
    public function testPremierCancelStopsRemainingYears(): void
    {
        $supplierId = $this->supplier(SyntheticPremierBackup::ICO, SyntheticPremierBackup::NAME);
        $token = $this->premierUpload($supplierId);
        $jobId = $this->job($supplierId, PremierImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'import', 'ico' => SyntheticPremierBackup::ICO,
            'years' => [SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2],
        ]);
        $this->db->pdo()->prepare('UPDATE import_jobs SET cancel_requested = 1 WHERE id = ?')->execute([$jobId]);
        $this->premier->run($jobId);

        self::assertSame([[SyntheticPremierBackup::YEAR1, 'cancelled']], array_map(static fn (array $r): array => [$r['year'], $r['status']], $this->runs('premier_imports', $jobId)));
        self::assertSame('cancelled', $this->jobs->findById($jobId)['status']);
    }

    /** Job se starším parametrem `year` převede jen ten rok. */
    public function testPremierJobWithLegacyYearParameter(): void
    {
        $supplierId = $this->supplier(SyntheticPremierBackup::ICO, SyntheticPremierBackup::NAME);
        $token = $this->premierUpload($supplierId);
        $jobId = $this->job($supplierId, PremierImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'dry_run', 'ico' => SyntheticPremierBackup::ICO, 'year' => SyntheticPremierBackup::YEAR1,
        ]);
        $this->premier->run($jobId);

        self::assertSame([SyntheticPremierBackup::YEAR1], array_column($this->runs('premier_imports', $jobId), 'year'));
        self::assertStringNotContainsString('(1 z', (string) $this->jobs->findById($jobId)['log_text']);
    }

    /**
     * POHODA: dvě agendy vzestupně, každá s vlastním během. Zkouška nanečisto víc roků
     * v logu upozorní, že pozdější rok nevidí data předchozího.
     */
    public function testPohodaAgendasRunAscendingEachWithOwnRun(): void
    {
        $supplierId = $this->supplier(SyntheticPohodaExport::ICO, SyntheticPohodaExport::NAME);
        $token = $this->pohodaUpload($supplierId, true);
        $jobId = $this->job($supplierId, PohodaImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'dry_run', 'ico' => SyntheticPohodaExport::ICO, 'kind' => 'accounting',
            'years' => [SyntheticPohodaExport::NEXT_YEAR, SyntheticPohodaExport::YEAR],
        ]);
        $this->pohoda->run($jobId);

        $runs = $this->runs('pohoda_imports', $jobId);
        self::assertSame([SyntheticPohodaExport::YEAR, SyntheticPohodaExport::NEXT_YEAR], array_column($runs, 'year'));
        $job = $this->jobs->findById($jobId);
        self::assertSame(\MyInvoice\Service\Migration\ImportYears::worstStatus(array_column($runs, 'status')), $job['status']);
        self::assertStringContainsString('Zkouška nanečisto převádí každý rok samostatně', (string) $job['log_text']);
        self::assertStringContainsString('Rok ' . SyntheticPohodaExport::NEXT_YEAR . ' (2 z 2)', (string) $job['log_text']);
        // Oba roky vybrané: agenda roku 2026 převádí i své doklady roku 2027.
        self::assertSame([], $this->protocol('pohoda_imports', $runs[0]['id'])['agenda']['skipped_years']);
    }

    /** Pozdější rok agendy bez vlastní agendy: nevybraný se přeskočí, vybraný jde s agendou v jednom běhu. */
    public function testPohodaLaterYearOfAgendaIsPartOfAgendaRun(): void
    {
        $supplierId = $this->supplier(SyntheticPohodaExport::ICO, SyntheticPohodaExport::NAME);
        $token = $this->pohodaUpload($supplierId, false);
        $jobId = $this->job($supplierId, PohodaImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'dry_run', 'ico' => SyntheticPohodaExport::ICO, 'kind' => 'accounting', 'year' => SyntheticPohodaExport::YEAR,
        ]);
        $this->pohoda->run($jobId);

        $runs = $this->runs('pohoda_imports', $jobId);
        self::assertSame([SyntheticPohodaExport::YEAR], array_column($runs, 'year'));
        self::assertSame([SyntheticPohodaExport::NEXT_YEAR], $this->protocol('pohoda_imports', $runs[0]['id'])['agenda']['skipped_years']);
        self::assertStringContainsString('bez dokladů nevybraného roku ' . SyntheticPohodaExport::NEXT_YEAR, (string) $this->jobs->findById($jobId)['log_text']);
    }

    /** POHODA: agenda s chybou zastaví agendy po ní. */
    public function testPohodaStopsAfterFailedYear(): void
    {
        $supplierId = $this->supplier('99999994', SyntheticPohodaExport::NAME);
        $token = $this->pohodaUpload($supplierId, true);
        $jobId = $this->job($supplierId, PohodaImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'import', 'ico' => SyntheticPohodaExport::ICO, 'kind' => 'accounting',
            'years' => [SyntheticPohodaExport::YEAR, SyntheticPohodaExport::NEXT_YEAR],
        ]);
        $this->pohoda->run($jobId);

        self::assertSame([[SyntheticPohodaExport::YEAR, 'failed']], array_map(static fn (array $r): array => [$r['year'], $r['status']], $this->runs('pohoda_imports', $jobId)));
        $job = $this->jobs->findById($jobId);
        self::assertSame('failed', $job['status']);
        self::assertStringContainsString('roky ' . SyntheticPohodaExport::NEXT_YEAR . ' se nespouštějí', (string) $job['log_text']);
        self::assertTrue(PohodaUploads::hasMeta($supplierId, $token), 'Export po nepovedeném převodu zůstává.');
    }

    private function supplier(string $ico, string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, vat_period, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 1", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, "monthly", ?, ?, "tax_evidence")'
        )->execute([$name, $this->czId, $ico, 'CZ' . $ico, $this->currencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function premierUpload(int $supplierId): string
    {
        $token = PremierUploads::newToken();
        $this->uploads[] = ['premier', $supplierId, $token];
        $dir = SyntheticPremierBackup::writeDir(PremierUploads::backupDir($supplierId, $token));
        PremierUploads::writeMeta($supplierId, $token, ['token' => $token, 'sha256' => str_repeat('0', 64), 'agendas' => PremierBackup::overview($dir)]);
        return $token;
    }

    /** `$nextAgenda`: vedle agendy roku YEAR (s doklady roku NEXT_YEAR) i vlastní agenda roku NEXT_YEAR. */
    private function pohodaUpload(int $supplierId, bool $nextAgenda): string
    {
        $token = PohodaUploads::newToken();
        $this->uploads[] = ['pohoda', $supplierId, $token];
        $root = PohodaUploads::exportDir($supplierId, $token);
        SyntheticPohodaExport::write($root, unbooked: true);
        if ($nextAgenda) {
            SyntheticPohodaExport::writeNextYear($root);
        }
        PohodaUploads::writeMeta($supplierId, $token, ['token' => $token, 'sha256' => str_repeat('0', 64), 'agendas' => PohodaExport::overview($root)]);
        return $token;
    }

    /** @param array<string,mixed> $params */
    private function job(int $supplierId, string $source, array $params): int
    {
        return $this->jobs->create($supplierId, $source, $params, $this->userId);
    }

    /** @return list<array{id:int,year:int,status:string}> */
    private function runs(string $table, int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id, agenda_year, status FROM {$table} WHERE job_id = ? ORDER BY id");
        $stmt->execute([$jobId]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'year' => (int) $r['agenda_year'], 'status' => (string) $r['status']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private function protocol(string $table, int $runId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT protocol FROM {$table} WHERE id = ?");
        $stmt->execute([$runId]);
        return (array) json_decode((string) $stmt->fetchColumn(), true);
    }
}
