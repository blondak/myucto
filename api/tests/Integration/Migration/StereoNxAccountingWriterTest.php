<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingWriter;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportMap;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StereoNxAccountingWriterTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxAccountingWriter $writer;
    private int $supplierId;
    private int $userId;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php') && !getenv('MYINVOICE_DB_NAME')) {
            self::markTestSkipped('Integration database is not configured.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->writer = new StereoNxAccountingWriter(
            $this->db,
            $container->get(ChartOfAccountsRepository::class),
            $container->get(ChartOfAccountsSeeder::class),
            $container->get(AccountingPeriodRepository::class),
            $container->get(JournalEntryRepository::class),
            $container->get(StereoNxImportMap::class),
        );
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Run ci-seed.php in the isolated test database.');
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $identity = SyntheticStereoNxAccountingTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name = ?, ic = ?, dic = ?, accounting_mode = 'double_entry', is_vat_payer = 1 WHERE id = ?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testWritesExactChartJournalOpeningBalancesAndIsIdempotent(): void
    {
        $prepared = $this->writer->prepare($this->backup(1250.50, -50.25));
        self::assertTrue($prepared['ok'], json_encode($prepared['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame(4, $prepared['counts']['journal_entries']);
        self::assertSame(2, $prepared['counts']['opening_entries']);
        self::assertCount(1, $prepared['warnings']);
        self::assertSame('journal_year_mismatch', $prepared['warnings'][0]['code']);
        self::assertSame(4, $prepared['warnings'][0]['count']);
        self::assertSame('Účetní období určeno podle data účetního případu; pomocný rok se u 4 řádků liší.', $prepared['warnings'][0]['message']);

        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT stereo_writer_rollback');
        $rolledBack = $this->writer->write($prepared, $this->supplierId, $this->userId);
        self::assertTrue($pdo->inTransaction(), 'Writer must not commit the caller transaction.');
        self::assertSame(4, $rolledBack['journal_entries_created']);
        self::assertSame(8, $rolledBack['journal_lines']);
        $pdo->exec('ROLLBACK TO SAVEPOINT stereo_writer_rollback');
        $pdo->exec('RELEASE SAVEPOINT stereo_writer_rollback');
        self::assertSame(0, $this->rowCount('journal_entries'));
        self::assertSame(0, $this->mapCount());

        $first = $this->writer->write($prepared, $this->supplierId, $this->userId);
        self::assertSame(4, $first['journal_entries_created']);
        self::assertGreaterThanOrEqual(3, $first['accounts_created']);
        self::assertSame(1, $first['periods_created']);
        self::assertSame(4, $this->rowCount('journal_entries'));
        self::assertSame(8, $this->rowCount('journal_entry_lines'));
        self::assertSame(2, (int) $this->value(
            'SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = ? AND is_red_storno = 1',
            [$this->supplierId],
        ));
        self::assertSame(2120025, (int) $this->value(
            "SELECT ROUND(SUM(signed_amount) * 100) FROM journal_entry_lines WHERE supplier_id = ? AND side = 'debit'",
            [$this->supplierId],
        ));
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'opening'", [$this->supplierId]));
        self::assertSame(2026, (int) $this->value('SELECT fiscal_year FROM accounting_periods WHERE supplier_id = ?', [$this->supplierId]));
        self::assertNotFalse($this->value("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '221kb'", [$this->supplierId]));
        self::assertSame([
            '221kb' => 120025,
            '321' => -120025,
            '353001' => 1000000,
            '411010' => -1000000,
            '701' => 0,
        ], $this->balances());

        $before = $this->snapshot();
        $repeat = $this->writer->write($prepared, $this->supplierId, $this->userId);
        self::assertSame(0, $repeat['journal_entries_created']);
        self::assertSame(4, $repeat['journal_entries_existing']);
        self::assertSame($before, $this->snapshot());
    }

    public function testChangedSourceAndChangedTargetAreRejectedWithoutAdditionalRows(): void
    {
        $prepared = $this->writer->prepare($this->backup(1250.50, -50.25));
        $this->writer->write($prepared, $this->supplierId, $this->userId);
        $before = $this->snapshot();
        $pdo = $this->db->pdo();

        $changedSource = $this->writer->prepare($this->backup(1250.50, 50.25));
        $pdo->exec('SAVEPOINT stereo_writer_changed_source');
        try {
            $this->writer->write($changedSource, $this->supplierId, $this->userId);
            self::fail('Changed source row must be rejected.');
        } catch (StereoNxException $e) {
            self::assertSame('source_changed', $e->errorCode);
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT stereo_writer_changed_source');
            $pdo->exec('RELEASE SAVEPOINT stereo_writer_changed_source');
        }
        self::assertSame($before, $this->snapshot());

        $entryId = (int) $this->value(
            "SELECT DISTINCT l.entry_id FROM journal_entry_lines l
              WHERE l.supplier_id = ? AND l.is_red_storno = 1 LIMIT 1",
            [$this->supplierId],
        );
        $pdo->exec('SAVEPOINT stereo_writer_changed_target');
        $pdo->prepare('UPDATE journal_entry_lines SET amount = amount + 1 WHERE entry_id = ? AND line_no = 1')
            ->execute([$entryId]);
        try {
            $this->writer->write($prepared, $this->supplierId, $this->userId);
            self::fail('Changed target posting must be rejected.');
        } catch (StereoNxException $e) {
            self::assertSame('mapped_target_changed', $e->errorCode);
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT stereo_writer_changed_target');
            $pdo->exec('RELEASE SAVEPOINT stereo_writer_changed_target');
        }
        self::assertSame($before, $this->snapshot());

        $pdo->exec('SAVEPOINT stereo_writer_changed_red_flag');
        $pdo->prepare('UPDATE journal_entry_lines SET is_red_storno = 0 WHERE entry_id = ?')
            ->execute([$entryId]);
        try {
            $this->writer->write($prepared, $this->supplierId, $this->userId);
            self::fail('Changed red storno flag must be rejected.');
        } catch (StereoNxException $e) {
            self::assertSame('mapped_target_changed', $e->errorCode);
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT stereo_writer_changed_red_flag');
            $pdo->exec('RELEASE SAVEPOINT stereo_writer_changed_red_flag');
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testNegativeRedStornoKeepsOriginalSidesAndUsesSignedAmounts(): void
    {
        $prepared = $this->writer->prepare($this->backup(1250.50, -50.25));
        self::assertTrue($prepared['ok'], json_encode($prepared['errors'], JSON_UNESCAPED_UNICODE));
        $this->writer->write($prepared, $this->supplierId, $this->userId);

        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, l.side, l.amount, l.signed_amount, l.is_red_storno
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.is_red_storno = 1
              ORDER BY l.line_no"
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame([
            ['account_code' => '221kb', 'side' => 'debit', 'amount' => '50.25', 'signed_amount' => '-50.25', 'is_red_storno' => 1],
            ['account_code' => '321', 'side' => 'credit', 'amount' => '50.25', 'signed_amount' => '-50.25', 'is_red_storno' => 1],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function backup(float $ordinaryAmount = 1250.50, float $correctionAmount = 50.25): StereoNxBackup
    {
        $path = sys_get_temp_dir() . '/stereo_accounting_' . bin2hex(random_bytes(8)) . '.zip';
        SyntheticNx1Archive::write(
            $path,
            SyntheticStereoNxAccountingTables::tables($ordinaryAmount, $correctionAmount),
            SyntheticStereoNxAccountingTables::identity(),
        );
        $this->files[] = $path;
        return StereoNxBackup::open($path, 0);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->value("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?", [$this->supplierId]);
    }

    private function mapCount(): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM stereo_nx_import_map WHERE supplier_id = ?', [$this->supplierId]);
    }

    /** @return array<string,int> */
    private function balances(): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code,
                    ROUND(SUM(CASE l.side WHEN 'debit' THEN l.signed_amount ELSE -l.signed_amount END) * 100) balance_cents
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ?
              GROUP BY a.id, a.account_code
              ORDER BY a.account_code"
        );
        $stmt->execute([$this->supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['account_code']] = (int) $row['balance_cents'];
        }
        return $out;
    }

    /** @return array<string,int> */
    private function snapshot(): array
    {
        return [
            'accounts' => $this->rowCount('chart_of_accounts'),
            'periods' => $this->rowCount('accounting_periods'),
            'entries' => $this->rowCount('journal_entries'),
            'lines' => $this->rowCount('journal_entry_lines'),
            'maps' => $this->mapCount(),
        ];
    }

    /** @param list<mixed> $params */
    private function value(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
