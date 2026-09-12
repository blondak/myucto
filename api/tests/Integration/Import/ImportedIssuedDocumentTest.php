<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\AiIssuedInvoiceExtractor;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Převzetí vydaného dokladu z ISDOC mimo kanál Shoptetu: obecný import vydaných faktur
 * a AI import vydaných faktur musí s daňovým dokladem k přijaté platbě a s konečnou
 * fakturou s odpočtem záloh zacházet stejně jako import Shoptetu
 * ({@see \MyInvoice\Service\Import\ImportedIssuedDocumentPolicy}). Syntetická data
 * (api/tests/Fixtures/Shoptet/).
 */
#[Group('integration')]
final class ImportedIssuedDocumentTest extends StockTestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/Shoptet/';
    private const SUPPLIER_IC = '12345679';

    private int $sid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sid = $this->createSupplier();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET ic = ?, dic = ? WHERE id = ?')->execute([self::SUPPLIER_IC, 'CZ' . self::SUPPLIER_IC, $this->sid]);
        $pdo->prepare(
            "INSERT IGNORE INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
             VALUES (?, '1900-01-01', 1, 0)"
        )->execute([$this->sid]);
        $clientId = $this->client($this->sid, 'Testovací odběratel s.r.o.');
        $pdo->prepare('UPDATE clients SET ic = ?, dic = ? WHERE id = ?')->execute(['25596641', 'CZ25596641', $clientId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            foreach ($this->supplierIds as $supplierId) {
                $this->db->pdo()->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$supplierId]);
                // Obecný import zakládá z čísla objednávky zakázku; doklady na ni ukazují.
                $this->db->pdo()->prepare('UPDATE invoices SET project_id = NULL WHERE supplier_id = ?')->execute([$supplierId]);
                $this->db->pdo()->prepare('DELETE FROM projects WHERE client_id IN (SELECT id FROM clients WHERE supplier_id = ?)')->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function import(string ...$names): array
    {
        return $this->container->get(InvoiceImportService::class)->importBundle(
            array_map(static fn (string $n): array => ['name' => $n, 'content' => (string) file_get_contents(self::FIXTURES . $n)], $names),
            $this->sid,
            $this->userId,
            'issued',
        );
    }

    /** @return array<string,mixed> */
    private function invoiceByVs(string $vs): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->sid, $vs]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Doklad $vs nevznikl.");

        return $row;
    }

    private function scalar(string $sql, array $args): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($args);

        return $stmt->fetchColumn();
    }

    /**
     * FAIL-BEFORE: M3 — importovaný DDPP dostal stav `sent` podle splatnosti a nulovou
     * uhrazenou zálohu, takže visel v pohledávkách v plné výši, ač je ze zákona zaplacený.
     */
    public function testImportedTaxDocumentIsPaidAndNotAReceivable(): void
    {
        $out = $this->import('isdoc-ddpp.isdoc');
        self::assertSame(1, $out['summary']['created'], implode(' | ', array_column($out['results'], 'reason')));

        $ddpp = $this->invoiceByVs('2026500001');
        self::assertSame('tax_document', $ddpp['invoice_type']);
        self::assertSame('paid', $ddpp['status']);
        self::assertSame('2026-09-03', substr((string) $ddpp['paid_at'], 0, 10), 'Zaplaceno ke dni přijetí úplaty (DUZP).');
        self::assertSame('0.00', number_format((float) $ddpp['amount_to_pay'], 2, '.', ''));
        self::assertSame('484.00', number_format((float) $ddpp['total_with_vat'], 2, '.', ''));

        // Tentýž predikát jako otevřené pohledávky na přehledu (SummaryAction::outstandingReceivableSql).
        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM invoices i
              WHERE i.supplier_id = ? AND i.status IN ('issued','sent','reminded')
                AND i.invoice_type IN ('invoice','credit_note','tax_document')
                AND (i.invoice_type NOT IN ('invoice','proforma','tax_document') OR i.amount_to_pay - i.paid_total > 0)",
            [$this->sid],
        ), 'DDPP nesmí být otevřená pohledávka.');
    }

    /**
     * FAIL-BEFORE: H2 (symetrie) — obecný import vydaných ISDOC převzal konečnou fakturu
     * s odpočtem zdaněné zálohy jako vystavenou s plnou DPH, stejně jako kanál Shoptetu.
     */
    public function testGenericIssuedImportKeepsDepositDeductingInvoiceAsDraft(): void
    {
        $out = $this->import('isdoc-ddpp.isdoc', 'isdoc-faktura-zalohy.isdoc');
        self::assertSame(2, $out['summary']['created'], implode(' | ', array_column($out['results'], 'reason')));

        $final = $this->invoiceByVs('2026100002');
        self::assertSame('draft', $final['status']);
        self::assertNull($final['paid_at']);

        $ledger = $this->container->get(VatLedgerService::class)->rows($this->sid, '2026-09-01', '2026-09-30');
        $ids = array_map(static fn (array $r): int => (int) ($r['invoice_id'] ?? 0), array_filter($ledger, static fn (array $r): bool => ($r['source'] ?? '') === 'sale'));
        self::assertNotContains((int) $final['id'], $ids);
    }

    /**
     * FAIL-BEFORE: L5 — dávka samých DDPP neposunula čítač řady faktur, ze které se
     * daňové doklady číslují, a další vystavená faktura by dostala obsazené číslo.
     */
    public function testTaxDocumentOnlyBatchLiftsInvoiceSeries(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET invoice_number_format = '{YYYY}5{CCCCC}', invoice_number_period = 'year' WHERE id = ?")
            ->execute([$this->sid]);

        $this->import('isdoc-ddpp.isdoc');

        self::assertSame(1, (int) $this->scalar(
            "SELECT MAX(last_number) FROM invoice_counters WHERE supplier_id = ? AND invoice_type = 'invoice'",
            [$this->sid],
        ));
    }

    /**
     * FAIL-BEFORE: H1 — AI import vydaných faktur mapoval neznámé druhy na `invoice`,
     * takže DDPP (ISDOC DocumentType 5 → `tax_document`) se stal řádnou fakturou
     * a po vystavení by se tržba i DPH zaevidovaly podruhé.
     */
    public function testAiIssuedImportKeepsTaxDocumentTypeAndFlagsDeposits(): void
    {
        $extractor = $this->container->get(AiIssuedInvoiceExtractor::class);
        $createFromIsdoc = new \ReflectionMethod($extractor, 'createFromIsdoc');
        $parser = new IsdocParser();

        $ddpp = $createFromIsdoc->invoke($extractor, $parser->parse((string) file_get_contents(self::FIXTURES . 'isdoc-ddpp.isdoc')),
            $this->sid, $this->userId, self::SUPPLIER_IC, 'isdoc');
        self::assertTrue($ddpp['ok'], (string) ($ddpp['error'] ?? ''));
        $row = $this->invoiceByVs('2026500001');
        self::assertSame('tax_document', $row['invoice_type'], 'DDPP nesmí propadnout do řádné faktury.');
        self::assertSame('draft', $row['status']);
        self::assertSame('0.00', number_format((float) $row['amount_to_pay'], 2, '.', ''), 'Úplata už přišla, není co doplácet.');

        $final = $createFromIsdoc->invoke($extractor, $parser->parse((string) file_get_contents(self::FIXTURES . 'isdoc-faktura-zalohy.isdoc')),
            $this->sid, $this->userId, self::SUPPLIER_IC, 'isdoc');
        self::assertTrue($final['ok'], (string) ($final['error'] ?? ''));
        self::assertStringContainsString('odečítá zálohy', implode(' ', $final['warnings'] ?? []));
    }
}
