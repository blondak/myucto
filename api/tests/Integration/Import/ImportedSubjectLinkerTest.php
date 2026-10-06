<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Import\ImportedSubjectLinker;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Import subjektů z Fakturoidu/iDokladu zakládal novou kartu i pro dodavatele, který
 * už ve firmě byl (ruční zadání, AI vytěžení, převod). Přijatá faktura se pak navázala
 * na jiné `vendor_id` a kontrola duplicit `uq_pi_vendor_invoice` ji propustila podruhé.
 *
 * Data jsou syntetická, běží v transakci rollbacknuté v tearDown.
 */
#[Group('integration')]
final class ImportedSubjectLinkerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ImportedSubjectLinker $linker;
    private ClientRepository $clients;

    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db      = $c->get(Connection::class);
            $this->clients = $c->get(ClientRepository::class);
            $this->linker  = $c->get(ImportedSubjectLinker::class);
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

    public function testSubjectWithKnownIcLinksExistingCard(): void
    {
        $existingId = $this->createClient(['ic' => '1234567', 'is_customer' => true, 'is_vendor' => false]);

        $linked = $this->linker->linkExisting($this->supplierId, 'fakturoid_id', 555, '01234567', '', false, true);

        self::assertSame($existingId, $linked);
        $row = $this->row($existingId);
        self::assertSame(555, (int) $row['fakturoid_id']);
        self::assertSame(1, (int) $row['is_vendor'], 'Karta musí dostat roli dodavatele.');
        self::assertSame(1, (int) $row['is_customer'], 'Původní role zůstává.');
    }

    public function testSubjectWithoutIcLinksByNormalizedDic(): void
    {
        $existingId = $this->createClient(['dic' => 'CZ 87654321']);

        self::assertSame($existingId, $this->linker->linkExisting($this->supplierId, 'idoklad_id', 777, '', 'CZ87654321', true, false));
        self::assertSame(777, (int) $this->row($existingId)['idoklad_id']);
    }

    public function testCardAlreadyLinkedToAnotherSubjectIsNotReused(): void
    {
        $existingId = $this->createClient(['ic' => '11111111']);
        self::assertSame($existingId, $this->linker->linkExisting($this->supplierId, 'fakturoid_id', 1, '11111111', '', true, false));

        self::assertNull($this->linker->linkExisting($this->supplierId, 'fakturoid_id', 2, '11111111', '', true, false));
        self::assertSame(1, (int) $this->row($existingId)['fakturoid_id']);
    }

    public function testDifferentIcIsNotLinked(): void
    {
        $this->createClient(['ic' => '11111111']);

        self::assertNull($this->linker->linkExisting($this->supplierId, 'fakturoid_id', 3, '22222222', '', true, false));
    }

    /** @param array<string,mixed> $data */
    private function createClient(array $data): int
    {
        return $this->clients->create($data + [
            'company_name' => 'Testovací dodavatel s.r.o.',
            'street'       => 'Ulice 1',
            'city'         => 'Praha',
            'zip'          => '11000',
        ], $this->supplierId);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
