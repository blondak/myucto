<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\PartnerIdentityMatcher;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Párování partnera převodu na existující kontakt: izolovaná firma v transakci s rollbackem.
 */
#[Group('integration')]
final class SharedPartnerIdentityMatcherTest extends TestCase
{
    private Connection $db;
    private PartnerIdentityMatcher $matcher;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->matcher = $container->get(PartnerIdentityMatcher::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, default_currency_id, default_vat_rate_id)
             VALUES ("Syntetická firma partneři", "Účetní 1", "Brno", "60200", ?, "partneri@example.invalid", "00000019", ?, ?)'
        )->execute([$czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testIndexNormalizesIcoAndKeepsOldestActiveContact(): void
    {
        $first = $this->client('Syntetický partner A', '1234567');
        $this->client('Syntetický partner A duplicitní', '01234567');
        $archived = $this->client('Archivovaný', '45274649', archived: true);
        $this->client('Bez IČO', null);
        $this->client('Nečíselné IČO', 'ABC');

        $index = $this->matcher->clientsByIco($this->supplierId);

        self::assertSame($first, $index['01234567'] ?? null);
        self::assertArrayNotHasKey('45274649', $index, "archivovaný kontakt {$archived} se nepáruje");
        self::assertArrayNotHasKey('', $index);
        self::assertCount(1, $index);
    }

    public function testNameMatchIsExactAndSkipsArchived(): void
    {
        $this->client('Syntetický partner B', null, archived: true);
        $active = $this->client('Syntetický partner B', null);

        self::assertSame($active, $this->matcher->clientByName($this->supplierId, 'Syntetický partner B'));
        self::assertNull($this->matcher->clientByName($this->supplierId, 'Syntetický partner'));
    }

    public function testFillMissingOnlyFillsBlanks(): void
    {
        $id = $this->client('Syntetický partner C', '01234567', street: '-', phone: '+420 000 000 001');
        $this->matcher->fillMissing($this->supplierId, $id, [
            'dic' => 'cz 01234567', 'street' => 'Nová 1', 'city' => 'Praha', 'zip' => '110 00',
            'email' => 'c@example.invalid', 'phone' => '+420 000 000 999',
        ]);

        $row = $this->db->pdo()->query("SELECT dic, is_vat_payer, street, city, zip, main_email, phone FROM clients WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $row['is_vat_payer'] = (int) $row['is_vat_payer'];
        self::assertSame(['dic' => 'CZ01234567', 'is_vat_payer' => 1, 'street' => 'Nová 1', 'city' => 'Brno', 'zip' => '11000',
            'main_email' => 'c@example.invalid', 'phone' => '+420 000 000 001'], $row);
    }

    private function client(string $name, ?string $ico, bool $archived = false, string $street = 'Stará 2', string $phone = ''): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, ic, street, city, zip, country_id, currency_default_id, phone, archived_at)
             SELECT ?, ?, ?, ?, "Brno", "-", country_id, default_currency_id, ?, ? FROM supplier WHERE id = ?'
        )->execute([$this->supplierId, $name, $ico, $street, $phone !== '' ? $phone : null, $archived ? '2020-01-01 00:00:00' : null, $this->supplierId]);
        return (int) $pdo->lastInsertId();
    }
}
