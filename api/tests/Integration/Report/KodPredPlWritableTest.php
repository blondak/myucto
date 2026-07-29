<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\VatClassificationRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kód předmětu plnění (§ 92b–92f) musí jít NASTAVIT, ne jen číst.
 *
 * Matice DPH (F3): migrace 0127 nasadila `kod_pred_pl = '4'` (stavební práce) plošně
 * pro VŠECHNY tuzemské režimy přenesené daňové povinnosti — a sloupec byl v repozitáři
 * jen ČTEN. `VatClassificationRepository::insert()` ani `update()` ho neobsahovaly,
 * takže uživatel neměl jak hodnotu změnit ani přes API.
 *
 * Dodavatel odpadu (§ 92c), zlata (§ 92b) nebo zboží z přílohy 6 (§ 92f) tak posílal
 * do kontrolního hlášení A.1/B.1 systematicky kód stavebních prací a neexistovala cesta,
 * jak to opravit. Tichá chyba v podání, kterou nic nehlásilo.
 */
#[Group('integration')]
final class KodPredPlWritableTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private VatClassificationRepository $repo;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->repo = $c->get(VatClassificationRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW COLUMNS FROM vat_classifications LIKE 'kod_pred_pl'")->fetch() === false) {
            $this->markTestSkipped('Migrace 0127 (kod_pred_pl) neproběhla.');
        }
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
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

    /** Založení klasifikace s vlastním kódem — dodavatel odpadu není stavební firma. */
    public function testInsertPersistsKodPredPl(): void
    {
        $id = $this->repo->create($this->supplierId, [
            'code'              => 'RC-ODP',
            'label'             => 'Přenesená DP — odpad (§ 92c)',
            'direction'         => 'sale',
            'is_reverse_charge' => 1,
            'kod_pred_pl'       => '5',
        ]);

        $row = $this->repo->find($id, $this->supplierId);
        self::assertNotNull($row);
        self::assertSame('5', (string) $row['kod_pred_pl'], 'Kód se musí uložit, ne spadnout na seedovanou čtyřku.');
    }

    /** Změna kódu na existující klasifikaci — bez toho nešlo seed opravit. */
    public function testUpdateChangesKodPredPl(): void
    {
        $id = $this->repo->create($this->supplierId, [
            'code'              => 'RC-UPD',
            'label'             => 'Přenesená DP',
            'direction'         => 'sale',
            'is_reverse_charge' => 1,
            'kod_pred_pl'       => '4',
        ]);

        $this->repo->update($id, $this->supplierId, [
            'label'             => 'Přenesená DP — zlato (§ 92b)',
            'direction'         => 'sale',
            'is_reverse_charge' => 1,
            'kod_pred_pl'       => '1',
        ]);

        $row = $this->repo->find($id, $this->supplierId);
        self::assertSame('1', (string) $row['kod_pred_pl']);
    }

    /**
     * Normalizace na tvar, který XSD připouští (`maxLength=3`). Hodnotový výčet je
     * v externím číselníku MFČR, takže se kontroluje jen TVAR — vlastní seznam kódů
     * by se s číselníkem rozešel a odmítal by legitimní hodnoty.
     *
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function kodPredPlValues(): iterable
    {
        yield 'jednociferný'   => ['1', '1'];
        yield 'dvouciferný'    => ['13', '13'];
        yield 's mezerami'     => [' 12 ', '12'];
        yield 'prázdný'        => ['', null];
        yield 'null'           => [null, null];
        yield 'delší než XSD'  => ['12345', '123'];
        yield 'nečíselný'      => ['abc', null];
    }

    /**
     * @param mixed $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('kodPredPlValues')]
    public function testKodPredPlIsNormalizedToXsdShape(mixed $input, ?string $expected): void
    {
        $id = $this->repo->create($this->supplierId, [
            'code'              => 'RC-N' . substr(md5((string) json_encode($input)), 0, 3),
            'label'             => 'Norm test',
            'direction'         => 'sale',
            'is_reverse_charge' => 1,
            'kod_pred_pl'       => $input,
        ]);

        $row = $this->repo->find($id, $this->supplierId);
        $actual = $row['kod_pred_pl'] === null ? null : (string) $row['kod_pred_pl'];

        self::assertSame($expected, $actual);
    }
}
