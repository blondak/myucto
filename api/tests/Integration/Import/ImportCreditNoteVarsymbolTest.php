<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Opravný daňový doklad (dobropis) v importu — variabilní symbol, prázdný doklad, vazba
 * na opravovaný doklad.
 *
 * Vada, kterou to hlídá: většina systémů vystavuje dobropis s TÝMŽ variabilním symbolem
 * jako opravovaná faktura, aby vratka došla na stejný symbol. Import ho zahodil jako
 * duplicitu („Faktura s varsymbolem X již existuje") a v exportu zákazníka takhle mizelo
 * 99 dobropisů — tedy celá jedna strana oprav DPH. Zúžit duplicitní kontrolu na typ
 * dokladu nestačí: unikátní index `uq_inv_supplier_varsymbol (supplier_id, varsymbol)`
 * dva takové doklady neuloží vůbec, takže by se tiché přeskočení vyměnilo za pád na
 * duplicitním klíči.
 *
 * Druhá polovina téže vady: SuperFaktura opravný doklad vyváží BEZ `<inv:invoiceDetail>`,
 * takže i po vyřešení symbolu by vznikl dobropis na nulu. Ten je horší než odmítnutí —
 * v seznamu vypadá jako naimportovaný, ale do žádného výkazu nepřispěje.
 *
 * Data jsou syntetická (fiktivní IČO, čísla i názvy, rok 2094) a všechno běží
 * v transakci, která se v tearDown rollbackne.
 */
#[Group('integration')]
final class ImportCreditNoteVarsymbolTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** Rok mimo dosah ostatních fixture (bootstrap 2095, OSS práh 2098/2099, OSS test 2096). */
    private const TAX_DATE = '2094-04-10';
    private const DUE_DATE = '2094-04-24';

    private const SUPPLIER_IC = '12345678';
    private const CZ_CUSTOMER = 'Testovací odběratel s.r.o.';
    private const CZ_CUSTOMER_IC = '25596641';
    private const OTHER_CUSTOMER = 'Jiný testovací odběratel s.r.o.';
    private const OTHER_CUSTOMER_IC = '27074358';

    private Connection $db;
    private InvoiceImportService $import;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private bool $inTx = false;

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

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        // Izolovaný dodavatel je klon jen řádku `supplier` — měny jsou per tenant.
        $this->czkId = $this->currency('CZK', 'Kč');
        // IČO dodavatele musí sedět s `ico` v XML, jinak import doklad odmítne v detectRoute.
        $pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::SUPPLIER_IC, $this->supplierId]);

        $this->client(self::CZ_CUSTOMER, self::CZ_CUSTOMER_IC);
        $this->client(self::OTHER_CUSTOMER, self::OTHER_CUSTOMER_IC);
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

    /**
     * JÁDRO NÁLEZU — dobropis se symbolem opravované faktury se naimportuje pod symbolem
     * ODVOZENÝM z čísla dokladu, a report o té náhradě mluví.
     *
     * Před opravou se doklad zahodil jako duplicita: `skipped` + „již existuje".
     */
    public function testCreditNoteSharingTheInvoiceVarsymbolIsImportedUnderADerivedSymbol(): void
    {
        $invoice = $this->importOne('faktura.xml', $this->pohodaInvoice('2094100010', symVar: '9410000010'));
        self::assertSame('created', $invoice['status'], (string) ($invoice['reason'] ?? ''));

        $credit = $this->importOne('dobropis.xml', $this->pohodaCreditNote(
            'D2094100003',
            symVar: '9410000010',
            correctedNumber: '2094100010',
        ));

        self::assertSame('created', $credit['status'],
            'Dobropis se symbolem opravované faktury se zahodil jako duplicita — přesně nález § 6 D. '
                . 'Důvod: ' . (string) ($credit['reason'] ?? ''));
        self::assertTrue($credit['varsymbol_substituted'],
            'Náhrada symbolu se musí propsat i do souhrnu za běh (`varsymbol_substituted`).');
        self::assertSame('D2094100003', (string) $credit['varsymbol'],
            'Symbol se odvozuje z ČÍSLA DOKLADU — týmž mechanismem jako náhrada GUIDu ze SuperFaktury.');

        // Report nesmí mlčet: doklad má v systému jiný symbol, než měl v souboru.
        $notes = implode("\n", $credit['notes']);
        self::assertStringContainsString('9410000010', $notes, 'Hláška musí uvést symbol ze souboru.');
        self::assertStringContainsString('D2094100003', $notes, 'i symbol, pod kterým doklad v systému leží');
        self::assertStringContainsString('nedohledáte', $notes,
            'Uživatel musí vědět, že pod symbolem ze souboru doklad nenajde.');
        self::assertStringContainsString('dobropis', $notes,
            'a proč se to stalo — kolize s dokladem jiného druhu, ne duplicita');

        // Obojí je v databázi a původní faktura si svůj symbol podržela.
        self::assertSame(1, $this->storedCount('9410000010'), 'Faktura musí zůstat pod svým symbolem.');
        self::assertSame(1, $this->storedCount('D2094100003'));
        self::assertSame('credit_note', $this->invoiceColumn((int) $credit['invoice_id'], 'invoice_type'));
    }

    /**
     * DRUHÁ PŮLKA — skutečná duplicita TÉHOŽ dokladu se musí dál přeskočit.
     *
     * Bez tohohle tvrzení by šlo nález „opravit" tím, že import přestane duplicity poznávat
     * a při opakovaném nahrání souboru založí každý doklad znovu pod novým symbolem.
     */
    public function testGenuineDuplicateOfTheSameDocumentIsStillSkipped(): void
    {
        $xml = $this->pohodaInvoice('2094100020', symVar: '9410000020');
        $first = $this->importOne('faktura.xml', $xml);
        self::assertSame('created', $first['status'], (string) ($first['reason'] ?? ''));

        $again = $this->importOne('faktura-znovu.xml', $xml);
        self::assertSame('skipped', $again['status']);
        self::assertStringContainsString('již existuje', (string) $again['reason']);
        self::assertFalse($again['varsymbol_substituted'],
            'U skutečné duplicity se nesmí nic odvozovat — jinak by se každé opakované nahrání '
                . 'souboru zapsalo znovu pod jiným symbolem.');
        self::assertSame(1, $this->storedCount('9410000020'));

        // A totéž pro dobropis: druhý týž dobropis je duplicita, ne kolize druhů.
        $creditXml = $this->pohodaCreditNote('D2094100020', symVar: '9410000020', correctedNumber: '2094100020');
        $credit = $this->importOne('dobropis.xml', $creditXml);
        self::assertSame('created', $credit['status'], (string) ($credit['reason'] ?? ''));

        $creditAgain = $this->importOne('dobropis-znovu.xml', $creditXml);
        self::assertSame('skipped', $creditAgain['status'],
            'Druhý týž dobropis se potkává s dobropisem STEJNÉHO druhu — to je duplicita.');
        self::assertStringContainsString('již existuje', (string) $creditAgain['reason']);
        self::assertSame(1, $this->storedCount('D2094100020'));
    }

    /**
     * Doklad BEZ JEDINÉ POLOŽKY se odmítne — tak, jak ho SuperFaktura opravné doklady
     * skutečně vyváží (`<inv:invoiceDetail>` v souboru vůbec není).
     *
     * Před opravou se založil dobropis na nulu: `created`, žádné varování, v seznamu
     * k nerozeznání od skutečné vratky.
     */
    public function testDocumentWithoutAnySingleItemIsRejectedInsteadOfBecomingAZeroDocument(): void
    {
        $credit = $this->importOne('dobropis-bez-polozek.xml', $this->pohodaCreditNote(
            'D2094100030',
            symVar: 'D2094100030',
            correctedNumber: '2094100030',
            withItems: false,
        ));

        self::assertSame('failed', $credit['status'],
            'Tichý dobropis na nulu je horší než odmítnutí — uživatel by měl v evidenci prázdné '
                . 'doklady a myslel si, že vratky jsou v systému.');
        $reason = (string) $credit['reason'];
        self::assertStringContainsString('jedinou položku', $reason);
        self::assertStringContainsString('D2094100030', $reason, 'Hláška musí doklad pojmenovat.');
        self::assertStringContainsString('dobropis', $reason, 'a říct, o jaký druh dokladu jde');
        self::assertSame(0, $this->storedCount('D2094100030'),
            'Odmítnutý doklad nesmí po sobě nechat hlavičku bez položek (import nejede v transakci).');

        // Pravidlo platí pro VŠECHNY vydané doklady, ne jen pro dobropisy: faktura na nulu
        // je stejný nesmysl a vzniká stejnou cestou (součty se sčítají z řádků).
        $invoice = $this->importOne('faktura-bez-polozek.xml', $this->pohodaInvoice(
            '2094100031',
            symVar: '9410000031',
            withItems: false,
        ));
        self::assertSame('failed', $invoice['status']);
        self::assertStringContainsString('jedinou položku', (string) $invoice['reason']);
        self::assertSame(0, $this->storedCount('9410000031'));
    }

    /**
     * Vazba na opravovaný doklad musí DRŽET — jinak je odvozený symbol koupený za ztrátu
     * jediné spojnice mezi dobropisem a fakturou.
     *
     * Variantu s odvozeným symbolem lze obhájit jen tehdy, když je vazba jinde: symbol
     * ze souboru dobropis po náhradě nenese a číslo dokladu z původního systému se
     * neukládá. Zbývá `parent_invoice_id` — týž sloupec, jaký plní i dobropis vystavený
     * v aplikaci ({@see \MyInvoice\Action\Invoice\CancelInvoiceAction}).
     */
    public function testCreditNoteIsLinkedToTheCorrectedInvoiceViaParentInvoiceId(): void
    {
        $invoice = $this->importOne('faktura.xml', $this->pohodaInvoice('2094100040', symVar: '9410000040'));
        self::assertSame('created', $invoice['status'], (string) ($invoice['reason'] ?? ''));

        $credit = $this->importOne('dobropis.xml', $this->pohodaCreditNote(
            'D2094100004',
            symVar: '9410000040',
            correctedNumber: '2094100040',
        ));
        self::assertSame('created', $credit['status'], (string) ($credit['reason'] ?? ''));

        self::assertSame(
            (int) $invoice['invoice_id'],
            (int) $this->invoiceColumn((int) $credit['invoice_id'], 'parent_invoice_id'),
            'Dobropis musí ukazovat na opravovanou fakturu — bez toho ho po náhradě symbolu '
                . 'nespojuje s originálem vůbec nic.',
        );
        self::assertStringContainsString('navázali na opravovaný doklad',
            implode("\n", $credit['notes']), 'Vazba patří i do reportu.');

        // Druhá půlka tvrzení: nespojuje se všechno se vším. Dobropis JINÉHO odběratele,
        // který se s fakturou nepotkává ani symbolem, ani odkazem, zůstane bez vazby —
        // špatně navázaný dobropis je horší než nenavázaný (FK má ON DELETE CASCADE).
        $orphan = $this->importOne('dobropis-cizi.xml', $this->pohodaCreditNote(
            'D2094100041',
            symVar: 'D2094100041',
            correctedNumber: '',
            company: self::OTHER_CUSTOMER,
            ico: self::OTHER_CUSTOMER_IC,
        ));
        self::assertSame('created', $orphan['status'], (string) ($orphan['reason'] ?? ''));
        self::assertNull($this->invoiceColumn((int) $orphan['invoice_id'], 'parent_invoice_id'),
            'Dobropis bez odkazu i bez shody symbolu se nesmí navázat na cizí fakturu.');
    }

    // ── pomůcky ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function importOne(string $name, string $content): array
    {
        $out = $this->import->importBundle(
            [['name' => $name, 'content' => $content]],
            $this->supplierId,
            $this->userId,
            'issued',
        );
        self::assertCount(1, $out['results'], 'Očekává se právě jeden výsledek na jeden doklad.');

        return $out['results'][0];
    }

    private function storedCount(string $varsymbol): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, $varsymbol]);

        return (int) $stmt->fetchColumn();
    }

    private function invoiceColumn(int $invoiceId, string $column): mixed
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT `{$column}` FROM invoices WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$invoiceId, $this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        self::assertNotFalse($row, 'Doklad #' . $invoiceId . ' u tohohle dodavatele v databázi není.');

        return $row[0];
    }

    private function currency(string $code, string $symbol): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)'
        )->execute([$this->supplierId, $code, $code, $symbol, $code, $code]);

        return (int) $pdo->lastInsertId();
    }

    private function client(string $name, string $ic): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, ?, "Testovací 1", "Praha", "11000", ?, "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $name, $ic, $countryId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }

    /** Běžná tuzemská faktura v Kč se sazbou 21 %. */
    private function pohodaInvoice(string $number, string $symVar, bool $withItems = true): string
    {
        return $this->pohodaDocument('issuedInvoice', $number, $symVar, '', $withItems, 1, '1000', '210');
    }

    /**
     * Opravný daňový doklad tak, jak ho vyváží SuperFaktura: `issuedCorrectiveTax`,
     * odkaz na opravovaný doklad v `<inv:correctiveDocument>` — a volitelně BEZ položek,
     * protože přesně tak vypadal doklad ve vzorku.
     */
    private function pohodaCreditNote(
        string $number,
        string $symVar,
        string $correctedNumber,
        bool $withItems = true,
        string $company = self::CZ_CUSTOMER,
        string $ico = self::CZ_CUSTOMER_IC,
    ): string {
        return $this->pohodaDocument(
            'issuedCorrectiveTax', $number, $symVar, $correctedNumber, $withItems, -1, '-1000', '-210',
            $company, $ico,
        );
    }

    private function pohodaDocument(
        string $docType,
        string $number,
        string $symVar,
        string $correctedNumber,
        bool $withItems,
        int $quantity,
        string $recapBase,
        string $recapVat,
        string $company = self::CZ_CUSTOMER,
        string $ico = self::CZ_CUSTOMER_IC,
    ): string {
        // Odkaz na opravovaný doklad. `inv:originalDocument` se schválně NEPOUŽÍVÁ —
        // do něj SuperFaktura u řádné faktury zapisuje její vlastní číslo.
        $correctiveXml = $correctedNumber !== ''
            ? "<inv:correctiveDocument><typ:sourceDocument><typ:number>{$correctedNumber}</typ:number>"
                . '</typ:sourceDocument></inv:correctiveDocument>'
            : '';
        $detailXml = $withItems
            ? <<<ITEMS
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Konzultace</inv:text>
                      <inv:quantity>{$quantity}</inv:quantity>
                      <inv:unit>ks</inv:unit>
                      <inv:rateVAT>high</inv:rateVAT>
                      <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  <inv:invoiceSummary>
                    <inv:homeCurrency>
                      <typ:priceHigh>{$recapBase}</typ:priceHigh>
                      <typ:priceHighVAT rate="21">{$recapVat}</typ:priceHighVAT>
                    </inv:homeCurrency>
                  </inv:invoiceSummary>
                ITEMS
            : '';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                          version="2.0" ico="{$this->supplierIcAttr()}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  {$correctiveXml}
                  <inv:invoiceHeader>
                    <inv:invoiceType>{$docType}</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$symVar}</inv:symVar>
                    <inv:date>{$this->issueDate()}</inv:date>
                    <inv:dateTax>{$this->issueDate()}</inv:dateTax>
                    <inv:dateDue>{$this->dueDate()}</inv:dateDue>
                    <inv:text>Tuzemské plnění</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>{$company}</typ:company>
                        <typ:ico>{$ico}</typ:ico>
                        <typ:street>Testovací 1</typ:street>
                        <typ:city>Praha</typ:city>
                        <typ:zip>11000</typ:zip>
                        <typ:country><typ:ids>CZ</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                {$detailXml}
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }

    private function issueDate(): string
    {
        return self::TAX_DATE;
    }

    private function dueDate(): string
    {
        return self::DUE_DATE;
    }

    private function supplierIcAttr(): string
    {
        return self::SUPPLIER_IC;
    }
}
