<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Integrace test: ISDOC export reálných faktur (supplier_id=1) MUSÍ projít XSD
 * validation oficiálního schématu (api/xsd/isdoc-invoice-6.0.2.xsd).
 *
 * Analogie k EpoXsdValidationTest (DPH/KH/SH). Na rozdíl od unit
 * IsdocExporterSchemaTest (syntetická pole) jde o **reálná DB data** přes plný
 * export pipeline (repo->find se snapshoty, live supplier/client/bank resolution,
 * ARES adresy, speciální znaky) — chytá to, co umělá data nemají.
 *
 * **Soft skip** pokud chybí cfg.php (CI runner) nebo ISDOC XSD.
 */
final class IsdocExportXsdValidationTest extends TestCase
{
    private const SUPPLIER_ID = 1;
    private const SAMPLE_LIMIT = 30;

    private IsdocExporter $exporter;
    private XmlSchemaValidator $validator;
    private ?Connection $conn = null;

    protected function tearDown(): void
    {
        $this->conn?->close();
    }

    protected function setUp(): void
    {
        // Oba checky PŘED Bootstrap::buildApp() — jinak fatal (viz EpoXsdValidationTest).
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        if (!is_file($rootDir . '/api/xsd/isdoc-invoice-6.0.2.xsd')) {
            $this->markTestSkipped('Chybí api/xsd/isdoc-invoice-6.0.2.xsd — spusť `bash cmd/download-xsd.sh isdoc`.');
        }

        $container = Bootstrap::buildContainer();
        $this->exporter = $container->get(IsdocExporter::class);
        $this->validator = $container->get(XmlSchemaValidator::class);
        $this->conn = $container->get(Connection::class);
    }

    public function testIssuedInvoicesSamplePassesIsdocXsd(): void
    {
        $rows = $this->invoiceSample();
        if ($rows === []) {
            $this->markTestSkipped('Žádné faktury pro supplier_id=' . self::SUPPLIER_ID . '.');
        }

        $failures = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $xml = $this->exporter->export([$id])['content'];
            $validation = $this->validator->validate($xml, 'isdoc');
            if ($validation['status'] !== 'passed') {
                $failures[] = sprintf(
                    "faktura #%d (%s, %s):\n    - %s",
                    $id,
                    $row['varsymbol'] ?? '?',
                    $row['currency'] ?? '?',
                    implode("\n    - ", $validation['errors'] ?: ['status=' . $validation['status']]),
                );
            }
        }

        $this->assertSame(
            [],
            $failures,
            count($failures) . ' z ' . count($rows) . " faktur neprošlo ISDOC XSD:\n" . implode("\n", $failures),
        );
    }

    /**
     * Cílený test cizoměnové faktury — exercise *Curr / přepočtu kurzem na reálných
     * datech. Skip, pokud supplier nemá žádnou non-CZK fakturu.
     */
    public function testForeignCurrencyInvoicePassesIsdocXsd(): void
    {
        $row = $this->conn?->pdo()->query(
            'SELECT i.id, i.varsymbol, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ' . self::SUPPLIER_ID . " AND cur.code <> 'CZK'
              ORDER BY i.id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC) ?: null;

        if ($row === null) {
            $this->markTestSkipped('Žádná cizoměnová faktura pro supplier_id=' . self::SUPPLIER_ID . '.');
        }

        $xml = $this->exporter->export([(int) $row['id']])['content'];

        // Cizoměnový doklad musí nést ForeignCurrencyCode i *Curr hodnoty.
        $this->assertStringContainsString('<ForeignCurrencyCode>', $xml);
        $this->assertStringContainsString('LineExtensionAmountCurr', $xml);

        $validation = $this->validator->validate($xml, 'isdoc');
        $this->assertSame(
            'passed',
            $validation['status'],
            sprintf("Faktura #%d (%s) neprošla ISDOC XSD:\n  - %s",
                $row['id'], $row['currency'], implode("\n  - ", $validation['errors'])),
        );
    }

    /**
     * @return list<array{id:int, varsymbol:?string, currency:?string}>
     */
    private function invoiceSample(): array
    {
        // Mix měn: nejdřív případné cizoměnové (vzácnější), pak doplnit nejnovějšími.
        $stmt = $this->conn?->pdo()->query(
            'SELECT i.id, i.varsymbol, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ' . self::SUPPLIER_ID . "
              ORDER BY (cur.code <> 'CZK') DESC, i.id DESC
              LIMIT " . self::SAMPLE_LIMIT
        );
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }
}
