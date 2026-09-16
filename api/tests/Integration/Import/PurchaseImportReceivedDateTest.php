<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\ImportedReceivedDatePolicy;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Datum přijetí u importovaného přijatého dokladu (migrace 1848).
 *
 * Zákaznický nález: „Pro import přijatých pdf dokladů jsme použili AI import, ovšem do
 * pole Datum přijetí se nám vkládá aktuální datum importu." Po migraci historie tak měla
 * celá firma ve sloupci „Datum přijetí" den importu.
 *
 * Tenhle test jede přes STRUKTUROVANÝ import (Pohoda XML), protože ten se dá spustit bez
 * sítě a bez AI brány — ale pravidlo je sdílené ({@see ImportedReceivedDatePolicy}), takže
 * hlídá tutéž logiku, jakou používá AI extrakce, ISDOC, iDoklad i Fakturoid.
 *
 * Data syntetická (fiktivní IČO, rok 2093), izolovaný supplier, uklizeno v tearDown.
 */
#[Group('integration')]
final class PurchaseImportReceivedDateTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TENANT_IC = '12345678';
    private const VENDOR = 'Testovací dodavatel s.r.o.';
    private const VENDOR_IC = '25596641';

    /**
     * Datum vystavení dokladu. MUSÍ být v MINULOSTI — jinak ho politika ořízne na dnešek
     * (doklad, jehož datum ještě nenastalo, jsme nemohli převzít) a test by měřil clamp
     * místo přebírání data z dokladu. Proto se tu nepoužívá obvyklý syntetický rok 2093.
     */
    private const ISSUE_DATE = '2025-07-14';

    /** Doklad s datem v budoucnu — pro ověření ořezu na dnešek. */
    private const FUTURE_ISSUE_DATE = '2093-07-14';

    private Connection $db;
    private InvoiceImportService $import;

    private int $supplierId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->import = $c->get(InvoiceImportService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }

        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::TENANT_IC, $this->supplierId]);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK", "Kč", "CZK", "CZK", 2, 1, 1)'
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'DELETE FROM purchase_invoice_items WHERE purchase_invoice_id IN
                (SELECT id FROM purchase_invoices WHERE supplier_id = ?)'
        )->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM purchase_invoice_counters WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        $this->db->close();
    }

    /**
     * JÁDRO NÁLEZU: datum přijetí se bere Z DOKLADU, ne ze dne importu.
     */
    public function testImportTakesReceivedAtFromTheDocument(): void
    {
        $id = $this->importOne('prijata-recv-1.xml', '93710001');

        self::assertSame(self::ISSUE_DATE, $this->receivedAt($id),
            'Datum přijetí musí odpovídat dokladu, ne dni importu.');
        self::assertNotSame(date('Y-m-d'), $this->receivedAt($id),
            'Kdyby to byl dnešek, nález zákazníka trvá.');
    }

    /**
     * Přepínač firmy vrátí dosavadní chování — datum přijetí = den importu. Uživatel ho
     * nastaví jednou a platí pro každý další import, nemusí ho klikat u každé dávky.
     */
    public function testCompanyCanSwitchBackToImportDay(): void
    {
        $this->setReceivedAtMode(ImportedReceivedDatePolicy::MODE_IMPORT_DAY);

        $id = $this->importOne('prijata-recv-2.xml', '93710002');

        self::assertSame(date('Y-m-d'), $this->receivedAt($id),
            'Po přepnutí na „den importu" musí být datum přijetí dnešní.');
    }

    /** Výchozí hodnota v DB je „z dokladu" — bez ní by přepínač nedával smysl. */
    public function testDefaultCompanyModeIsDocumentDate(): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT purchase_import_received_at FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);

        self::assertSame(ImportedReceivedDatePolicy::MODE_DOCUMENT, (string) $stmt->fetchColumn());
    }

    /**
     * POJISTKA § 73: ať se datum přijetí vezme odkudkoli, importovaný doklad si drží
     * `received_at_source = 'import'`. Jen díky tomu {@see \MyInvoice\Service\Report\VatLedgerService}
     * NEposune období nároku na odpočet — o zařazení dál rozhoduje DUZP / datum vystavení.
     * Kdyby sem spadlo 'manual', změna evidenčního údaje by tiše přepsala daňové období.
     */
    public function testImportedDocumentKeepsImportSourceSoVatPeriodIsUntouched(): void
    {
        $fromDocument = $this->importOne('prijata-recv-3.xml', '93710003');
        self::assertSame('import', $this->receivedAtSource($fromDocument));

        $this->setReceivedAtMode(ImportedReceivedDatePolicy::MODE_IMPORT_DAY);
        $importDay = $this->importOne('prijata-recv-4.xml', '93710004');
        self::assertSame('import', $this->receivedAtSource($importDay),
            'Ani volba „den importu" nesmí z dokladu udělat vědomé zadání účetní.');
    }

    /**
     * Budoucí datum na dokladu (překlep dodavatele, nebo syntetická data) se nedosazuje —
     * doklad, jehož datum vystavení ještě nenastalo, jsme nemohli fyzicky převzít.
     */
    public function testFutureIssueDateIsClampedToToday(): void
    {
        $id = $this->importOne('prijata-recv-5.xml', '93710005', self::FUTURE_ISSUE_DATE);

        self::assertSame(date('Y-m-d'), $this->receivedAt($id),
            'Datum přijetí nesmí být v budoucnosti.');
    }

    // ── pomůcky ──────────────────────────────────────────────────────────────

    private function setReceivedAtMode(string $mode): void
    {
        $this->db->pdo()
            ->prepare('UPDATE supplier SET purchase_import_received_at = ? WHERE id = ?')
            ->execute([$mode, $this->supplierId]);
    }

    private function importOne(string $name, string $symVar, ?string $issueDate = null): int
    {
        $out = $this->import->importBundle(
            [['name' => $name, 'content' => $this->pohodaReceived($symVar, $issueDate ?? self::ISSUE_DATE)]],
            $this->supplierId,
            $this->userId,
            'purchase',
            null,
            null,
            'received',
        );
        self::assertCount(1, $out['results']);
        $result = $out['results'][0];
        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));

        return (int) $result['purchase_invoice_id'];
    }

    private function receivedAt(int $purchaseInvoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT received_at FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$purchaseInvoiceId]);

        return substr((string) $stmt->fetchColumn(), 0, 10);
    }

    private function receivedAtSource(int $purchaseInvoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT received_at_source FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$purchaseInvoiceId]);

        return (string) $stmt->fetchColumn();
    }

    /** Přijatá faktura v uživatelském exportu z Pohody (`rsp:responsePack` → `lst:invoice`). */
    private function pohodaReceived(string $symVar, string $date): string
    {
        $tenantIc = self::TENANT_IC;
        $vendor   = self::VENDOR;
        $vendorIc = self::VENDOR_IC;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rsp:responsePack xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd"
                              xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd"
                              xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                              xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                              version="2.0" ico="{$tenantIc}">
              <rsp:responsePackItem version="2.0">
                <lst:listInvoice version="2.0">
                  <lst:invoice version="2.0">
                    <inv:invoiceHeader>
                      <inv:invoiceType>receivedInvoice</inv:invoiceType>
                      <inv:number><typ:numberRequested>{$symVar}</typ:numberRequested></inv:number>
                      <inv:symVar>{$symVar}</inv:symVar>
                      <inv:date>{$date}</inv:date>
                      <inv:dateTax>{$date}</inv:dateTax>
                      <inv:dateDue>{$date}</inv:dateDue>
                      <inv:text>Kancelářské potřeby</inv:text>
                      <inv:partnerIdentity>
                        <typ:address>
                          <typ:company>{$vendor}</typ:company>
                          <typ:ico>{$vendorIc}</typ:ico>
                          <typ:street>Dodavatelská 5</typ:street>
                          <typ:city>Brno</typ:city>
                          <typ:zip>60200</typ:zip>
                          <typ:country><typ:ids>CZ</typ:ids></typ:country>
                        </typ:address>
                      </inv:partnerIdentity>
                      <inv:myIdentity>
                        <typ:address>
                          <typ:company>Tenant a.s.</typ:company>
                          <typ:ico>{$tenantIc}</typ:ico>
                          <typ:street>Testovací 1</typ:street>
                          <typ:city>Praha</typ:city>
                          <typ:zip>11000</typ:zip>
                          <typ:country><typ:ids>CZ</typ:ids></typ:country>
                        </typ:address>
                      </inv:myIdentity>
                    </inv:invoiceHeader>
                    <inv:invoiceDetail>
                      <inv:invoiceItem>
                        <inv:text>Papír A4</inv:text>
                        <inv:quantity>1</inv:quantity>
                        <inv:unit>ks</inv:unit>
                        <inv:rateVAT>high</inv:rateVAT>
                        <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                      </inv:invoiceItem>
                    </inv:invoiceDetail>
                    <inv:invoiceSummary>
                      <inv:homeCurrency>
                        <typ:priceHigh>1000</typ:priceHigh>
                        <typ:priceHighVAT rate="21">210</typ:priceHighVAT>
                      </inv:homeCurrency>
                    </inv:invoiceSummary>
                  </lst:invoice>
                </lst:listInvoice>
              </rsp:responsePackItem>
            </rsp:responsePack>
            XML;
    }
}
