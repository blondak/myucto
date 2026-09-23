<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\StereoNx\StereoNxAssets;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StereoNxAssetsWriteTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxAssets $assets;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php') && !getenv('MYINVOICE_DB_NAME')) {
            self::markTestSkipped('Integration database is not configured.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->assets = $container->get(StereoNxAssets::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $account = $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ([
            ['022', 'Samostatné movité věci', 'asset', 'debit'],
            ['042', 'Pořízení dlouhodobého majetku', 'asset', 'debit'],
            ['082', 'Oprávky', 'asset', 'credit'],
        ] as $row) {
            $account->execute([$this->supplierId, ...$row]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testWriteIsIdempotentAndCreatesNoJournalOrDepreciationRows(): void
    {
        $plan = StereoNxAssets::fromTables($this->tables(), $this->identity(), 1);

        $first = $this->assets->write($plan, $this->supplierId, $this->userId);
        self::assertSame(1, $first['assets_created']);
        self::assertSame(1, $first['small_assets_created']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM assets WHERE supplier_id = ?'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM small_assets WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM depreciation_entries WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?'));

        $again = $this->assets->write($plan, $this->supplierId, $this->userId);
        self::assertSame(0, $again['assets_created']);
        self::assertSame(1, $again['assets_existing']);
        self::assertSame(0, $again['small_assets_created']);
        self::assertSame(1, $again['small_assets_existing']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM assets WHERE supplier_id = ?'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM small_assets WHERE supplier_id = ?'));
    }

    public function testChangedSourceCardIsRejectedInsteadOfDuplicated(): void
    {
        $plan = StereoNxAssets::fromTables($this->tables(), $this->identity(), 1);
        $this->assets->write($plan, $this->supplierId, $this->userId);
        $changed = $plan;
        $changed['records']['assets'][0]['source_hash'] = str_repeat('a', 64);

        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('změnila');
        $this->assets->write($changed, $this->supplierId, $this->userId);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function tables(): array
    {
        $asset = [
            'InvCislo' => 'M-SYN-1', 'Nazev' => 'Syntetický stroj', 'Typ' => 'H',
            'CenaPorizovaci' => 100_000.0, 'DatumPorizeni' => '2020-01-15', 'DatumZarazeni' => '2020-01-15',
            'DatumVyrazeni' => '', 'UcetMDZarazeni' => '022', 'UcetDalZarazeni' => '042',
            'UcetDalOdpisu' => '082', 'ZpusobDanOdepisovani' => 'Z', 'OdpisovaSkupina' => '2',
            'ZvyseniSazProc' => 0, 'DanoveOdepsano' => 20_000.0, 'UcetneOdepsano' => 20_000.0,
        ];
        $depreciation = [
            ['InvCislo' => 'M-SYN-1', 'Datum' => '2020-12-31', 'Castka' => 20_000.0, 'DatumUplatneni' => '2021-01-04'],
            ['InvCislo' => 'M-SYN-1', 'Datum' => '2021-12-31', 'Castka' => 30_000.0, 'DatumUplatneni' => ''],
        ];
        return [
            'JMajetek' => [$asset],
            'JDrobMaj' => [[
                'InvCislo' => 'D-SYN-1', 'Nazev' => 'Syntetická židle', 'Typ' => 'H',
                'JednCena' => 4_000.0, 'Mnozstvi' => 2.0, 'DatumPorizeni' => '2023-05-02',
                'DatumZarazeni' => '2023-05-03', 'DatumVyrazeni' => '',
            ]],
            'JDanOdpisy' => $depreciation,
            'JUcOdpisy' => $depreciation,
            'JTechZhod' => [],
        ];
    }

    /** @return array{ico:string,dic:string,name:string,vat_payer:bool} */
    private function identity(): array
    {
        return ['ico' => '12345679', 'dic' => 'CZ12345679', 'name' => 'Syntetická firma s.r.o.', 'vat_payer' => true];
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
