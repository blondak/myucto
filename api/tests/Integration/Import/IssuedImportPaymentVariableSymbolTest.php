<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\AiIssuedInvoiceExtractor;
use MyInvoice\Service\Import\FakturoidImportService;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Vydaný doklad převzatý z jiného systému nese číslo a variabilní symbol, které se můžou
 * lišit — typicky daňový doklad k proformě s VS proformy. Import ho musí uložit pod SVÝM
 * číslem a odlišný VS do `payment_variable_symbol`. Dřív se číslo a VS slévaly, takže
 * doklad dostal číslo, které na něm vůbec není (Fakturoid bral VS přednostně), nebo
 * ztratil VS, který klient platí (AI a ISDOC import).
 *
 * Obecný import souborů hlídá {@see ImportCreditNoteVarsymbolTest}.
 */
#[Group('integration')]
final class IssuedImportPaymentVariableSymbolTest extends StockTestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/Shoptet/';
    private const SUPPLIER_IC = '12345679';

    private int $sid = 0;
    private int $clientId = 0;

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
        $this->clientId = $this->client($this->sid, 'Testovací odběratel s.r.o.');
        $pdo->prepare('UPDATE clients SET ic = ?, dic = ?, fakturoid_id = ? WHERE id = ?')
            ->execute(['25596641', 'CZ25596641', 990001, $this->clientId]);
    }

    public function testFakturoidStoresDocumentNumberAndSeparateVariableSymbol(): void
    {
        $service = $this->container->get(FakturoidImportService::class);
        $createIssued = new \ReflectionMethod($service, 'createIssued');

        $id = (int) $createIssued->invoke($service, $this->fakturoidInvoice('20940034', '120940044'), $this->sid, $this->userId);

        $row = $this->row($id);
        self::assertSame('20940034', $row['varsymbol'], 'Číslo dokladu je `number`, ne `variable_symbol`.');
        self::assertSame('120940044', $row['payment_variable_symbol']);
    }

    public function testFakturoidWithMatchingVariableSymbolKeepsItDerived(): void
    {
        $service = $this->container->get(FakturoidImportService::class);
        $createIssued = new \ReflectionMethod($service, 'createIssued');

        $id = (int) $createIssued->invoke($service, $this->fakturoidInvoice('20940035', '20940035'), $this->sid, $this->userId);

        $row = $this->row($id);
        self::assertSame('20940035', $row['varsymbol']);
        self::assertNull($row['payment_variable_symbol']);
    }

    public function testAiIssuedImportKeepsDifferentVariableSymbol(): void
    {
        $extractor = $this->container->get(AiIssuedInvoiceExtractor::class);
        $map = new \ReflectionMethod($extractor, 'mapAiToDraft');

        $draft = $map->invoke($extractor, [
            'document_kind'         => 'invoice',
            'vendor_invoice_number' => '20940034',
            'issue_date'            => '2094-08-28',
            'tax_date'              => '2094-08-28',
            'due_date'              => '2094-09-27',
            'currency'              => 'CZK',
            'payment'               => ['variable_symbol' => '120940044'],
            'items'                 => [],
        ], $this->clientId, $this->sid);

        self::assertSame('20940034', $draft['varsymbol']);
        self::assertSame('120940044', $draft['payment_variable_symbol']);
    }

    public function testAiIssuedIsdocKeepsDifferentVariableSymbol(): void
    {
        $extractor = $this->container->get(AiIssuedInvoiceExtractor::class);
        $createFromIsdoc = new \ReflectionMethod($extractor, 'createFromIsdoc');
        $xml = str_replace(
            '</Invoice>',
            '<PaymentMeans><Payment><Details><VariableSymbol>120940044</VariableSymbol></Details></Payment></PaymentMeans></Invoice>',
            (string) file_get_contents(self::FIXTURES . 'isdoc-ddpp.isdoc'),
        );

        $out = $createFromIsdoc->invoke($extractor, (new IsdocParser())->parse($xml), $this->sid, $this->userId, self::SUPPLIER_IC, 'isdoc');

        self::assertTrue($out['ok'], (string) ($out['error'] ?? ''));
        $row = $this->row((int) $out['invoice_id']);
        self::assertSame('2026500001', $row['varsymbol']);
        self::assertSame('120940044', $row['payment_variable_symbol']);
    }

    /** @return array<string,mixed> */
    private function fakturoidInvoice(string $number, string $variableSymbol): array
    {
        return [
            'id'                      => 880001,
            'subject_id'              => 990001,
            'document_type'           => 'invoice',
            'number'                  => $number,
            'variable_symbol'         => $variableSymbol,
            'issued_on'               => '2094-08-28',
            'taxable_fulfillment_due' => '2094-08-28',
            'due_on'                  => '2094-09-27',
            'currency'                => 'CZK',
            'status'                  => 'open',
            'lines'                   => [[
                'name'       => 'Programátorské práce',
                'quantity'   => 1,
                'unit_name'  => 'ks',
                'unit_price' => 1000,
                'vat_rate'   => 21,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol, payment_variable_symbol FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $this->sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Doklad #$id nevznikl.");

        return $row;
    }
}
