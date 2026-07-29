<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * C6' (audit 2026-07, vat) — období odpočtu tuzemských přijatých plnění dle § 73 odst. 1
 * písm. a) ZDPH: pokud účetní VĚDOMĚ zadala datum přijetí ve formuláři
 * (received_at_source='manual'), odpočet spadá do pozdějšího z (received_at, DUZP, vystavení).
 * Importní otisk data přijetí (received_at_source='import') se IGNORUJE — jinak by zpětně
 * importovaný doklad naházel odpočet do měsíce importu.
 *
 * Testuje přímo VatLedgerService::rows() (zdroj pravdy pro Knihu DPH, DPHDP3 i KH):
 * doklad se v daném období objeví právě tehdy, když do něj spadá jeho výraz období odpočtu.
 *
 * Izolovaný rok 2099 pod existujícím supplierem, úklid v tearDown. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class VatLedgerDeductionPeriodTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private VatLedgerService $ledger;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $deId = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $purchaseIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db     = $container->get(Connection::class);
            $this->ledger = $container->get(VatLedgerService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = $this->countryId('CZ');
        $this->deId = $this->countryId('DE');

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0
            || $this->userId === 0 || $this->czId === 0 || $this->deId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->purchaseIds !== []) {
            $ph = implode(',', array_fill(0, count($this->purchaseIds), '?'));
            $pdo->prepare("DELETE FROM purchase_invoice_items WHERE purchase_invoice_id IN ($ph)")->execute($this->purchaseIds);
            $pdo->prepare("DELETE FROM purchase_invoices WHERE id IN ($ph)")->execute($this->purchaseIds);
        }
        if ($this->vendorIds !== []) {
            $ph = implode(',', array_fill(0, count($this->vendorIds), '?'));
            $pdo->prepare("DELETE FROM clients WHERE id IN ($ph)")->execute($this->vendorIds);
        }
    }

    /**
     * (a) Ručně zadaná PF (manual) s received_at POZDĚJI než GREATEST(DUZP, vystavení):
     *     odpočet spadá do pozdějšího období = měsíc received_at (§ 73/1/a).
     * (b) Importovaná PF (import) se STEJNÝMI daty: received_at se ignoruje, odpočet
     *     zůstává v GREATEST(DUZP, vystavení) jako dosud (žádná regrese).
     */
    public function testManualReceivedAtMovesDeductionButImportDoesNot(): void
    {
        $vend = $this->vendor('CZ dodavatel', $this->czId, 'CZ10000010');

        // DUZP i vystavení v BŘEZNU → GREATEST = 2099-03-25.
        $tax   = sprintf('%04d-03-20', self::YEAR);
        $issue = sprintf('%04d-03-25', self::YEAR);
        // Doklad fyzicky přijat/zadán až v KVĚTNU.
        $recv  = sprintf('%04d-05-10', self::YEAR);

        $manualId = $this->purchase('C6-A-manual', $vend, $tax, $issue, $recv, 'manual', false);
        $importId = $this->purchase('C6-B-import', $vend, $tax, $issue, $recv, 'import', false);

        $march = $this->purchaseIdsIn(3);
        $may   = $this->purchaseIdsIn(5);

        // Manual: odpočet dle received_at → KVĚTEN, ne březen.
        $this->assertNotContains($manualId, $march, 'manual received_at (květen) NESMÍ zůstat v březnu');
        $this->assertContains($manualId, $may, 'manual received_at → odpočet v květnu (§ 73/1/a)');

        // Import: received_at ignorováno → GREATEST(DUZP, vystavení) = BŘEZEN.
        $this->assertContains($importId, $march, 'import received_at se ignoruje → odpočet v březnu (beze změny)');
        $this->assertNotContains($importId, $may, 'import received_at NESMÍ posunout odpočet do května');
    }

    /**
     * (c) Manuální PF, jejíž received_at NEPOSOUVÁ období (dřívější než GREATEST(DUZP,
     *     vystavení)): manuální větev vrací GREATEST(received_at, DUZP, vystavení), takže
     *     brzké received_at nesmí odpočet rozbít — zůstává v měsíci vystavení (beze změny).
     */
    public function testManualEarlyReceivedAtDoesNotChangePeriod(): void
    {
        $vend = $this->vendor('CZ dodavatel brzy', $this->czId, 'CZ10000028');

        $tax   = sprintf('%04d-03-20', self::YEAR);
        $issue = sprintf('%04d-03-25', self::YEAR);
        // Doklad "přijat" dřív než DUZP i vystavení → nesmí posunout období.
        $recv  = sprintf('%04d-03-10', self::YEAR);

        $id = $this->purchase('C6-C-early', $vend, $tax, $issue, $recv, 'manual', false);

        $march = $this->purchaseIdsIn(3);
        $february = $this->purchaseIdsIn(2);

        $this->assertContains($id, $march, 'brzké received_at → GREATEST(DUZP, vystavení) = březen');
        $this->assertNotContains($id, $february, 'brzké received_at nesmí odpočet stáhnout do února');
    }

    /**
     * (d) Zahraniční reverse charge (issue #117) — období dle DUZP bez ohledu na received_at
     *     i vystavení; manual received_at se u něj IGNORUJE (§ 25 / § 73/1/b).
     */
    public function testForeignReverseChargeIgnoresReceivedAt(): void
    {
        $vend = $this->vendor('EU dodavatel RC', $this->deId, 'DE100000010');

        // DUZP březen, ale vystaveno až v květnu a "přijato" (manual) až v červenci.
        $tax   = sprintf('%04d-03-20', self::YEAR);
        $issue = sprintf('%04d-05-25', self::YEAR);
        $recv  = sprintf('%04d-07-01', self::YEAR);

        $id = $this->purchase('C6-D-rc', $vend, $tax, $issue, $recv, 'manual', true);

        $march = $this->purchaseIdsIn(3);
        $may   = $this->purchaseIdsIn(5);
        $july  = $this->purchaseIdsIn(7);

        $this->assertContains($id, $march, 'zahraniční RC dle DUZP → březen (beze změny, received_at ignorováno)');
        $this->assertNotContains($id, $may, 'zahraniční RC nesmí spadnout do měsíce vystavení');
        $this->assertNotContains($id, $july, 'zahraniční RC nesmí spadnout do měsíce manual received_at');
    }

    /**
     * (e) Zahraniční RC rozpoznaný JEN z položkového klasifikačního kódu (issue #117) —
     *     import přiřadí kód 24e na položku, ale hlavičkový flag `reverse_charge` i
     *     `pi.vat_classification_code` zůstanou prázdné (stav, který remeduje
     *     backfill-foreign-reverse-charge.php). Období musí přesto spadnout na DUZP přes
     *     korelovaný EXISTS ve výrazu období — bez item-level detekce by pozdě vystavená
     *     §24 služba (DUZP březen, vystaveno květen) utekla přes GREATEST do měsíce vystavení.
     */
    public function testForeignReverseChargeDetectedFromItemLevelCode(): void
    {
        $vend = $this->vendor('EU dodavatel RC položka', $this->deId, 'DE100000036');

        // DUZP březen, vystaveno až v květnu; hlavička BEZ flagu i kódu, RC jen na položce.
        $tax   = sprintf('%04d-03-20', self::YEAR);
        $issue = sprintf('%04d-05-25', self::YEAR);

        $id = $this->purchaseItemLevelRc('C6-E-item-rc', $vend, $tax, $issue, '24e');

        $march = $this->purchaseIdsIn(3);
        $may   = $this->purchaseIdsIn(5);

        $this->assertContains($id, $march, 'RC z položkového kódu → období dle DUZP (březen)');
        $this->assertNotContains($id, $may, 'RC z položkového kódu nesmí utéct do měsíce vystavení');
    }

    /** invoice_id přijatých plnění, které VatLedgerService zařadil do daného měsíce roku YEAR. */
    private function purchaseIdsIn(int $month): array
    {
        $start = sprintf('%04d-%02d-01', self::YEAR, $month);
        $end   = date('Y-m-t', strtotime($start));
        $ids = [];
        foreach ($this->ledger->rows($this->supplierId, $start, $end) as $r) {
            if (($r['source'] ?? '') === 'purchase') {
                $ids[] = (int) $r['invoice_id'];
            }
        }
        return $ids;
    }

    private function vendor(string $name, int $countryId, ?string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    private function purchase(
        string $number,
        int $vendorId,
        string $taxDate,
        string $issueDate,
        ?string $receivedAt,
        string $receivedAtSource,
        bool $rc,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, received_at_source, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, 1, ?, "{}", 1000, 210, 1210, "received", ?, "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $issueDate, $taxDate, $issueDate,
            $receivedAt, $receivedAtSource, $this->currencyId, $rc ? 1 : 0,
            $rc ? '5' : '40', $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;

        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, "Test", 1, "ks", 1000, ?, 21, 1000, 210, 1210, 0, ?)'
        )->execute([$id, $this->vatRateId, $rc ? '5' : '40']);

        return $id;
    }

    /**
     * Přijatá PF zahraničního dodavatele, kde je RC klasifikační kód JEN na položce
     * (hlavička bez `reverse_charge` flagu i bez `vat_classification_code`) — reprodukce
     * importního stavu. total_vat=0 (samovyměření), vat_deduction='full'.
     */
    private function purchaseItemLevelRc(
        string $number,
        int $vendorId,
        string $taxDate,
        string $issueDate,
        string $itemCode,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, received_at_source, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, "import", ?, 1, 0,
                     "{}", 1000, 0, 1000, "received", NULL, "full", ?)'
        );
        // received_at = vystavení, ale received_at_source="import" ho ve výrazu období
        // ignoruje (manuální větev vyžaduje 'manual') → rozhoduje jen RC-DUZP přes EXISTS.
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $issueDate, $taxDate, $issueDate,
            $issueDate, $this->currencyId, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;

        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, "Test RC", 1, "ks", 1000, ?, 21, 1000, 0, 1000, 0, ?)'
        )->execute([$id, $this->vatRateId, $itemCode]);

        return $id;
    }

    private function countryId(string $iso2): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$iso2]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
