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
 * Sazba, kterou soubor NEURČUJE — celá cesta od XML po uloženou položku.
 *
 * Doplněk k {@see OssInvoiceImportTest}, který hlídá zahraniční doklady. Tady jde o dvě
 * věci, které se týkají BĚŽNÉHO TUZEMSKÉHO exportu z Pohody (ten `<inv:percentVAT>`
 * nepíše) a o chování brány číselníku:
 *
 *   - úroveň `<inv:rateVAT>` se překládá na procento z ČÍSELNÍKU ČLENSKÝCH STÁTŮ K DATU
 *     PLNĚNÍ, a jen u TUZEMSKÉHO odběratele. Natvrdo dosazená česká sazba byla měřeným
 *     únikem: za `high` se dosadilo 21 %, číselník je potvrdil jako tuzemskou sazbu
 *     a polská daň skončila na ř. 1 českého přiznání bez varování;
 *   - „číselník tohle období nepokrývá" NENÍ důvod zastavit celý běh a poslat uživatele
 *     na migrace, které dávno doběhly.
 *
 * Data jsou syntetická a všechno běží v transakci, kterou tearDown rollbackne.
 */
#[Group('integration')]
final class OssImportRateSubstitutionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const SUPPLIER_IC = '12345678';
    private const CZ_CUSTOMER = 'Testovací odběratel s.r.o.';
    private const CZ_CUSTOMER_IC = '25596641';
    private const PL_CONSUMER = 'Testowy Odbiorca sp. z o.o.';

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
        $this->czkId = $this->currency('CZK', 'Kč', 'Koruna česká', 'Czech koruna');
        $pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::SUPPLIER_IC, $this->supplierId]);
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
     * TÝŽ SOUBOR, JINÝ ROK, JINÁ SAZBA. Doklad nese jen `<inv:rateVAT>low</inv:rateVAT>`
     * bez procenta a bez rekapitulace — přesně tvar, který Pohoda běžně exportuje.
     *
     * Snížená sazba byla v ČR 15 % do konce roku 2023 a 12 % od roku 2024, takže
     * zadrátovaná konstanta (ať kterákoli z nich) by jeden z těch dvou dokladů vyměřila
     * špatně. Procento se proto bere z číselníku K DATU PLNĚNÍ — a je to týž podklad,
     * proti kterému pak rozhoduje invariant, takže dosazená sazba nikdy nemůže být
     * sazba, kterou by číselník vzápětí neuznal.
     */
    public function testEnumOnlyRateIsTakenFromTheCodebookValidOnTheTaxDate(): void
    {
        $this->assertRateExists('CZ', 15.0, '2020-05-15');
        $this->assertRateExists('CZ', 12.0, '2024-05-15');
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        $old = $this->importOne('2020.xml', $this->pohodaEnumOnly('20FV0001', '2020-05-15'));
        self::assertSame('created', $old['status'], (string) ($old['reason'] ?? ''));
        $oldItem = $this->itemRow((int) $old['invoice_id']);
        self::assertEqualsWithDelta(15.0, (float) $oldItem['vat_rate_snapshot'], 0.001,
            'Doklad z roku 2020 musí dostat sazbu platnou tehdy, ne dnešní.');
        self::assertEqualsWithDelta(15.0, (float) $oldItem['rate_percent'], 0.001);
        self::assertSame(0, (int) $oldItem['oss_applicable']);

        $new = $this->importOne('2024.xml', $this->pohodaEnumOnly('24FV0001', '2024-05-15'));
        self::assertSame('created', $new['status'], (string) ($new['reason'] ?? ''));
        self::assertEqualsWithDelta(12.0, (float) $this->itemRow((int) $new['invoice_id'])['vat_rate_snapshot'], 0.001);

        // Dosazená sazba je jediné číslo na dokladu, které nepochází ze zdrojového
        // systému — report o ní musí uživatele zpravit.
        self::assertNotEmpty(array_filter(
            (array) ($old['notes'] ?? []),
            static fn (string $n): bool => str_contains($n, 'sazbovou úroveň') && str_contains($n, '15 %'),
        ), 'Report nesmí o dosazené sazbě mlčet: ' . implode(' | ', (array) ($old['notes'] ?? [])));
    }

    /**
     * JÁDRO ÚNIKU — týž soubor, jen odběratel z Polska. Pohoda schema zahraniční sazby
     * nezná, takže `high` na zahraničním dokladu neznamená „21 %", ale „skutečné procento
     * je jinde". Dosazení sazby země dodavatele by z cizí daně udělalo tuzemskou, číselník
     * by ji POZITIVNĚ potvrdil a plnění by prošlo na ř. 1 českého přiznání.
     */
    public function testEnumOnlyRateForAForeignConsumerIsRejectedInsteadOfBecomingCzechTax(): void
    {
        $this->client(self::PL_CONSUMER, 'PL');

        $result = $this->importOne('pl.xml', $this->pohodaEnumOnly(
            'PL0001',
            '2024-05-15',
            company: self::PL_CONSUMER,
            ico: '',
            countryIso2: 'PL',
            enum: 'high',
        ));

        self::assertSame('failed', $result['status'],
            'Zahraniční doklad s enumem bez procenta se nesmí naimportovat s tuzemskou sazbou.');
        self::assertStringContainsString('není tuzemský', (string) $result['reason']);
        self::assertStringContainsString('inv:percentVAT', (string) $result['reason']);
        self::assertSame(0, $this->storedInvoiceCount('PL0001'));
    }

    /**
     * G5c — „ČÍSELNÍK TOHLE OBDOBÍ NEPOKRÝVÁ" NENÍ DŮVOD ZASTAVIT CELÝ BĚH.
     *
     * Brána dřív porovnávala data v balíku s obdobím, které číselník pro zemi dodavatele
     * pokrývá, a když se nepotkaly, shodila běh výjimkou s návodem spustit migrace. Jenže
     * tenhle stav nastane i na instalaci s KOMPLETNĚ nasazenými migracemi — stačí balík
     * dokladů staršího data, než kam seed vůbec sahá (ČR od roku 2010). Uživatel pak dostal
     * návod spustit migrace, které dávno doběhly, což je přesně ta třída zavádějící hlášky,
     * kterou celá vlna napravuje.
     *
     * Doklad se musí odmítnout SÁM ZA SEBE, hláškou, která pojmenuje zemi i datum plnění.
     */
    public function testPeriodTheCodebookDoesNotCoverDoesNotStopTheWholeRun(): void
    {
        $ancient = '2009-05-15';
        self::assertFalse($this->codebookKnowsDomesticRatesAt($ancient),
            'Předpoklad testu padl: číselník sazby země dodavatele k roku 2009 zná, '
                . 'takže se tenhle stav nedá vyvolat.');
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        // Balík je schválně JEDNODOKLADOVÝ: dokud stačilo jedno datum uvnitř pokrytého
        // období, projevila by se stará brána jen u balíku, kde je MIMO pokrytí všechno.
        $out = $this->import->importBundle(
            [['name' => '2009.xml', 'content' => $this->pohodaEnumOnly('09FV0001', $ancient, percent: '19')]],
            $this->supplierId,
            $this->userId,
            'issued',
        );

        self::assertCount(1, $out['results'], 'Běh musí doběhnout a vydat report, ne spadnout výjimkou.');
        self::assertSame('failed', $out['results'][0]['status']);
        self::assertStringContainsString('2009', (string) $out['results'][0]['reason'],
            'Hláška má pojmenovat datum plnění, ke kterému číselník mlčí.');
        self::assertSame(0, $this->storedInvoiceCount('09FV0001'));
    }

    /**
     * G2 — SOUBOR, KTERÝ SI ODPORUJE SÁM SE SEBOU, NESMÍ PROJÍT TIŠE. Položka nese 21 %,
     * rekapitulace v témž souboru na týž základ 23% daň. Importují se čísla z položek,
     * takže je to varování, ne chyba — ale mlčet o tom nelze, protože přesně takhle vypadá
     * doklad, u kterého jedna z obou stran lže.
     */
    public function testSelfContradictingFileIsReportedAsAWarning(): void
    {
        $this->assertRateExists('CZ', 21.0, '2024-05-15');
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        $result = $this->importOne('rozpor.xml', $this->pohodaContradictingRecap('24FV0002'));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertEqualsWithDelta(21.0, (float) $this->itemRow((int) $result['invoice_id'])['vat_rate_snapshot'], 0.001,
            'Importují se sazby z položek, ne z rekapitulace.');
        self::assertNotEmpty(array_filter(
            (array) ($result['warnings'] ?? []),
            static fn (string $w): bool => str_contains($w, 'odporuje'),
        ), 'Rozpor položek a rekapitulace se musí objevit v reportu: '
            . implode(' | ', (array) ($result['warnings'] ?? [])));
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function importOne(string $name, string $content): array
    {
        $out = $this->import->importBundle(
            [['name' => $name, 'content' => $content]],
            $this->supplierId,
            $this->userId,
            'issued',
        );
        self::assertCount(1, $out['results']);

        return $out['results'][0];
    }

    /** @return array<string,mixed> */
    private function itemRow(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.*, vr.code AS rate_code, vr.rate_percent AS rate_percent
               FROM invoice_items ii
               JOIN vat_rates vr ON vr.id = ii.vat_rate_id
              WHERE ii.invoice_id = ?
           ORDER BY ii.order_index, ii.id'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'Doklad má mít právě jednu položku.');

        return $rows[0];
    }

    private function storedInvoiceCount(string $varsymbol): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, $varsymbol]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Sazba musí být v `vat_rates` (jinak by doklad spadl na nenapárované sazbě a test by
     * zeleně tvrdil něco jiného, než co hlídá). Je to PŘEDPOKLAD ze seedu, ne tvrzení.
     */
    private function assertRateExists(string $country, float $percent, string $onDate): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM vat_rates
              WHERE country = ? AND is_reverse_charge = 0 AND ABS(rate_percent - ?) <= 0.005
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)'
        );
        $stmt->execute([$country, $percent, $onDate, $onDate]);
        if ((int) $stmt->fetchColumn() === 0) {
            self::markTestSkipped(sprintf('Číselník sazeb DPH nemá %s %s %% k %s.', $country, $percent, $onDate));
        }
    }

    /** Pokrývá číselník členských států zemi dodavatele k datu? Předpoklad, ne tvrzení. */
    private function codebookKnowsDomesticRatesAt(string $date): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM oss_member_state_rates
              WHERE country = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)'
        );
        $stmt->execute([$this->domesticCountry(), $date, $date]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Tuzemsko izolovaného dodavatele — TÝŽ výraz, jakým ho čte `OssItemDeriver`. */
    private function domesticCountry(): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(UPPER(TRIM(co.iso2)), '')
               FROM supplier s LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$this->supplierId]);

        return (string) $stmt->fetchColumn() ?: 'CZ';
    }

    private function currency(string $code, string $symbol, string $cs, string $en): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)'
        )->execute([$this->supplierId, $code, $cs, $symbol, $cs, $en]);

        return (int) $pdo->lastInsertId();
    }

    /** Klient se zakládá předem, aby `ClientResolver` nesahal na ARES/VIES (síť v testu). */
    private function client(string $name, string $iso2, ?string $ic = null): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query(
            "SELECT id FROM countries WHERE UPPER(iso2) = '" . strtoupper($iso2) . "' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát ' . $iso2 . ' není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, ?, NULL, "Testovací 1", "Město", "11000", ?, "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $name, $ic, $countryId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Pohoda XML tak, jak ho exportuje sama Pohoda u tuzemského dokladu: sazba jen jako
     * ÚROVEŇ v `<inv:rateVAT>`, žádné `<inv:percentVAT>` a — schválně — ani rekapitulace,
     * ze které by šlo procento dopočítat. Procento tedy musí dodat číselník, nebo se
     * doklad odmítne.
     */
    private function pohodaEnumOnly(
        string $number,
        string $date,
        string $company = self::CZ_CUSTOMER,
        string $ico = self::CZ_CUSTOMER_IC,
        string $countryIso2 = 'CZ',
        string $enum = 'low',
        ?string $percent = null,
    ): string {
        $icoXml = $ico !== '' ? "<typ:ico>{$ico}</typ:ico>" : '';
        $percentXml = $percent !== null ? "<inv:percentVAT>{$percent}</inv:percentVAT>" : '';
        $ic = self::SUPPLIER_IC;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                          version="2.0" ico="{$ic}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>issuedInvoice</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$number}</inv:symVar>
                    <inv:date>{$date}</inv:date>
                    <inv:dateTax>{$date}</inv:dateTax>
                    <inv:dateDue>{$date}</inv:dateDue>
                    <inv:text>Plnění bez uvedeného procenta</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>{$company}</typ:company>
                        {$icoXml}
                        <typ:street>Testovací 1</typ:street>
                        <typ:city>Praha</typ:city>
                        <typ:zip>11000</typ:zip>
                        <typ:country><typ:ids>{$countryIso2}</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Konzultace</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>ks</inv:unit>
                      <inv:rateVAT>{$enum}</inv:rateVAT>
                      {$percentXml}
                      <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }

    /** Doklad, jehož položka a rekapitulace tvrdí každá jinou sazbu (§ G2). */
    private function pohodaContradictingRecap(string $number): string
    {
        $ic = self::SUPPLIER_IC;
        $customer = self::CZ_CUSTOMER;
        $customerIc = self::CZ_CUSTOMER_IC;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                          version="2.0" ico="{$ic}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>issuedInvoice</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$number}</inv:symVar>
                    <inv:date>2024-05-15</inv:date>
                    <inv:dateTax>2024-05-15</inv:dateTax>
                    <inv:dateDue>2024-06-15</inv:dateDue>
                    <inv:text>Doklad, který si odporuje</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>{$customer}</typ:company>
                        <typ:ico>{$customerIc}</typ:ico>
                        <typ:street>Testovací 1</typ:street>
                        <typ:city>Praha</typ:city>
                        <typ:zip>11000</typ:zip>
                        <typ:country><typ:ids>CZ</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Konzultace</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>ks</inv:unit>
                      <inv:rateVAT>high</inv:rateVAT>
                      <inv:percentVAT>21</inv:percentVAT>
                      <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  <inv:invoiceSummary>
                    <inv:homeCurrency>
                      <typ:priceHigh>1000</typ:priceHigh>
                      <typ:priceHighVAT rate="23">230</typ:priceHighVAT>
                    </inv:homeCurrency>
                  </inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }
}
