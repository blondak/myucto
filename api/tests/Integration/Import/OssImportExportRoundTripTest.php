<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\PohodaXmlExporter;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Oss\OssLedgerService;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sazba dokladu na cestě SKRZ CELÝ PRODUKT — import → náš vlastní export → reimport →
 * daňové výkazy. Regrese proti úniku, který review NAMĚŘILA spuštěným testem, ne čtením
 * kódu, a který si systém způsobil sám sobě vlastním exportem:
 *
 *   1. Pohoda XML s `percentVAT=23` pro polského spotřebitele se naimportovalo správně
 *      (23 %, OSS, PL, mimo tuzemskou evidenci DPH);
 *   2. týž doklad vyexportovaný NAŠÍM `PohodaXmlExporter` obsahoval
 *      `<inv:rateVAT>high</inv:rateVAT>` a řetězec „percentVAT" ANI JEDNOU;
 *   3. reimport toho vlastního souboru vrátil `vat_rate_snapshot` 21,00,
 *      `oss_applicable` 0, klasifikaci '1' a PRÁZDNÁ varování — polská daň 230 PLN se
 *      cestou tiše proměnila v českou a `DphPriznaniBuilder` ji vykázal na ř. 1
 *      (základ 6 000 Kč, daň 1 260 Kč).
 *
 * Nešlo přitom o jednu vadu, ale o dvě, které se potkaly: exportér zapisoval jen ČESKOU
 * SAZBOVOU ÚROVEŇ (`high`) bez procenta, a parser si za tu úroveň dosazoval AKTUÁLNÍ
 * ČESKOU SAZBU. Číselník pak 21 % v ČR k datu plnění POZITIVNĚ potvrdil jako tuzemskou
 * sazbu, takže invariant proti úniku řádek propustil jako tuzemský — invariant je totiž
 * jen tak dobrý jako procento, na které se ptá.
 *
 * Proto se tady netvrdí nic o obsahu sloupců bez toho, aby se tvrzení zároveň ověřilo
 * SKUTEČNÝM {@see DphPriznaniBuilder} a {@see OssLedgerService}: přesně ty sloupce
 * vypadaly u naměřeného úniku nevinně. V každém testu, kde se tvrdí „na ř. 1 to není",
 * je zároveň KONTROLNÍ tuzemský doklad téhož období — tvrzení o prázdném výkazu platí
 * vždycky a nedokazuje nic.
 *
 * Data jsou syntetická (fiktivní firmy, rok 2096, kurzy nasazené do `exchange_rates`)
 * a všechno běží v transakci, kterou tearDown rollbackne.
 */
#[Group('integration')]
final class OssImportExportRoundTripTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** Rok mimo dosah ostatních fixture (bootstrap 2095, OSS práh 2098/2099). */
    private const TAX_DATE    = '2096-05-15';
    private const DUE_DATE    = '2096-06-15';
    private const YEAR        = 2096;
    private const MONTH       = 5;
    private const QUARTER     = 2;

    /** Historický doklad — snížená sazba byla tehdy 15 %, dnes je 12 %. */
    private const OLD_TAX_DATE = '2020-05-15';
    private const OLD_DUE_DATE = '2020-06-15';

    /** Kurzy ČNB k DUZP — 1 PLN = 6 Kč, 1 EUR = 25 Kč, tedy PLN→EUR přesně 0,24. */
    private const PLN_CZK = 6.0;
    private const EUR_CZK = 25.0;

    private const SUPPLIER_IC    = '12345678';
    private const CZ_CUSTOMER    = 'Testovací odběratel s.r.o.';
    private const CZ_CUSTOMER_IC = '25596641';
    private const PL_CONSUMER    = 'Testowy Odbiorca sp. z o.o.';

    private Connection $db;
    private InvoiceImportService $import;
    private PohodaXmlExporter $exporter;
    private OssLedgerService $oss;
    private DphPriznaniBuilder $dph;

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
            $this->db       = $c->get(Connection::class);
            $this->import   = $c->get(InvoiceImportService::class);
            $this->exporter = $c->get(PohodaXmlExporter::class);
            $this->oss      = $c->get(OssLedgerService::class);
            $this->dph      = $c->get(DphPriznaniBuilder::class);
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
        $this->czkId = $this->currency('CZK', 'Kč', 'Koruna česká', 'Czech koruna', isDefault: true);
        $this->currency('PLN', 'zł', 'Polský zlotý', 'Polish zloty');

        // IČO musí sedět s `ico` v XML, jinak import doklad odmítne už v detectRoute.
        $pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::SUPPLIER_IC, $this->supplierId]);

        // Kurzy do cache ČNB klienta — jinak by přepočet do měny podání sahal na síť.
        $this->exchangeRate('PLN', self::PLN_CZK);
        $this->exchangeRate('EUR', self::EUR_CZK);
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

    // ── 1) ROUND TRIP VLASTNÍM EXPOREM ───────────────────────────────────────

    /**
     * NAMĚŘENÝ ÚNIK CELÝ, OD ZAČÁTKU DO KONCE. Doklad se naimportuje jako OSS/PL 23 %,
     * pak se vyexportuje NAŠÍM exportérem a výsledek se reimportuje.
     *
     * Přípustné jsou právě dva konce a test je bere jako alternativu, protože obě jsou
     * bezpečné a systém smí zvolit kteroukoli:
     *   a) export OSS doklad ODMÍTNE (dnešní chování — Pohoda schema nemá kam uložit zemi
     *      spotřeby, takže by zahraniční sazba dorazila jako tuzemská; stejně se chová
     *      `StereoXmlExporter`), nebo
     *   b) vyexportovaný soubor nese SKUTEČNÉ procento a reimport vrátí zase 23 % a OSS.
     *
     * Nepřípustný je právě ten třetí konec, který review naměřila: reimport se sazbou
     * 21 %, `oss_applicable` 0 a základem 6 000 Kč na ř. 1 českého přiznání.
     *
     * BEZ OPRAVY PADÁ: exportér zapsal `rateVAT=high` bez `percentVAT`, parser si za
     * `high` dosadil 21 %, reimport vytvořil tuzemský doklad a ř. 1 vyšel na 7 000 Kč
     * (1 000 kontrolních + 6 000 z polského dokladu) místo 1 000 Kč.
     */
    public function testOssDocumentDoesNotBecomeCzechTaxByPassingThroughOurOwnPohodaExport(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        // Kontrolní tuzemský doklad ve STEJNÉM období: bez něj by „polská daň na ř. 1
        // není" prošlo i nad výkazem, který nevrací vůbec nic.
        $control = $this->importOne('tuzemsko.xml', $this->pohodaDomestic('26FV0001', percent: '21'));
        self::assertSame('created', $control['status'], (string) ($control['reason'] ?? ''));

        $oss = $this->importOne('oss-pl.xml', $this->pohodaForeignConsumer('26OSS0001', percent: '23'));
        self::assertSame('created', $oss['status'], (string) ($oss['reason'] ?? ''));
        self::assertSame(1, $oss['oss_items']);
        $ossItem = $this->itemRow((int) $oss['invoice_id']);
        self::assertEqualsWithDelta(23.0, (float) $ossItem['vat_rate_snapshot'], 0.001);
        self::assertSame(1, (int) $ossItem['oss_applicable']);
        self::assertSame('PL', (string) $ossItem['oss_consumer_country']);

        // ── export ───────────────────────────────────────────────────────────
        $refusal = null;
        $exported = null;
        try {
            $exported = $this->exporter->export([(int) $oss['invoice_id']], $this->supplierId);
        } catch (\RuntimeException $e) {
            $refusal = $e->getMessage();
        }

        if ($refusal !== null) {
            // Varianta (a). Hláška musí pojmenovat doklad a poslat uživatele na OSS
            // přiznání — jinak nemá co s dokladem udělat.
            self::assertStringContainsString('OSS', $refusal);
            self::assertStringContainsString('26OSS0001', $refusal,
                'Odmítnutí musí říct, KTERÝ doklad z balíku vadí.');
        } else {
            // Varianta (b). Soubor, ze kterého sazba nejde přečíst, je vada sama o sobě:
            // přesně tím se cizí daň mění na českou.
            self::assertNotNull($exported);
            self::assertStringContainsString('percentVAT', (string) $exported['content'],
                'Vyexportovaný soubor neuvádí skutečné procento — čtenář, který zná jen enum '
                    . '„high", za něj dosadí SVOJI základní sazbu.');

            $back = $this->importOne(
                'reimport.xml',
                $this->withVarsymbol((string) $exported['content'], '26OSS0002'),
            );
            if ($back['status'] === 'created') {
                $backItem = $this->itemRow((int) $back['invoice_id']);
                self::assertEqualsWithDelta(23.0, (float) $backItem['vat_rate_snapshot'], 0.001,
                    'Reimport vlastního souboru vrátil jinou sazbu, než jaká na dokladu byla.');
                self::assertSame(1, (int) $backItem['oss_applicable'],
                    'Reimport z dokladu udělal tuzemské plnění — odtud vede přímá cesta na ř. 1.');
            }
        }

        // ── výkazy: ř. 1 nese JEN kontrolní tuzemský doklad ───────────────────
        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertArrayHasKey('1', $dph['summary']['lines'],
            'Kontrolní tuzemský doklad na ř. 1 chybí — pak netvrdí nic ani zbytek testu.');
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'], 0.01,
            'Na ř. 1 patří jen tuzemských 1 000 Kč. 7 000 Kč = přidal se polský doklad '
                . '(1 000 PLN kurzem 6) — přesně naměřený únik.');
        self::assertEqualsWithDelta(210.0, $dph['summary']['lines']['1']['vat'], 0.01,
            'Daň 1 470 Kč místo 210 Kč znamená, že se do české daně přimíchalo polských 230 PLN.');

        // A polský doklad je pořád právě jednou v OSS podkladu — ne dvakrát (reimport),
        // ne nulakrát (ztracen).
        $pl = $this->ossCountry($this->oss->preview($this->supplierId, self::YEAR, self::QUARTER), 'PL');
        self::assertEqualsWithDelta(240.0, $pl['base'], 0.01, 'Základ 1 000 PLN kurzem 0,24 = 240 EUR.');
        self::assertEqualsWithDelta(55.2, $pl['vat'], 0.01, 'Daň 230 PLN kurzem 0,24 = 55,20 EUR.');
    }

    /**
     * Druhá polovina téhož round tripu — větev, kterou export SMÍ projít. Tuzemský doklad
     * se vyexportuje a hned reimportuje; sazba musí přežít beze změny.
     *
     * Naměřeno bylo, že vyexportovaný soubor NEOBSAHUJE řetězec „percentVAT" ani jednou,
     * takže z něj sazba nejde přečíst — dá se jen dohadovat z české úrovně `high`. U
     * tuzemského dokladu vyjde dohad náhodou správně, ale je to týž soubor a týž mechanismus,
     * kterým se zahraniční sazba mění na českou; ta shoda platí jen do nejbližší změny sazeb.
     *
     * BEZ OPRAVY PADÁ: v exportu `percentVAT` nebyl, takže první tvrzení neprojde.
     */
    public function testDomesticDocumentKeepsItsRateThroughOurOwnExportAndBack(): void
    {
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        $first = $this->importOne('tuzemsko.xml', $this->pohodaDomestic('26FV0007', percent: '21'));
        self::assertSame('created', $first['status'], (string) ($first['reason'] ?? ''));

        $exported = $this->exporter->export([(int) $first['invoice_id']], $this->supplierId);
        self::assertStringContainsString('<inv:percentVAT>21.00</inv:percentVAT>', $exported['content'],
            'Náš export neuvádí skutečné procento — soubor, ze kterého sazba nejde přečíst, '
                . 'je přesně ten tvar, kterým se cizí daň mění na českou.');

        $back = $this->importOne('reimport.xml', $this->withVarsymbol($exported['content'], '26FV0008'));
        self::assertSame('created', $back['status'], (string) ($back['reason'] ?? ''));
        $item = $this->itemRow((int) $back['invoice_id']);
        self::assertEqualsWithDelta(21.0, (float) $item['vat_rate_snapshot'], 0.001);
        self::assertSame(0, (int) $item['oss_applicable']);
        self::assertEqualsWithDelta(1000.0, (float) $item['unit_price_without_vat'], 0.01,
            'Round trip změnil základ daně — cena se cestou přepočetla jinou sazbou.');

        // Reimport je druhý doklad téhož období, takže ř. 1 musí být přesně dvojnásobek.
        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(2000.0, $dph['summary']['lines']['1']['base'] ?? 0.0, 0.01);
        self::assertEqualsWithDelta(420.0, $dph['summary']['lines']['1']['vat'] ?? 0.0, 0.01);
    }

    // ── 2) ENUM BEZ PROCENTA U ZAHRANIČNÍHO ODBĚRATELE ───────────────────────

    /**
     * Enum `high` na dokladu pro POLSKÉHO spotřebitele neznamená „21 %". Pohoda schema
     * zahraniční sazbu neumí zapsat (viz hlášení zákazníka), takže úroveň tam znamená
     * jedině „skutečné procento je jinde" — a když v souboru nikde není, sazba známá NENÍ.
     *
     * BEZ OPRAVY PADÁ: parser dosadil za `high` českých 21 %, číselník je pro ČR k datu
     * plnění potvrdil jako tuzemskou sazbu, invariant řádek pustil jako tuzemský,
     * klasifikátor mu dal kód '1' a doklad skončil na ř. 1 českého přiznání — bez jediného
     * varování a bez jediné položky v reportu.
     */
    public function testEnumWithoutPercentIsNeverSubstitutedWithTheDomesticRateForAForeignConsumer(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        $control = $this->importOne('tuzemsko.xml', $this->pohodaDomestic('26FV0002', percent: '21'));
        self::assertSame('created', $control['status'], (string) ($control['reason'] ?? ''));

        // Ani procento, ani rekapitulace, ze které by šlo dopočítat — jen úroveň.
        $result = $this->importOne('pl-enum.xml', $this->pohodaForeignConsumer('26OSS0003', percent: null, recap: false));

        self::assertSame('failed', $result['status'],
            'Zahraniční doklad, u kterého soubor sazbu neurčuje, se nesmí naimportovat s tuzemskou sazbou.');
        self::assertStringContainsString('inv:percentVAT', (string) $result['reason'],
            'Hláška má říct, co do souboru doplnit.');
        self::assertSame(0, $this->storedInvoiceCount('26OSS0003'),
            'Odmítnutý doklad nesmí po sobě v datech nic nechat — import nejede v transakci.');

        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertArrayHasKey('1', $dph['summary']['lines'], 'Kontrolní doklad na ř. 1 chybí.');
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'], 0.01,
            'Na ř. 1 se objevil i polský doklad — přesně ten únik, který dosazení sazby působilo.');
    }

    // ── 3) ENUM BEZ PROCENTA U TUZEMSKÉHO ODBĚRATELE (regrese běžného provozu) ─

    /**
     * Druhá strana téže mince: běžný export z Pohody `<inv:percentVAT>` NEPÍŠE, takže
     * tuzemský doklad nese jen `high`. Ten se musí naimportovat úplně normálně — jinak
     * by oprava zahraničního úniku znamenala, že tuzemským zákazníkům přestane fungovat
     * import jako takový.
     *
     * Procento se přitom nebere z konstanty v kódu, ale z ČÍSELNÍKU pro zemi dodavatele
     * k datu plnění — tedy z TÉHOŽ podkladu, proti kterému pak rozhoduje invariant.
     *
     * BEZ OPRAVY PADÁ (v opačném směru než ostatní testy): zákaz dosazování bez rozlišení
     * odběratele by tenhle doklad odmítl a běžný tuzemský import by se zastavil.
     */
    public function testEnumWithoutPercentForADomesticCustomerImportsAtTheDomesticRate(): void
    {
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        $result = $this->importOne('tuzemsko-enum.xml', $this->pohodaDomestic('26FV0003', percent: null, recap: false));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertEqualsWithDelta(21.0, (float) $item['vat_rate_snapshot'], 0.001);
        self::assertEqualsWithDelta(21.0, (float) $item['rate_percent'], 0.001);
        self::assertSame('CZ', (string) $item['rate_country']);
        self::assertSame(0, (int) $item['oss_applicable']);
        self::assertSame('1', (string) $item['vat_classification_code'],
            'Tuzemské plnění v základní sazbě patří na ř. 1 — kód 1.');

        // Dosazená sazba je jediné číslo na dokladu, které nepochází ze zdrojového
        // systému; report o ní mlčet nesmí.
        self::assertNotEmpty(array_filter(
            (array) ($result['notes'] ?? []),
            static fn (string $n): bool => str_contains($n, 'sazbovou úroveň') && str_contains($n, '21 %'),
        ), 'Report neuvedl, že jsme sazbu dosadili: ' . implode(' | ', (array) ($result['notes'] ?? [])));

        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'] ?? 0.0, 0.01);
        self::assertEqualsWithDelta(210.0, $dph['summary']['lines']['1']['vat'] ?? 0.0, 0.01);
    }

    // ── 4) DOSAZENÍ ZÁVISLÉ NA DATU ──────────────────────────────────────────

    /**
     * Týž soubor, jiný rok, jiná sazba. Snížená sazba byla v ČR 15 % do konce roku 2023
     * a 12 % od roku 2024, takže zadrátovaná konstanta (ať kterákoli z nich) vyměří jeden
     * z těch dvou dokladů špatně.
     *
     * BEZ OPRAVY PADÁ: parser dosazoval za `low` natvrdo AKTUÁLNÍCH 12 %, takže doklad
     * z roku 2020 dostal základ i daň o tři procentní body vedle a naimportoval se do
     * sazby, která v době plnění vůbec neexistovala.
     */
    public function testEnumWithoutPercentUsesTheRateValidOnTheTaxDateNotTodays(): void
    {
        $this->assertVatRateExists('CZ', 15.0, self::OLD_TAX_DATE);
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        $result = $this->importOne('2020.xml', $this->pohodaDomestic(
            '20FV0001',
            percent: null,
            recap: false,
            enum: 'low',
            date: self::OLD_TAX_DATE,
            dueDate: self::OLD_DUE_DATE,
        ));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertEqualsWithDelta(15.0, (float) $item['vat_rate_snapshot'], 0.001,
            'Doklad z roku 2020 dostal dnešní sníženou sazbu 12 % místo tehdejších 15 %.');
        self::assertEqualsWithDelta(15.0, (float) $item['rate_percent'], 0.001);
        self::assertSame(0, (int) $item['oss_applicable']);
    }

    // ── 5) DOPOČET Z REKAPITULACE ────────────────────────────────────────────

    /**
     * Procento se nevymýšlí, ale DÁ SE SPOČÍTAT. Položka nese jen `high`, jenže
     * `<invoiceSummary>` v TÉMŽE souboru uvádí základ 1 000 a daň 230 — procento je tedy
     * 23 % a není to dohad, je to aritmetika ze stejného souboru.
     *
     * BEZ OPRAVY PADÁ: dopočet neexistoval, za `high` se dosadila česká sazba, takže
     * z polských 23 % bylo 21 % a doklad prošel jako tuzemský; po zákazu dosazování
     * (bez dopočtu) by se naopak odmítl doklad, jehož sazba je v souboru jednoznačně
     * spočitatelná.
     */
    public function testRateIsComputedFromTheSummaryRecapWhenTheItemDoesNotStateIt(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');

        // Rekapitulace záměrně BEZ atributu @rate — procento smí vzejít jedině z částek.
        $result = $this->importOne('pl-recap.xml', $this->pohodaForeignConsumer(
            '26OSS0004',
            percent: null,
            recap: true,
            declaredRate: null,
        ));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertEqualsWithDelta(23.0, (float) $item['vat_rate_snapshot'], 0.001,
            'Sazba se měla dopočítat z rekapitulace (230 / 1 000), ne dosadit z enumu.');
        self::assertSame(1, (int) $item['oss_applicable']);
        self::assertSame('PL', (string) $item['oss_consumer_country']);

        $pl = $this->ossCountry($this->oss->preview($this->supplierId, self::YEAR, self::QUARTER), 'PL');
        self::assertEqualsWithDelta(240.0, $pl['base'], 0.01);
        self::assertEqualsWithDelta(55.2, $pl['vat'], 0.01);
    }

    // ── 6) SEBEODPORUJÍCÍ SOUBOR ─────────────────────────────────────────────

    /**
     * Soubor, jehož položky říkají jedno a rekapitulace druhé, nesmí projít TIŠE.
     * Importují se čísla z položek (výkazy sumují řádky), takže je to varování, ne chyba —
     * ale obě čísla platit zároveň nemůžou a uživatel se to musí dozvědět, dokud má
     * zdrojový soubor po ruce.
     *
     * Testují se OBĚ podoby rozporu, protože každou hlásí jiná větev kontroly:
     * jiná množina SAZEB a jiný ZÁKLAD při shodné sazbě.
     *
     * BEZ OPRAVY PADÁ: křížová kontrola neexistovala; doklad, jehož položka nese 21 %
     * a rekapitulace k témuž základu 23% daň, se naimportoval bez jediného varování.
     */
    public function testSelfContradictingFileIsReportedInsteadOfPassingSilently(): void
    {
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        $rateConflict = $this->importOne('rozpor-sazba.xml', $this->pohodaDomestic(
            '26FV0004',
            percent: '21',
            recap: true,
            recapBase: '1000',
            recapVat: '230',
        ));
        self::assertSame('created', $rateConflict['status'], (string) ($rateConflict['reason'] ?? ''));
        self::assertEqualsWithDelta(21.0, (float) $this->itemRow((int) $rateConflict['invoice_id'])['vat_rate_snapshot'], 0.001,
            'Importují se sazby z položek, ne z rekapitulace.');
        self::assertNotEmpty(array_filter(
            (array) ($rateConflict['warnings'] ?? []),
            static fn (string $w): bool => str_contains($w, 'odporuje') && str_contains($w, 'rekapitulace'),
        ), 'Rozpor sazeb se do reportu nedostal: ' . implode(' | ', (array) ($rateConflict['warnings'] ?? [])));

        $baseConflict = $this->importOne('rozpor-zaklad.xml', $this->pohodaDomestic(
            '26FV0005',
            percent: '21',
            recap: true,
            recapBase: '5000',
            recapVat: '1050',
        ));
        self::assertSame('created', $baseConflict['status'], (string) ($baseConflict['reason'] ?? ''));
        self::assertNotEmpty(array_filter(
            (array) ($baseConflict['warnings'] ?? []),
            static fn (string $w): bool => str_contains($w, 'odporuje') && str_contains($w, 'základ'),
        ), 'Rozpor základů se do reportu nedostal: ' . implode(' | ', (array) ($baseConflict['warnings'] ?? [])));
    }

    // ── 7) JEDEN DOKLAD ZÁROVEŇ V OBOU VÝKAZECH ──────────────────────────────

    /**
     * Jeden doklad, jeden polský spotřebitel, dvě položky: první s `percentVAT=23`,
     * druhá jen s `high`. Naměřený stav byl, že první šla do OSS a druhá — s dosazenými
     * českými 21 % — na ř. 1 českého přiznání. TÝŽ doklad byl tedy zároveň v OSS podkladu
     * i v tuzemském přiznání, bez jediného varování.
     *
     * Odmítá se CELÝ doklad, ne jen vadný řádek: doklad s vynechaným řádkem by v seznamu
     * vypadal kompletní, jen by měl nižší součty.
     *
     * BEZ OPRAVY PADÁ: doklad se vytvořil, ř. 1 vyšel na 7 000 Kč místo 1 000 Kč a v OSS
     * podkladu byl týž doklad zároveň.
     */
    public function testMixedDocumentNeverEndsUpInTheOssReturnAndTheCzechOneAtOnce(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        $control = $this->importOne('tuzemsko.xml', $this->pohodaDomestic('26FV0006', percent: '21'));
        self::assertSame('created', $control['status'], (string) ($control['reason'] ?? ''));

        $result = $this->importOne('pl-mix.xml', $this->pohodaForeignConsumerTwoItems('26OSS0005'));

        self::assertSame('failed', $result['status'],
            'Doklad, jehož jedna položka nemá určenou sazbu, se nesmí naimportovat po částech.');
        self::assertStringContainsString('Položka č. 2', (string) $result['reason'],
            'Hláška má říct, KTERÝ řádek dokladem pohnul.');
        self::assertSame(0, $this->storedInvoiceCount('26OSS0005'));

        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'] ?? 0.0, 0.01,
            'Na ř. 1 je i polská položka — doklad se rozpadl do obou výkazů zároveň.');

        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        self::assertSame(0, (int) ($preview['summary']['invoice_count'] ?? 0),
            'Odmítnutý doklad nesmí být ani v OSS podkladu — jinak je vykázaný dvakrát.');
    }

    // ── 8) ISDOC: MLČENÍ NENÍ NULA ───────────────────────────────────────────

    /**
     * Chybějící `<ClassifiedTaxCategory><Percent>` je MLČENÍ, ne prohlášení o nule.
     * Tichý přetyp na 0.0 je tentýž únik jinou větví, a horší: invariant proti úniku se
     * na nulovou sazbu ZÁMĚRNĚ neuplatňuje (u plnění bez daně není co unikat), takže by
     * daň z dokladu nezmizela do špatné země, ale úplně.
     *
     * BEZ OPRAVY PADÁ: řádek se naimportoval jako osvobozené plnění za 0 %, doklad
     * vznikl a daň se z evidence ztratila beze stopy.
     */
    public function testIsdocLineWithoutPercentIsRejectedInsteadOfBecomingAnExemptSupply(): void
    {
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        $result = $this->importOne('bez-percenta.isdoc', $this->isdocDomestic('26ISD0001', percent: null));

        self::assertSame('failed', $result['status'],
            'Řádek bez procenta se naimportoval — sazba se tiše stala nulou.');
        self::assertStringContainsString('Percent', (string) $result['reason'],
            'Hláška má pojmenovat element, který v souboru chybí.');
        self::assertSame(0, $this->storedInvoiceCount('26ISD0001'));
    }

    /**
     * Protipól téhož pravidla: `<VATApplicable>false</VATApplicable>` na dokladu je
     * PROHLÁŠENÍ o plnění bez daně (ISDOC 4.1.5), takže nula je tam správně a doklad
     * musí projít. Bez tohohle rozlišení by oprava zablokovala import od neplátců DPH.
     *
     * BEZ OPRAVY PADÁ: kdyby se „chybějící procento" a „nedaňový doklad" slily do jedné
     * větve, odmítl by se i tenhle legitimní doklad.
     */
    public function testIsdocNonTaxDocumentKeepsItsLegitimateZeroRate(): void
    {
        $this->client(self::CZ_CUSTOMER, 'CZ', self::CZ_CUSTOMER_IC);

        $result = $this->importOne('nedanovy.isdoc', $this->isdocDomestic(
            '26ISD0002',
            percent: null,
            vatApplicable: false,
        ));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertEqualsWithDelta(0.0, (float) $item['vat_rate_snapshot'], 0.001);
        self::assertSame(0, (int) $item['oss_applicable']);
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
        self::assertCount(1, $out['results'], 'Očekává se právě jeden výsledek na jeden doklad.');

        return $out['results'][0];
    }

    /**
     * Varsymbol i evidenční číslo ve vyexportovaném souboru — reimport vlastního exportu
     * by jinak spadl na duplicitě a test by zeleně tvrdil něco úplně jiného, než co hlídá.
     */
    private function withVarsymbol(string $xml, string $varsymbol): string
    {
        $numeric = (string) preg_replace('/\D+/', '', $varsymbol);
        $xml = (string) preg_replace(
            '~(<inv:number>\s*<typ:numberRequested>)[^<]*(</typ:numberRequested>)~',
            '${1}' . $varsymbol . '${2}',
            $xml,
        );

        return (string) preg_replace(
            '~(<inv:symVar>)[^<]*(</inv:symVar>)~',
            '${1}' . ($numeric !== '' ? $numeric : $varsymbol) . '${2}',
            $xml,
        );
    }

    /** @return array<string,mixed> */
    private function itemRow(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.*, vr.code AS rate_code, vr.country AS rate_country, vr.rate_percent AS rate_percent
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

    /**
     * Kolik dokladů daného varsymbolu po sobě běh nechal. Odmítnutí musí vracet NULU:
     * import nejede v transakci, takže „doklad se nevytvořil" je tvrzení o datech.
     */
    private function storedInvoiceCount(string $varsymbol): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, $varsymbol]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param  array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function ossCountry(array $preview, string $iso2): array
    {
        foreach ($preview['countries'] as $country) {
            if ($country['country'] === $iso2) {
                return $country;
            }
        }
        self::fail('OSS podklad neobsahuje sekci ' . $iso2 . '.');
    }

    /**
     * Sazba musí být v `vat_rates`, jinak by doklad spadl na nenapárované sazbě a test by
     * zeleně tvrdil něco jiného, než co hlídá. Je to PŘEDPOKLAD ze seedu, ne tvrzení.
     */
    private function assertVatRateExists(string $country, float $percent, string $onDate): void
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

    private function enableOss(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET oss_enabled = 1,
                    oss_valid_from = '2096-01-01',
                    oss_valid_to = NULL,
                    oss_identification_country = 'CZ',
                    oss_return_currency = 'EUR'
              WHERE id = ?"
        )->execute([$this->supplierId]);
    }

    private function currency(string $code, string $symbol, string $cs, string $en, bool $isDefault = false): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, ?)'
        )->execute([$this->supplierId, $code, $cs, $symbol, $cs, $en, $isDefault ? 1 : 0]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Sazba pro stát spotřeby v `vat_rates`. Zakládá ji TEST, ne import: `vat_rates` je
     * globální tabulka bez `supplier_id`, takže zápis z importu jednoho nájemníka by měnil
     * číselník celé instalaci. Uživatel ji zakládá v Nastavení → Sazby DPH.
     */
    private function vatRate(string $country, float $percent, string $validFrom = '2090-01-01'): void
    {
        $code = strtoupper($country) . '-' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        $this->db->pdo()->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default,
                                    is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, 0, 0, ?, NULL, 900)
             ON DUPLICATE KEY UPDATE rate_percent = VALUES(rate_percent), country = VALUES(country),
                                     valid_from = VALUES(valid_from), valid_to = VALUES(valid_to)'
        )->execute([$code, $percent, strtoupper($country), $code, $code, $validFrom]);
    }

    private function exchangeRate(string $code, float $rate, string $date = self::TAX_DATE): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$date, $code, $rate]);
    }

    /**
     * Klient se zakládá PŘEDEM: `ClientResolver` by pro neznámé české IČO sáhl na ARES
     * a pro cizí DIČ na VIES, takže by test závisel na dostupnosti sítě.
     */
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

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Doklad v PLN pro polského spotřebitele bez DIČ.
     *
     * `$percent = null` = soubor procento neuvádí a nese jen sazbovou ÚROVEŇ (tak vypadá
     * náš vlastní starý export i běžný export z Pohody). `$recap = false` navíc odebere
     * i přihrádky rekapitulace, takže procento není odkud vzít ani dopočtem — blok
     * `foreignCurrency` zůstává kvůli měně a kurzu.
     */
    private function pohodaForeignConsumer(
        string $number,
        ?string $percent = '23',
        bool $recap = true,
        ?string $declaredRate = '23',
        string $enum = 'high',
    ): string {
        $percentXml = $percent !== null ? "<inv:percentVAT>{$percent}</inv:percentVAT>" : '';
        $rateAttr = $declaredRate !== null ? " rate=\"{$declaredRate}\"" : '';
        $recapXml = $recap
            ? "<typ:priceHigh>1000</typ:priceHigh>\n                      "
                . "<typ:priceHighVAT{$rateAttr}>230</typ:priceHighVAT>"
            : '';
        $ic = self::SUPPLIER_IC;
        $company = self::PL_CONSUMER;
        $date = self::TAX_DATE;
        $due = self::DUE_DATE;

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
                    <inv:dateDue>{$due}</inv:dateDue>
                    <inv:text>Prodej spotřebiteli do Polska</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>{$company}</typ:company>
                        <typ:street>Ulica 1</typ:street>
                        <typ:city>Warszawa</typ:city>
                        <typ:zip>00-001</typ:zip>
                        <typ:country><typ:ids>PL</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Zboží do Polska</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>{$enum}</inv:rateVAT>
                      {$percentXml}
                      <inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  <inv:invoiceSummary>
                    <inv:foreignCurrency>
                      <typ:currency><typ:ids>PLN</typ:ids></typ:currency>
                      <typ:rate>6</typ:rate>
                      <typ:amount>1</typ:amount>
                      {$recapXml}
                    </inv:foreignCurrency>
                  </inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }

    /**
     * Týž polský doklad o dvou položkách: první se skutečným procentem, druhá jen s enumem.
     * Rekapitulace tu schválně není — kdyby byla, procento druhé položky by z ní šlo
     * dopočítat a test by hlídal něco jiného, než co review naměřila.
     */
    private function pohodaForeignConsumerTwoItems(string $number): string
    {
        $ic = self::SUPPLIER_IC;
        $company = self::PL_CONSUMER;
        $date = self::TAX_DATE;
        $due = self::DUE_DATE;

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
                    <inv:dateDue>{$due}</inv:dateDue>
                    <inv:text>Prodej spotřebiteli do Polska</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>{$company}</typ:company>
                        <typ:street>Ulica 1</typ:street>
                        <typ:city>Warszawa</typ:city>
                        <typ:zip>00-001</typ:zip>
                        <typ:country><typ:ids>PL</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Zboží do Polska</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>historyHigh</inv:rateVAT>
                      <inv:percentVAT>23</inv:percentVAT>
                      <inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>
                    </inv:invoiceItem>
                    <inv:invoiceItem>
                      <inv:text>Balné</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>ks</inv:unit>
                      <inv:rateVAT>high</inv:rateVAT>
                      <inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  <inv:invoiceSummary>
                    <inv:foreignCurrency>
                      <typ:currency><typ:ids>PLN</typ:ids></typ:currency>
                      <typ:rate>6</typ:rate>
                      <typ:amount>1</typ:amount>
                    </inv:foreignCurrency>
                  </inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }

    /**
     * Tuzemský korunový doklad. `$percent = null` = tvar, jaký exportuje sama Pohoda
     * (procento u položky neuvádí); `$recap = false` odebere i rekapitulaci, takže sazbu
     * musí dodat číselník pro zemi dodavatele k datu plnění, nebo se doklad odmítne.
     */
    private function pohodaDomestic(
        string $number,
        ?string $percent = '21',
        bool $recap = true,
        string $enum = 'high',
        string $date = self::TAX_DATE,
        string $dueDate = self::DUE_DATE,
        string $recapBase = '1000',
        string $recapVat = '210',
    ): string {
        $percentXml = $percent !== null ? "<inv:percentVAT>{$percent}</inv:percentVAT>" : '';
        $bucket = $enum === 'high' ? 'High' : ($enum === 'low' ? 'Low' : '3');
        $recapXml = $recap
            ? "<inv:invoiceSummary>\n                    <inv:homeCurrency>\n"
                . "                      <typ:price{$bucket}>{$recapBase}</typ:price{$bucket}>\n"
                . "                      <typ:price{$bucket}VAT>{$recapVat}</typ:price{$bucket}VAT>\n"
                . "                    </inv:homeCurrency>\n                  </inv:invoiceSummary>"
            : '';
        $ic = self::SUPPLIER_IC;
        $company = self::CZ_CUSTOMER;
        $companyIc = self::CZ_CUSTOMER_IC;

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
                    <inv:dateDue>{$dueDate}</inv:dateDue>
                    <inv:text>Tuzemské plnění</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>{$company}</typ:company>
                        <typ:ico>{$companyIc}</typ:ico>
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
                      <inv:rateVAT>{$enum}</inv:rateVAT>
                      {$percentXml}
                      <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  {$recapXml}
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }

    /**
     * Tuzemský ISDOC. `$percent = null` = řádek sazbu NEUVÁDÍ (mlčení),
     * `$vatApplicable = false` = doklad je nedaňový a nula je tam PROHLÁŠENÍ.
     */
    private function isdocDomestic(string $id, ?string $percent = '21', bool $vatApplicable = true): string
    {
        $percentXml = $percent !== null ? "<Percent>{$percent}</Percent>" : '';
        $applicable = $vatApplicable ? 'true' : 'false';
        $taxTotal = $vatApplicable && $percent !== null
            ? "<TaxTotal>\n                <TaxSubTotal>\n"
                . "                  <TaxableAmount>1000</TaxableAmount>\n"
                . "                  <TaxAmount>210</TaxAmount>\n"
                . "                  <TaxCategory><Percent>{$percent}</Percent></TaxCategory>\n"
                . "                </TaxSubTotal>\n              </TaxTotal>"
            : '';
        $ic = self::SUPPLIER_IC;
        $company = self::CZ_CUSTOMER;
        $companyIc = self::CZ_CUSTOMER_IC;
        $date = self::TAX_DATE;
        $due = self::DUE_DATE;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">
              <DocumentType>1</DocumentType>
              <ID>{$id}</ID>
              <IssueDate>{$date}</IssueDate>
              <TaxPointDate>{$date}</TaxPointDate>
              <VATApplicable>{$applicable}</VATApplicable>
              <LocalCurrencyCode>CZK</LocalCurrencyCode>
              <AccountingSupplierParty>
                <Party>
                  <PartyIdentification><ID>{$ic}</ID></PartyIdentification>
                  <PartyName><Name>Testovací dodavatel s.r.o.</Name></PartyName>
                  <PostalAddress>
                    <StreetName>Testovací</StreetName>
                    <BuildingNumber>1</BuildingNumber>
                    <CityName>Praha</CityName>
                    <PostalZone>11000</PostalZone>
                    <Country><IdentificationCode>CZ</IdentificationCode><Name>Česká republika</Name></Country>
                  </PostalAddress>
                </Party>
              </AccountingSupplierParty>
              <AccountingCustomerParty>
                <Party>
                  <PartyIdentification><ID>{$companyIc}</ID></PartyIdentification>
                  <PartyName><Name>{$company}</Name></PartyName>
                  <PostalAddress>
                    <StreetName>Testovací</StreetName>
                    <BuildingNumber>1</BuildingNumber>
                    <CityName>Praha</CityName>
                    <PostalZone>11000</PostalZone>
                    <Country><IdentificationCode>CZ</IdentificationCode><Name>Česká republika</Name></Country>
                  </PostalAddress>
                </Party>
              </AccountingCustomerParty>
              <InvoiceLines>
                <InvoiceLine>
                  <ID>1</ID>
                  <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
                  <LineExtensionAmount>1000</LineExtensionAmount>
                  <UnitPrice>1000</UnitPrice>
                  <ClassifiedTaxCategory>
                    {$percentXml}
                    <VATApplicable>{$applicable}</VATApplicable>
                  </ClassifiedTaxCategory>
                  <Item><Description>Konzultace</Description></Item>
                </InvoiceLine>
              </InvoiceLines>
              {$taxTotal}
              <LegalMonetaryTotal>
                <PayableAmount>1210</PayableAmount>
              </LegalMonetaryTotal>
              <PaymentMeans>
                <Payment>
                  <Details><PaymentDueDate>{$due}</PaymentDueDate></Details>
                </Payment>
              </PaymentMeans>
            </Invoice>
            XML;
    }
}
