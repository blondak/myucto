<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\ChartOfAccountsTemplate;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační test konzistence seedu výkazů (Epic F2, T7, migrace 1011+1012):
 * každý rozvahový účet šablony osnovy má zásah v mapě rozvahy, každý výsledkový
 * v mapě VZZ, žádný mapovaný prefix nezačíná '7', parent_row_code odkazují na
 * existující řádky a (version, row_code) je unikátní. Read-only — bez transakce.
 * Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class StatementSeedTest extends TestCase
{
    private Connection $db;
    private int $bsVersionId = 0;
    private int $isVersionId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->query("SELECT id, statement_type FROM statement_versions WHERE version_code = 'vyhl500-2002/2024'");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['statement_type'] === 'balance_sheet') {
                $this->bsVersionId = (int) $row['id'];
            } elseif ($row['statement_type'] === 'income_statement') {
                $this->isVersionId = (int) $row['id'];
            }
        }
        if ($this->bsVersionId === 0 || $this->isVersionId === 0) {
            self::fail('Seed 1012 chybí — statement_versions neobsahuje vyhl500-2002/2024 pro oba typy výkazů.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testBalanceSheetMapCoversAllBalanceAccounts(): void
    {
        $prefixes = $this->mapPrefixes($this->bsVersionId);
        $missing = [];
        foreach (ChartOfAccountsTemplate::ACCOUNTS as $account) {
            if (!in_array($account['type'], ['asset', 'liability', 'equity'], true)) {
                continue;
            }
            if (!$this->covered($account['code'], $prefixes)) {
                $missing[] = $account['code'] . ' (' . $account['name'] . ')';
            }
        }
        self::assertSame([], $missing, 'Rozvahové účty šablony bez zásahu v mapě rozvahy: ' . implode(', ', $missing));
    }

    public function testIncomeStatementMapCoversAllProfitLossAccounts(): void
    {
        $prefixes = $this->mapPrefixes($this->isVersionId);
        $missing = [];
        foreach (ChartOfAccountsTemplate::ACCOUNTS as $account) {
            if (!in_array($account['type'], ['revenue', 'expense'], true)) {
                continue;
            }
            if (!$this->covered($account['code'], $prefixes)) {
                $missing[] = $account['code'] . ' (' . $account['name'] . ')';
            }
        }
        self::assertSame([], $missing, 'Výsledkové účty šablony bez zásahu v mapě VZZ: ' . implode(', ', $missing));
    }

    public function testNoMappedPrefixStartsWithClass7(): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT account_prefix FROM statement_account_map
              WHERE version_id IN (?, ?) AND account_prefix LIKE '7%'"
        );
        $stmt->execute([$this->bsVersionId, $this->isVersionId]);
        $offenders = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $offenders, 'Třída 7 (closing/offbalance) nesmí být mapovaná do výkazů: ' . implode(', ', $offenders));
    }

    public function testParentRowCodesReferenceExistingRows(): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.version_id, r.row_code, r.parent_row_code
               FROM statement_rows r
               LEFT JOIN statement_rows p
                 ON p.version_id = r.version_id AND p.row_code = r.parent_row_code
              WHERE r.version_id IN (?, ?)
                AND r.parent_row_code IS NOT NULL
                AND p.id IS NULL'
        );
        $stmt->execute([$this->bsVersionId, $this->isVersionId]);
        $orphans = array_map(
            static fn (array $r): string => $r['row_code'] . ' → ' . $r['parent_row_code'] . ' (verze ' . $r['version_id'] . ')',
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
        self::assertSame([], $orphans, 'parent_row_code bez existujícího řádku: ' . implode(', ', $orphans));
    }

    public function testRowCodesUniquePerVersion(): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT version_id, row_code, COUNT(*) AS cnt
               FROM statement_rows
              WHERE version_id IN (?, ?)
              GROUP BY version_id, row_code
             HAVING cnt > 1'
        );
        $stmt->execute([$this->bsVersionId, $this->isVersionId]);
        $dupes = array_map(
            static fn (array $r): string => $r['row_code'] . ' (verze ' . $r['version_id'] . ', ' . $r['cnt'] . '×)',
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
        self::assertSame([], $dupes, 'Duplicitní (version, row_code): ' . implode(', ', $dupes));
    }

    public function testMapRowCodesReferenceExistingRows(): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT m.version_id, m.row_code, m.account_prefix
               FROM statement_account_map m
               LEFT JOIN statement_rows r
                 ON r.version_id = m.version_id AND r.row_code = m.row_code
              WHERE m.version_id IN (?, ?)
                AND r.id IS NULL'
        );
        $stmt->execute([$this->bsVersionId, $this->isVersionId]);
        $orphans = array_map(
            static fn (array $r): string => $r['account_prefix'] . ' → ' . $r['row_code'] . ' (verze ' . $r['version_id'] . ')',
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
        self::assertSame([], $orphans, 'Mapovací řádky mířící na neexistující row_code: ' . implode(', ', $orphans));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function mapPrefixes(int $versionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT account_prefix FROM statement_account_map WHERE version_id = ?'
        );
        $stmt->execute([$versionId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param list<string> $prefixes
     */
    private function covered(string $code, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, (string) $prefix)) {
                return true;
            }
        }
        return false;
    }
}
