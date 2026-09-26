<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\PurchaseInvoiceExportService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Integrace: export PŘIJATÝCH faktur na **reálných DB datech** přes plný pipeline
 * (PurchaseInvoiceRepository::find → buildInvoiceShape → exportér). Chytá to, co
 * syntetické unit fixtury nechytí — zejména **mismatch klíčů rekapitulace DPH**
 * (repo dává `vat_rate`/`without_vat`, exportéry čtou `rate`/`base`), který dřív
 * vyráběl NULOVÝ summary a klasifikaci `UNX/nonSubsume`.
 *
 * Ověřuje:
 *  - Pohoda `<inv:invoice>` je validní vůči oficiálnímu invoice.xsd,
 *  - ISDOC je validní vůči isdoc-invoice-6.0.2.xsd,
 *  - u dokladu s kladným součtem NENÍ rekapitulace nulová (Pohoda homeCurrency i ISDOC TaxTotal).
 *
 * Soft skip bez cfg.php (CI runner) nebo bez XSD.
 */
final class PurchaseInvoiceExportXsdValidationTest extends TestCase
{
    private const SAMPLE_LIMIT = 20;

    private const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';
    private const POHODA_XSD = __DIR__ . '/../../../xsd/pohoda/invoice.xsd';

    private PurchaseInvoiceExportService $exporter;
    private XmlSchemaValidator $validator;
    private ?Connection $conn = null;

    protected function tearDown(): void
    {
        $this->conn?->close();
    }

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        if (!is_file(self::POHODA_XSD) || !is_file($rootDir . '/api/xsd/isdoc-invoice-6.0.2.xsd')) {
            $this->markTestSkipped('Chybí Pohoda/ISDOC XSD.');
        }

        $container = Bootstrap::buildContainer();
        $this->exporter = $container->get(PurchaseInvoiceExportService::class);
        $this->validator = $container->get(XmlSchemaValidator::class);
        $this->conn = $container->get(Connection::class);
    }

    public function testReceivedInvoicesSamplePassesXsdAndHasNonZeroRecap(): void
    {
        $rows = $this->sample();
        if ($rows === []) {
            $this->markTestSkipped('Žádné přijaté faktury v DB.');
        }

        $failures = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $sid = (int) $row['supplier_id'];
            $total = (float) $row['total_with_vat'];

            try {
                $pohoda = $this->exporter->toPohodaXml($id, $sid);
                $isdoc  = $this->exporter->toIsdocXml($id, $sid);
            } catch (\Throwable $e) {
                $failures[] = "#{$id}: export selhal — " . $e->getMessage();
                continue;
            }

            foreach ($this->pohodaXsdErrors($pohoda) as $err) {
                $failures[] = "#{$id} Pohoda XSD: {$err}";
            }

            $isdocResult = $this->validator->validate($isdoc, 'isdoc');
            if ($isdocResult['status'] === 'failed') {
                $failures[] = "#{$id} ISDOC XSD: " . implode(' | ', $isdocResult['errors']);
            }

            // Rekapitulace nesmí být nulová, pokud má doklad kladný součet.
            if ($total > 0.0) {
                if ($this->pohodaHomeCurrencySum($pohoda) <= 0.0) {
                    $failures[] = "#{$id}: Pohoda homeCurrency je nulová (total={$total})";
                }
                if ($this->isdocTaxInclusive($isdoc) <= 0.0) {
                    $failures[] = "#{$id}: ISDOC TaxInclusiveAmount je nulová (total={$total})";
                }
            }
        }

        $this->assertSame([], $failures,
            count($failures) . " problémů u " . count($rows) . " přijatých faktur:\n" . implode("\n", $failures));
    }

    /** @return list<array{id:int, supplier_id:int, total_with_vat:string}> */
    private function sample(): array
    {
        $stmt = $this->conn?->pdo()->query(
            "SELECT id, supplier_id, total_with_vat
               FROM purchase_invoices
              WHERE status IN ('received','booked','paid')
              ORDER BY id DESC
              LIMIT " . self::SAMPLE_LIMIT
        );
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    /** @return list<string> chyby XSD validace vnitřního <inv:invoice> proti invoice.xsd */
    private function pohodaXsdErrors(string $dataPackXml): array
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($dataPackXml)) {
            return ['není well-formed XML'];
        }
        $errors = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_INV, 'invoice') as $node) {
            $single = new \DOMDocument('1.0', 'UTF-8');
            $single->appendChild($single->importNode($node, true));
            $reload = new \DOMDocument();
            $reload->loadXML((string) $single->saveXML());

            $prev = libxml_use_internal_errors(true);
            libxml_clear_errors();
            $ok = $reload->schemaValidate(self::POHODA_XSD);
            $libErrors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            if (!$ok) {
                foreach ($libErrors as $e) {
                    $errors[] = sprintf('ř.%d %s', $e->line, trim($e->message));
                }
            }
        }
        return $errors;
    }

    private function pohodaHomeCurrencySum(string $xml): float
    {
        $xp = $this->xpath($xml, ['inv' => self::NS_INV, 'typ' => self::NS_TYP]);
        $sum = 0.0;
        foreach (['priceNone', 'priceLow', 'priceLowVAT', 'priceHigh', 'priceHighVAT'] as $el) {
            $sum += (float) ($xp->query("//inv:invoiceSummary/inv:homeCurrency/typ:{$el}")->item(0)?->textContent ?? '0');
        }
        return $sum;
    }

    private function isdocTaxInclusive(string $xml): float
    {
        $xp = $this->xpath($xml, ['i' => 'http://isdoc.cz/namespace/2013']);
        return (float) ($xp->query('//i:LegalMonetaryTotal/i:TaxInclusiveAmount')->item(0)?->textContent ?? '0');
    }

    /** @param array<string,string> $namespaces */
    private function xpath(string $xml, array $namespaces): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        foreach ($namespaces as $prefix => $uri) {
            $xp->registerNamespace($prefix, $uri);
        }
        return $xp;
    }
}
