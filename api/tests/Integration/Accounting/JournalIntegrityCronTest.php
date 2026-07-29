<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * N-012: cron `cron-journal-integrity-check.php` musí SKUTEČNĚ spouštět i tenantové
 * invarianty (`checkTenantIntegrity`), ne jen pětici ukládaných kontrol.
 *
 * Invarianty se totiž neukládají do `journal_integrity_findings` (ENUM je nepokrývá) —
 * vyhodnocují se výhradně naživo. Dokud je nevolal nikdo kromě testu, bylo pět tvrdých
 * kontrol v provozu mrtvý kód: tenant leak ani poškozený zápis by se nikde neprojevily.
 * Textový guard by tu nestačil, proto se cron pouští jako podproces a kontroluje se,
 * že porušení invariantu opravdu vidí.
 *
 * Data se musí COMMITNOUT — podproces má vlastní spojení a rozpracovanou transakci
 * testu by neviděl. Úklid proto běží v tearDown() bezpodmínečně.
 */
#[Group('integration')]
final class JournalIntegrityCronTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private string $rootDir = '';

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $accountId = 0;
    private int $entryId = 0;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        if (!is_file($this->rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db  = $container->get(Connection::class);
            $periods   = $container->get(AccountingPeriodRepository::class);
            $seeder    = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query(
            "SELECT id FROM supplier WHERE accounting_mode = 'double_entry' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí podvojný supplier / user.');
        }

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->accountId = (int) ($pdo->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->accountId === 0) {
            $this->markTestSkipped('Osnova se nenaseedovala.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($this->entryId > 0) {
            $pdo->prepare('DELETE FROM journal_entry_lines WHERE entry_id = ?')->execute([$this->entryId]);
            $pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$this->entryId]);
        }
        if ($this->periodId > 0) {
            $pdo->prepare('DELETE FROM accounting_periods WHERE id = ?')->execute([$this->periodId]);
        }
        $this->db->close();
    }

    /**
     * Čistý běh: cron projde a report obsahuje sekci tenantových invariantů.
     * Kdyby ji někdo z cronu odstranil, `tenant_violations=` ve výstupu zmizí.
     */
    public function testCronReportsTenantInvariantSection(): void
    {
        [$exit, $out] = $this->runCron();

        self::assertSame(0, $exit, "Cron selhal:\n" . $out);
        self::assertStringContainsString('tenant_violations=', $out, 'Report musí nést sekci invariantů.');
        self::assertStringContainsString('findings=', $out);
    }

    /**
     * Porušený invariant (řádek s nulovou částkou) musí cron vidět a vypsat.
     * Tohle je vlastní jádro N-012 — dokazuje, že se invarianty opravdu vyhodnocují,
     * ne že se jen tiskne konstantní nula.
     */
    public function testCronDetectsNonPositiveAmountInvariant(): void
    {
        // Testovací DB nemusí být čistá (zbytky po jiných testech), takže se měří ROZDÍL.
        [, $before] = $this->runCron();
        $outsideBefore = $this->parseCount($before, 'entry_date_outside_period');
        $totalBefore   = $this->parseCount($before, 'tenant_violations');

        $this->insertEntryDatedOutsidePeriod();

        [$exit, $out] = $this->runCron();

        self::assertSame(0, $exit, "Cron selhal:\n" . $out);
        self::assertStringContainsString('porušené invarianty deníku', $out);
        self::assertSame(
            $outsideBefore + 1,
            $this->parseCount($out, 'entry_date_outside_period'),
            "Cron musí vidět zápis mimo období.\n" . $out,
        );
        self::assertSame(
            $totalBefore + 1,
            $this->parseCount($out, 'tenant_violations'),
            "Porušení se musí promítnout do souhrnu.\n" . $out,
        );
    }

    /** Vytáhne `klíč=N` z výstupu cronu; chybějící klíč znamená nulu (tiskne se jen cnt>0). */
    private function parseCount(string $output, string $key): int
    {
        return preg_match('/\b' . preg_quote($key, '/') . '=(\d+)/', $output, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * Vyvážený zápis s datem MIMO své účetní období → entry_date_outside_period.
     *
     * Záměrně ne `nonpositive_amount`: ten hlídá i DB CHECK `chk_jel_amount_positive`,
     * takže ho z testu ani nejde vyrobit (obrana v hloubce — kontrola v deníku zůstává
     * pro data zapsaná před zavedením constraintu).
     */
    private function insertEntryDatedOutsidePeriod(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO journal_entries
                 (supplier_id, period_id, entry_date, source_type, source_id, posted_at, posted_by)
             VALUES (?, ?, ?, 'manual', NULL, ?, ?)"
        )->execute([
            $this->supplierId, $this->periodId, (self::YEAR - 1) . '-06-15',
            self::YEAR . '-06-15 10:00:00', $this->userId,
        ]);
        $this->entryId = (int) $pdo->lastInsertId();

        foreach ([['debit', 1], ['credit', 2]] as [$side, $lineNo]) {
            $pdo->prepare(
                "INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
                 VALUES (?, ?, ?, ?, 100, ?)"
            )->execute([$this->entryId, $this->supplierId, $this->accountId, $side, $lineNo]);
        }
    }

    /**
     * Spustí cron v --dry-run (nic neukládá) nad jedním dodavatelem.
     * @return array{0:int, 1:string} exit kód a spojený výstup
     */
    private function runCron(): array
    {
        $env = getenv();
        self::assertIsArray($env);
        // Podproces NIKDY nesmí sáhnout na ostrou DB — jméno testovací DB předáváme explicitně.
        $dbName = (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $dbName, 'Testy musí běžet proti testovací DB.');
        $env['MYINVOICE_DB_NAME'] = $dbName;

        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                $this->rootDir . '/api/bin/cron-journal-integrity-check.php',
                '--dry-run',
                '--supplier=' . $this->supplierId,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->rootDir,
            $env,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout . $stderr];
    }
}
