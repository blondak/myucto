<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FR 2 (vendor bugreport 2026-08-06) — integrační test pro
 * ClientRepository::findDuplicateCandidates()/findDuplicateGroups() proti reálné
 * DB (ověřuje SQL v activeIdentityRows() a řazení dat do VendorDuplicateFinder,
 * ne jen čistou logiku — tu pokrývá VendorDuplicateFinderTest).
 *
 * Data jsou syntetická, běží v transakci rollbacknuté v tearDown.
 */
#[Group('integration')]
final class ClientDuplicatesRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ClientRepository $clients;

    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db      = $c->get(Connection::class);
            $this->clients = $c->get(ClientRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí základní data (supplier).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK", "Kč", "CZK", "CZK", 2, 1, 1)'
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testFindDuplicateGroups_detectsLeadingZeroIcAndSpacedDic(): void
    {
        $a = $this->clients->create(['company_name' => 'Firma A', 'ic' => '1234567', 'street' => 'X', 'city' => 'Y', 'zip' => '11000'], $this->supplierId);
        $b = $this->clients->create(['company_name' => 'Firma A s.r.o.', 'ic' => '01234567', 'street' => 'X', 'city' => 'Y', 'zip' => '11000'], $this->supplierId);
        // Nezávislá dvojice se stejným problémem u DIČ.
        $this->clients->create(['company_name' => 'Firma B', 'dic' => 'CZ 87654321', 'street' => 'X', 'city' => 'Y', 'zip' => '11000'], $this->supplierId);
        $this->clients->create(['company_name' => 'Firma B s.r.o.', 'dic' => 'CZ87654321', 'street' => 'X', 'city' => 'Y', 'zip' => '11000'], $this->supplierId);
        // Bez duplicity — nesmí se objevit v žádné skupině.
        $this->clients->create(['company_name' => 'Firma C', 'ic' => '99999999', 'street' => 'X', 'city' => 'Y', 'zip' => '11000'], $this->supplierId);

        $groups = $this->clients->findDuplicateGroups($this->supplierId);

        self::assertCount(2, $groups);
        $icGroup = self::firstByType($groups, 'ic');
        self::assertNotNull($icGroup);
        self::assertSame('01234567', $icGroup['key_value']);
        self::assertEqualsCanonicalizing([$a, $b], array_column($icGroup['clients'], 'id'));

        $dicGroup = self::firstByType($groups, 'dic');
        self::assertNotNull($dicGroup);
        self::assertSame('CZ87654321', $dicGroup['key_value']);
    }

    public function testFindDuplicateGroups_archivedClientExcluded(): void
    {
        $a = $this->clients->create(['company_name' => 'Firma D', 'ic' => '1234567', 'street' => 'X', 'city' => 'Y', 'zip' => '11000'], $this->supplierId);
        $this->clients->create(['company_name' => 'Firma D s.r.o.', 'ic' => '01234567', 'street' => 'X', 'city' => 'Y', 'zip' => '11000'], $this->supplierId);

        $this->db->pdo()->prepare('UPDATE clients SET archived_at = NOW() WHERE id = ?')->execute([$a]);

        // Archivovaná karta je vyřazena → zbyde jen jedna aktivní, žádná duplicita.
        self::assertSame([], $this->clients->findDuplicateGroups($this->supplierId));
    }

    public function testFindDuplicateCandidates_returnsMatchAndExcludesGivenId(): void
    {
        $existingId = $this->clients->create(['company_name' => 'Firma E', 'ic' => '1234567', 'street' => 'X', 'city' => 'Y', 'zip' => '11000'], $this->supplierId);

        $matches = $this->clients->findDuplicateCandidates($this->supplierId, '01234567', null);
        self::assertCount(1, $matches);
        self::assertSame($existingId, $matches[0]['id']);
        self::assertSame('ic', $matches[0]['match_field']);

        // Editace karty samotné (excludeClientId=$existingId) — nesmí se ohlásit sama proti sobě.
        self::assertSame(
            [],
            $this->clients->findDuplicateCandidates($this->supplierId, '01234567', null, $existingId),
        );
    }

    public function testFindDuplicateCandidates_noIcOrDicReturnsEmptyWithoutQuery(): void
    {
        self::assertSame([], $this->clients->findDuplicateCandidates($this->supplierId, null, null));
        self::assertSame([], $this->clients->findDuplicateCandidates($this->supplierId, '', ''));
    }

    /** @param list<array{key_type:string, key_value:string, clients:list<array<string,mixed>>}> $groups */
    private static function firstByType(array $groups, string $type): ?array
    {
        foreach ($groups as $g) {
            if ($g['key_type'] === $type) {
                return $g;
            }
        }
        return null;
    }
}
