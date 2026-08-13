<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\JmhzSpecPackageRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollJmhzCodebookRepositoryTest extends TestCase
{
    private Connection $db;
    private JmhzSpecPackageRepository $repository;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        if (!$db->hasTable('payroll_jmhz_spec_packages')) {
            $this->markTestSkipped('Migrace 1334 neproběhla.');
        }
        $this->db = $db;
        $this->repository = new JmhzSpecPackageRepository($db);
    }

    public function testOfficialPackageIsInstalledIdempotentlyAndCannotBeMutated(): void
    {
        $manifest = (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
        );
        $packageId = $this->repository->install($manifest);

        self::assertSame($packageId, $this->repository->install($manifest));
        self::assertEquals(
            $manifest,
            $this->repository->find(
                JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                $manifest['manifest_sha256'],
            ),
        );
        self::assertNull($this->repository->find(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            str_repeat('0', 64),
        ));

        $counts = $manifest['payload']['counts'];
        self::assertSame(
            (int) $counts['attributes'],
            $this->countRows('payroll_jmhz_dictionary_attributes', $packageId),
        );
        self::assertSame(
            (int) $counts['codebooks'],
            $this->countRows('payroll_jmhz_codebooks', $packageId),
        );
        self::assertSame(
            (int) $counts['codebook_entries'],
            $this->countRows('payroll_jmhz_codebook_entries', $packageId),
        );
        $codebooks = array_column($manifest['payload']['codebooks'], null, 'codebook_key');
        $storedCodebook = $this->db->pdo()->prepare(
            'SELECT source_kind, entry_count, content_hash
               FROM payroll_jmhz_codebooks
              WHERE package_id = ? AND codebook_key = ?',
        );
        $storedCodebook->execute([$packageId, 'kod_eldp']);
        self::assertSame(
            [
                'source_kind' => 'embedded',
                'entry_count' => (int) $codebooks['kod_eldp']['entry_count'],
                'content_hash' => $codebooks['kod_eldp']['content_hash'],
            ],
            array_map(
                static fn (mixed $value): string|int => is_numeric($value) ? (int) $value : (string) $value,
                $storedCodebook->fetch(\PDO::FETCH_ASSOC),
            ),
        );
        $exactCode = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_jmhz_codebook_entries entry
               JOIN payroll_jmhz_codebooks codebook ON codebook.id = entry.codebook_id
              WHERE entry.package_id = ? AND codebook.codebook_key = ? AND entry.item_code = ?',
        );
        $exactCode->execute([$packageId, 'kod_eldp', '1D+']);
        self::assertSame(1, (int) $exactCode->fetchColumn());
        $exactCode->execute([$packageId, 'kod_eldp', '1d+']);
        self::assertSame(0, (int) $exactCode->fetchColumn());
        $storedAttribute = $this->db->pdo()->prepare(
            'SELECT monthly_marker, codebook_key
               FROM payroll_jmhz_dictionary_attributes
              WHERE package_id = ? AND attribute_id = ?',
        );
        $storedAttribute->execute([$packageId, '10036']);
        self::assertSame(
            ['monthly_marker' => 'x010203', 'codebook_key' => null],
            $storedAttribute->fetch(\PDO::FETCH_ASSOC),
        );

        try {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_jmhz_dictionary_attributes
                    (package_id, attribute_id, name, row_hash)
                 VALUES (?, ?, ?, ?)',
            )->execute([$packageId, 'test.extra', 'Nepovolený atribut', str_repeat('0', 64)]);
            self::fail('Do kompletního balíku JMHZ se nesmí dát přidat další atribut.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('already contains all declared attributes', $e->getMessage());
        }

        try {
            $this->db->pdo()->prepare(
                'UPDATE payroll_jmhz_spec_packages SET xsd_version = ? WHERE id = ?',
            )->execute(['tampered', $packageId]);
            self::fail('Uložený balík JMHZ se nesmí dát změnit.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('immutable', $e->getMessage());
        }
    }

    private function countRows(string $table, int $packageId): int
    {
        $allowed = [
            'payroll_jmhz_dictionary_attributes',
            'payroll_jmhz_codebooks',
            'payroll_jmhz_codebook_entries',
        ];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException('Neplatná tabulka testu JMHZ.');
        }
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE package_id = ?");
        $stmt->execute([$packageId]);

        return (int) $stmt->fetchColumn();
    }
}
