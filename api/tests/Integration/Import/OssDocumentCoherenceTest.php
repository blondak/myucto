<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Oss\OssLedgerService;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Vat\VatRateResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SOUDRŽNOST DOKLADU A TICHÝ TUZEMSKÝ KVADRANT (§ H1–H3) — celý řetěz od XML po výkazy.
 *
 * Review naměřila dva stavy, ve kterých systém rozhodl správně a přesto se choval špatně,
 * protože o svém rozhodnutí mlčel:
 *
 *   PŘÍPAD A — jeden Pohoda XML, JEDEN polský spotřebitel bez DIČ, rekapitulace sedící na
 *   položky (tedy žádné `file_issues`), položka 23 % a položka 12 %. Naměřeno: status
 *   `created`, NULA varování. Doklad měl přitom jeden řádek v OSS podkladu (PL 240 / 55,20
 *   EUR) a druhý v českém přiznání (ř. 2 = 6 000 / 720 Kč). Obě rozhodnutí jsou z pohledu
 *   ŘÁDKU správná — 23 % v ČR neplatí a 12 % v Polsku neplatí — a deriver o ostatních
 *   řádcích téhož dokladu neví nic. Rozpor je vlastnost DOKLADU.
 *
 *   PŘÍPAD B — týž polský spotřebitel bez DIČ, dodavatel s AKTIVNÍ registrací do OSS,
 *   `percentVAT 21`. Naměřeno: `created`, nula varování, nula poznámek, nula OSS řádků,
 *   kód '1', ř. 1 = 6 000 / 1 260. Není to únik cizí daně (sazbu uvádí sám doklad
 *   a číselník ji v zemi dodavatele potvrdil), ale vnitřní rozpor, o kterém se uživatel
 *   nedozví.
 *
 * V OBOU případech se ROZHODNUTÍ nemění a test to tvrdí stejně silně jako to, že se o něm
 * uživatel doví: smíšený doklad umí vzniknout legitimně (plnění s místem plnění v tuzemsku
 * a zásilka do JČS na jedné faktuře) a přeznačkování by daň poslalo do jiného státu, než
 * kam patří. Tvrzení proto jdou přes SKUTEČNÝ {@see VatLedgerService},
 * {@see DphPriznaniBuilder} a {@see OssLedgerService} — sloupce v databázi vypadaly nevinně
 * i u původní chyby.
 *
 * Rozhodovací pravidla samotná drží rychlé unit testy
 * ({@see \MyInvoice\Tests\Unit\Service\Import\InvoiceImportDocumentCoherenceTest},
 * {@see \MyInvoice\Tests\Unit\Service\Oss\OssCrossBorderB2cContradictionTest}); tady jde
 * o to, že se pravidlo skutečně projeví v datech a ve výkazech.
 *
 * Data jsou syntetická (fiktivní firmy, rok 2096, kurzy nasazené do `exchange_rates`)
 * a všechno běží v transakci, kterou tearDown rollbackne.
 */
#[Group('integration')]
final class OssDocumentCoherenceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TAX_DATE    = '2096-05-15';
    private const YEAR        = 2096;
    private const MONTH       = 5;
    private const QUARTER     = 2;
    private const PERIOD_FROM = '2096-05-01';
    private const PERIOD_TO   = '2096-05-31';

    /** Kurzy ČNB k DUZP — 1 PLN = 6 Kč, 1 EUR = 25 Kč, tedy PLN→EUR přesně 0,24. */
    private const PLN_CZK = 6.0;
    private const EUR_CZK = 25.0;

    private const SUPPLIER_IC = '12345678';
    private const PL_CONSUMER = 'Testowy Odbiorca sp. z o.o.';
    private const CZ_CUSTOMER = 'Testovací odběratel s.r.o.';
    /** Spotřebitel ze státu, jehož základní sazba je shodná s českou (21 %). */
    private const NL_CONSUMER = 'Testverbruiker B.V.';

    private Connection $db;
    private InvoiceImportService $import;
    private OssLedgerService $oss;
    private VatLedgerService $vatLedger;
    private DphPriznaniBuilder $dph;
    private VatRateResolver $vatRates;

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
            $this->db        = $c->get(Connection::class);
            $this->import    = $c->get(InvoiceImportService::class);
            $this->oss       = $c->get(OssLedgerService::class);
            $this->vatLedger = $c->get(VatLedgerService::class);
            $this->dph       = $c->get(DphPriznaniBuilder::class);
            $this->vatRates  = $c->get(VatRateResolver::class);
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

        $this->czkId = $this->currency('CZK', 'Kč', 'Koruna česká', 'Czech koruna', isDefault: true);
        $this->currency('PLN', 'zł', 'Polský zlotý', 'Polish zloty');

        $pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::SUPPLIER_IC, $this->supplierId]);

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

    // ── PŘÍPAD A: jeden doklad ve dvou přiznáních ────────────────────────────

    /**
     * NAMĚŘENÝ PŘÍPAD A DOSLOVA — 23 % + 12 % na jednom dokladu jednoho polského
     * spotřebitele bez DIČ.
     *
     * Tvrdí se OBOJÍ najednou:
     *   1. doklad pořád leží v obou výkazech (rozhodnutí se nemění — smíšený doklad je
     *      výjimka, ne chyba, a odmítnutí by uživatele zavřelo do slepé uličky, protože
     *      cizí export nemá jak rozdělit);
     *   2. uživatel se o tom dozví — hlasitým varováním v reportu, příznakem u OBOU
     *      dotčených řádků v datech a varováním v náhledu OSS podání.
     */
    public function testMixedDocumentLiesInBothReturnsAndSaysSo(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->requireDomesticRate(12.0);

        $result = $this->importOne('smiseny-23-12.xml', $this->pohodaMixed('26FV0301', domesticPercent: 12));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_items']);
        self::assertSame(2, $result['oss_manual_review'],
            'Označí se OBĚ strany rozporu: OSS řádek je ta polovina, kterou uživatel uvidí '
                . 'v náhledu podání, tuzemský ta, kterou má prověřit.');

        // ── 1) Report o rozporu mlčet nesmí ─────────────────────────────────────
        // Doklad spouští OBĚ pravidla téhle vlny naráz a je to tak správně: § H1 mluví
        // o dokladu jako celku (leží ve dvou přiznáních), § H2 o tuzemském řádku
        // (přeshraniční B2C plnění za tuzemskou sazbu při aktivní registraci). Původní
        // stav byl přitom „created, NULA varování" — rekapitulace na položky SEDÍ, takže
        // ani parser neměl co hlásit.
        $warnings = $result['warnings'];
        self::assertCount(2, $warnings, implode(' | ', $warnings));
        $contradiction = implode("\n", array_filter($warnings, static fn (string $w): bool => str_contains($w, 'protiřečí')));
        self::assertStringContainsString('Doklad si protiřečí', $contradiction);
        self::assertStringContainsString('stát spotřeby PL', $contradiction);
        self::assertStringContainsString('sazbou 12 %', $contradiction);
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', $contradiction);
        self::assertStringContainsString('TUZEMSKOU sazbou', implode("\n", $warnings),
            'Tuzemská polovina rozporu je zároveň případ § H2 — mlčet nesmí ani sama za sebe.');

        // ── 2) Příznak je v DATECH, u obou zdaněných řádků ──────────────────────
        $invoiceId = (int) $result['invoice_id'];
        $rows = $this->itemRows($invoiceId);
        self::assertCount(2, $rows);
        self::assertSame(1, (int) $rows[0]['oss_applicable']);
        self::assertSame('PL', (string) $rows[0]['oss_consumer_country']);
        self::assertSame(0, (int) $rows[1]['oss_applicable']);
        self::assertSame('2', (string) $rows[1]['vat_classification_code'], 'tuzemsko, snížená sazba');
        foreach ($rows as $row) {
            self::assertSame(1, (int) $row['oss_needs_manual_review'],
                'Report je jednorázová stránka — po jejím zavření musí jít řádky dohledat v datech.');
        }

        // ── 3) Doklad SKUTEČNĚ leží v obou výkazech ─────────────────────────────
        self::assertContains($invoiceId, $this->saleInvoiceIdsInVatLedger(),
            'Varování NENÍ změna rozhodnutí — tuzemský řádek se z evidence DPH ztratit nesmí.');
        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertArrayHasKey('2', $dph['summary']['lines']);
        self::assertEqualsWithDelta(6000.0, $dph['summary']['lines']['2']['base'], 0.01,
            'Naměřený stav: ř. 2 = základ 6 000 Kč (1 000 PLN kurzem 6 Kč).');
        self::assertEqualsWithDelta(720.0, $dph['summary']['lines']['2']['vat'], 0.01);
        self::assertArrayNotHasKey('1', $dph['summary']['lines'], 'OSS řádek do přiznání nepatří.');

        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        $pl = $this->ossCountry($preview, 'PL');
        self::assertEqualsWithDelta(240.0, $pl['base'], 0.01, 'Základ 1 000 PLN kurzem 0,24 = 240 EUR.');
        self::assertEqualsWithDelta(55.2, $pl['vat'], 0.01);

        // ── 4) § H3 — poslední obrazovka před odesláním podání o tom ví ─────────
        $manual = $this->manualReviewWarnings($preview);
        self::assertCount(1, $manual, 'Náhled podání musí příznak z položky přečíst, jinak je to jednorázová hláška.');
        self::assertStringContainsString('1 řádků', $manual[0], 'Náhled vidí jen OSS polovinu rozporu.');
    }

    /**
     * TÁŽ KOMBINACE SE ZÁKLADNÍ SAZBOU — 23 % + 21 %, naměřeno jako ř. 1 = 6 000 / 1 260
     * a zároveň OSS PL 240 / 55,20.
     *
     * Nápadnější varianta téhož: doklad odvádí polskou daň v OSS a českou na ř. 1 přiznání.
     * Kdyby se rozpor hlásil jen u snížené sazby, zůstala by právě tahle tichá.
     */
    public function testMixedDocumentWithTheDomesticStandardRateIsFlaggedToo(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->requireDomesticRate(21.0);

        $result = $this->importOne('smiseny-23-21.xml', $this->pohodaMixed('26FV0302', domesticPercent: 21));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_items']);
        self::assertSame(2, $result['oss_manual_review']);
        self::assertStringContainsString('sazbou 21 %', implode("\n", $result['warnings']));
        self::assertSame([1, 1], array_map(
            static fn (array $r): int => (int) $r['oss_needs_manual_review'],
            $this->itemRows((int) $result['invoice_id']),
        ));

        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(6000.0, $dph['summary']['lines']['1']['base'], 0.01);
        self::assertEqualsWithDelta(1260.0, $dph['summary']['lines']['1']['vat'], 0.01);

        $pl = $this->ossCountry($this->oss->preview($this->supplierId, self::YEAR, self::QUARTER), 'PL');
        self::assertEqualsWithDelta(240.0, $pl['base'], 0.01);
        self::assertEqualsWithDelta(55.2, $pl['vat'], 0.01);
    }

    /**
     * PROTIPÓL PŘÍPADU A: druhý řádek je OSVOBOZENÝ (0 % — poštovné). Rozpor to není,
     * protože osvobozené plnění se vykazuje BEZ DANĚ, takže tu proti dani odvedené ve
     * státě spotřeby nestojí žádná daň tuzemská.
     *
     * Bez téhle výjimky by varování dostala skoro každá OSS faktura — nulový řádek
     * (zaokrouhlení, poštovné, sleva) nese kdejaký doklad — a hláška by u migrace
     * 1 670 dokladů okamžitě zevšedněla.
     */
    public function testZeroRatedSecondLineIsNotAContradiction(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->requireDomesticRate(0.0);

        $result = $this->importOne('oss-plus-postovne.xml', $this->pohodaOssWithExemptLine('26FV0303'));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_items']);
        self::assertSame(0, $result['oss_manual_review'], 'Osvobozený řádek není druhé přiznání.');
        self::assertStringNotContainsString('protiřečí', implode("\n", $result['warnings']));

        $rows = $this->itemRows((int) $result['invoice_id']);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame(0, (int) $row['oss_needs_manual_review']);
        }

        self::assertSame([], $this->manualReviewWarnings(
            $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER)
        ), 'Náhled podání nemá o čem mluvit.');
    }

    /**
     * REGRESE BĚŽNÉHO PROVOZU: čistě tuzemský doklad pro českého odběratele — dvě zdaněné
     * sazby i osvobozený řádek — se nesmí hnout ani po zapnutí OSS u firmy.
     *
     * Falešný poplach je tu dražší než mlčení: uživatel, kterému kontrola hlásí rozpor na
     * každé druhé faktuře, ji přestane číst dřív, než dojde k té jedné, kde o něco jde.
     */
    public function testPurelyDomesticInvoiceIsCompletelyUnaffected(): void
    {
        $this->enableOss();
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');
        $this->requireDomesticRate(21.0);
        $this->requireDomesticRate(12.0);
        $this->requireDomesticRate(0.0);

        $result = $this->importOne('tuzemsko.xml', $this->pohodaDomesticThreeRates('26FV0304'));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(0, $result['oss_items']);
        self::assertSame(0, $result['oss_manual_review']);
        self::assertSame([], $result['warnings'], 'Tuzemský doklad nemá co hlásit.');

        $invoiceId = (int) $result['invoice_id'];
        foreach ($this->itemRows($invoiceId) as $row) {
            self::assertSame(0, (int) $row['oss_applicable']);
            self::assertSame(0, (int) $row['oss_needs_manual_review']);
        }

        self::assertContains($invoiceId, $this->saleInvoiceIdsInVatLedger());
        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'], 0.01);
        self::assertEqualsWithDelta(210.0, $dph['summary']['lines']['1']['vat'], 0.01);
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['2']['base'], 0.01);
        self::assertEqualsWithDelta(120.0, $dph['summary']['lines']['2']['vat'], 0.01);

        self::assertSame([], $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER)['countries'],
            'Tuzemský doklad do OSS podkladu nepatří.');
    }

    // ── PŘÍPAD B: tuzemská sazba na přeshraničním B2C plnění ─────────────────

    /**
     * NAMĚŘENÝ PŘÍPAD B DOSLOVA — polský spotřebitel bez DIČ, AKTIVNÍ registrace do OSS,
     * `percentVAT 21`. Naměřeno: `created`, nula varování, nula poznámek, nula OSS řádků,
     * kód '1', ř. 1 = 6 000 / 1 260.
     *
     * Řádek ZŮSTÁVÁ tuzemský — sazbu uvádí sám doklad, registrace do OSS je dobrovolná
     * a plnění pod prahem § 8/3 tuzemské opravdu být může. Test proto tvrdí, že se ř. 1
     * nezměnil ani o korunu, a vedle toho, že doklad dostal varování i příznak.
     */
    public function testDomesticRateForEuConsumerWithActiveRegistrationStaysOnLine1ButIsFlagged(): void
    {
        $this->enableOss();
        $this->client(self::PL_CONSUMER, 'PL');
        $this->requireDomesticRate(21.0);

        $result = $this->importOne('pl-spotrebitel-21.xml', $this->pohodaForeignSingleRate('26FV0311', 21));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(0, $result['oss_items'], 'Rozhodnutí se nemění — řádek zůstává tuzemský.');
        self::assertSame(1, $result['oss_manual_review']);

        $warnings = implode("\n", $result['warnings']);
        self::assertStringContainsString('TUZEMSKOU sazbou', $warnings);
        self::assertStringContainsString('aktivní registraci do OSS', $warnings);
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', $warnings);

        $invoiceId = (int) $result['invoice_id'];
        $item = $this->itemRow($invoiceId);
        self::assertSame(0, (int) $item['oss_applicable']);
        self::assertSame('1', (string) $item['vat_classification_code']);
        self::assertSame(1, (int) $item['oss_needs_manual_review'],
            'Příznak na TUZEMSKÉM řádku je jediná stopa, která po zavření reportu zbude.');

        // Řádek se z přiznání ztratit nesmí — to by byla druhá chyba místo první.
        self::assertContains($invoiceId, $this->saleInvoiceIdsInVatLedger());
        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(6000.0, $dph['summary']['lines']['1']['base'], 0.01,
            'Naměřený stav: ř. 1 = 6 000 / 1 260. Varování ho nesmí změnit.');
        self::assertEqualsWithDelta(1260.0, $dph['summary']['lines']['1']['vat'], 0.01);

        // A do OSS podkladu se doklad nedostal — příznak na tuzemském řádku je tedy
        // dohledatelný jen v datech a v reportu, ne v náhledu podání (ten čte výhradně
        // řádky s `oss_applicable = 1`). Je to vědomá hranice § H3, ne opomenutí.
        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        self::assertSame([], $preview['countries']);
    }

    /**
     * PROTIPÓL PŘÍPADU B: TÝŽ doklad u firmy, která pro dané období registraci do OSS
     * NEMÁ. Pak je tuzemská sazba na přeshraničním plnění úplně normální (plnění pod
     * prahem § 8/3 se vykazuje v tuzemsku) a systém nesmí říct ani slovo.
     *
     * Bez tohohle případu by kontrola mohla „projít" i v podobě, která otravuje u každého
     * zahraničního dokladu — a u migrace 1 670 dokladů je to totéž jako nemít ji.
     */
    public function testSameDocumentWithoutAnActiveOssRegistrationIsSilent(): void
    {
        // enableOss() se schválně NEVOLÁ — klon dodavatele má OSS vypnutý.
        $this->client(self::PL_CONSUMER, 'PL');
        $this->requireDomesticRate(21.0);
        self::assertSame(
            0,
            (int) $this->db->pdo()->query("SELECT oss_enabled FROM supplier WHERE id = {$this->supplierId}")->fetchColumn(),
            'Předpoklad testu: firma registraci do OSS nemá.',
        );

        $result = $this->importOne('pl-spotrebitel-21-bez-oss.xml', $this->pohodaForeignSingleRate('26FV0312', 21));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(0, $result['oss_items']);
        self::assertSame(0, $result['oss_manual_review']);
        self::assertSame([], $result['warnings'], 'Bez registrace do OSS není co zpochybňovat.');

        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertSame(0, (int) $item['oss_needs_manual_review']);
        self::assertSame('1', (string) $item['vat_classification_code']);

        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(6000.0, $dph['summary']['lines']['1']['base'], 0.01);
    }

    /**
     * Druhý protipól téhož: registrace EXISTUJE, ale doklad je mimo její platnost.
     * Platnost se porovnává jako řetězec, takže je to jiná větev než „vypnutý přepínač".
     */
    public function testDocumentOutsideTheRegistrationValidityIsSilent(): void
    {
        $this->enableOss(validFrom: '2096-07-01');
        $this->client(self::PL_CONSUMER, 'PL');
        $this->requireDomesticRate(21.0);

        $result = $this->importOne('pl-pred-registraci.xml', $this->pohodaForeignSingleRate('26FV0313', 21));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(0, $result['oss_manual_review']);
        self::assertSame([], $result['warnings']);
        self::assertSame(0, (int) $this->itemRow((int) $result['invoice_id'])['oss_needs_manual_review']);
    }

    // ── § H3: náhled podání počítá řádky k ručnímu posouzení ─────────────────

    /**
     * § H3 — příznak musí být vidět i po zavření reportu importu, a to na POSLEDNÍ
     * obrazovce před odesláním podání.
     *
     * `oss_needs_manual_review` nečetl do téhle vlny nikdo: ani náhled OSS, ani přiznání
     * k DPH. Kategorie „tady je místo plnění sporné" tím žila jen na stránce reportu.
     *
     * Počet se ověřuje nad DVĚMA doklady z různých větví (smíšený doklad § H1 a doklad,
     * jehož sazba platí v obou zemích), aby se dalo tvrdit, že se čísla SČÍTAJÍ — u jednoho
     * dokladu by prošla i natvrdo napsaná jednička. Varování je přitom JEDNO za období:
     * seznam 1 670 dokladů by v náhledu utopil všechna ostatní varování.
     */
    public function testReturnPreviewCountsRowsWaitingForManualReview(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->vatRate('NL', 21.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::NL_CONSUMER, 'NL');
        $this->requireDomesticRate(12.0);

        // Kontrolní měření PŘED importem: náhled o ručním posouzení zatím mlčí, takže
        // tvrzení níže nedokazuje jen to, že text ve zdrojáku existuje.
        self::assertSame([], $this->manualReviewWarnings(
            $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER)
        ));

        $out = $this->import->importBundle(
            [
                // § H1 — smíšený doklad: OSS řádek dostane příznak z kontroly soudržnosti.
                ['name' => 'smiseny.xml', 'content' => $this->pohodaMixed('26FV0321', domesticPercent: 12)],
                // § D1b — 21 % platí v NL i v ČR, místo plnění z procenta neplyne.
                ['name' => 'nl-21.xml', 'content' => $this->pohodaForeignSingleRate(
                    '26FV0322',
                    21,
                    company: self::NL_CONSUMER,
                    countryIso2: 'NL',
                )],
            ],
            $this->supplierId,
            $this->userId,
            'issued',
        );
        self::assertSame(2, $out['summary']['created'], implode(' | ', array_column($out['results'], 'reason')));

        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        $manual = $this->manualReviewWarnings($preview);

        self::assertCount(1, $manual, 'Jedno varování za období, ne za doklad.');
        self::assertStringContainsString('2 řádků', $manual[0],
            'Počítají se OSS řádky obou dokladů — u jednoho by prošla i konstanta.');
        self::assertStringContainsString('dřív, než podání odešlete', $manual[0],
            'Hláška musí říct, kdy to má uživatel udělat — náhled je poslední obrazovka před podáním.');
    }

    // ── tvrzení a fixtures ───────────────────────────────────────────────────

    /**
     * Varování náhledu podání o řádcích k ručnímu posouzení.
     *
     * @param  array<string,mixed> $preview
     * @return list<string>
     */
    private function manualReviewWarnings(array $preview): array
    {
        return array_values(array_filter(
            $preview['warnings'],
            static fn (string $w): bool => str_contains($w, 'RUČNÍ POSOUZENÍ'),
        ));
    }

    /** @return list<int> */
    private function saleInvoiceIdsInVatLedger(): array
    {
        $ids = [];
        foreach ($this->vatLedger->rows($this->supplierId, self::PERIOD_FROM, self::PERIOD_TO) as $row) {
            if ($row['source'] === 'sale') {
                $ids[] = (int) $row['invoice_id'];
            }
        }

        return array_values(array_unique($ids));
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
     * Tuzemská sazba MUSÍ být na instalaci nalezitelná, jinak by se doklad odmítl na
     * párování `vat_rate_id` — a test by zeleně tvrdil něco o odmítnutí místo o rozporu.
     */
    private function requireDomesticRate(float $percent): void
    {
        if (!$this->vatRates->resolve($this->domesticCountry(), $percent, self::TAX_DATE)->found()) {
            self::markTestSkipped(sprintf(
                'Instalace nemá tuzemskou sazbu %s %% k %s — doklad by spadl na párování sazby.',
                rtrim(rtrim(number_format($percent, 2, ',', ' '), '0'), ','),
                self::TAX_DATE,
            ));
        }
    }

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
        $rows = $this->itemRows($invoiceId);
        self::assertCount(1, $rows, 'Doklad má mít právě jednu položku.');

        return $rows[0];
    }

    /** @return list<array<string,mixed>> */
    private function itemRows(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.*, vr.country AS rate_country, vr.rate_percent AS rate_percent
               FROM invoice_items ii
               JOIN vat_rates vr ON vr.id = ii.vat_rate_id
              WHERE ii.invoice_id = ?
           ORDER BY ii.order_index, ii.id'
        );
        $stmt->execute([$invoiceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function enableOss(string $validFrom = '2096-01-01'): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET oss_enabled = 1,
                    oss_valid_from = ?,
                    oss_valid_to = NULL,
                    oss_identification_country = 'CZ',
                    oss_return_currency = 'EUR'
              WHERE id = ?"
        )->execute([$validFrom, $this->supplierId]);
    }

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
     * Sazba pro stát spotřeby v `vat_rates` — zakládá ji TEST, protože `vat_rates` je
     * globální tabulka bez `supplier_id` a import do ní zapisovat nesmí.
     */
    private function vatRate(string $country, float $percent): void
    {
        $code = strtoupper($country) . '-' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        $this->db->pdo()->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default,
                                    is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, 0, 0, ?, NULL, 900)
             ON DUPLICATE KEY UPDATE rate_percent = VALUES(rate_percent), country = VALUES(country),
                                     valid_from = VALUES(valid_from), valid_to = VALUES(valid_to)'
        )->execute([$code, $percent, strtoupper($country), $code, $code, '2090-01-01']);
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
    private function client(string $name, string $iso2, ?string $ic = null, ?string $dic = null): int
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
             VALUES (?, ?, ?, ?, "Testovací 1", "Město", "11000", ?, "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $name, $ic, $dic, $countryId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * NAMĚŘENÝ DOKLAD PŘÍPADU A: jeden polský spotřebitel, dva zdaněné řádky po 1 000 PLN —
     * 23 % (v ČR neplatí → OSS sekce PL) a tuzemská sazba (v Polsku neplatí → přiznání
     * k DPH). Rekapitulace na položky SEDÍ, takže parser nemá co hlásit a doklad prochází
     * bez jediného `file_issue` — právě proto byl původní stav „created, nula varování".
     */
    private function pohodaMixed(string $number, int $domesticPercent): string
    {
        $domesticLevel = $domesticPercent >= 21 ? 'high' : 'low';
        $domesticBucket = $domesticPercent >= 21 ? 'High' : 'Low';
        $domesticVat = number_format(1000 * $domesticPercent / 100, 2, '.', '');
        // Obě přihrádky nesou @rate ze SOUBORU, takže se dopočet nemá o co přichytávat
        // (česká kotva je na cizoměnovém dokladu vypnutá, § H4).
        $ossBucket = $domesticBucket === 'High'
            ? '<typ:priceLow>1000.00</typ:priceLow><typ:priceLowVAT rate="23">230.00</typ:priceLowVAT>'
            : '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT rate="23">230.00</typ:priceHighVAT>';
        $domesticBucketXml = sprintf(
            '<typ:price%1$s>1000.00</typ:price%1$s><typ:price%1$sVAT rate="%2$d">%3$s</typ:price%1$sVAT>',
            $domesticBucket === 'High' ? 'High' : 'Low',
            $domesticPercent,
            $domesticVat,
        );
        // Přihrádka OSS řádku musí být JINÁ než přihrádka tuzemského, jinak by se obě
        // sazby sečetly do jedné a rekapitulace by si s položkami neodpovídala.
        $buckets = $domesticBucket === 'High'
            ? $domesticBucketXml . $ossBucket
            : $ossBucket . $domesticBucketXml;
        $ossLevel = $domesticBucket === 'High' ? 'low' : 'high';

        return $this->pohodaEnvelope($number, <<<XML
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Zboží do Polska</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>{$ossLevel}</inv:rateVAT>
                      <inv:percentVAT>23</inv:percentVAT>
                      <inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>
                    </inv:invoiceItem>
                    <inv:invoiceItem>
                      <inv:text>Doprava</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>{$domesticLevel}</inv:rateVAT>
                      <inv:percentVAT>{$domesticPercent}</inv:percentVAT>
                      <inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
            XML, $buckets);
    }

    /**
     * PROTIPÓL PŘÍPADU A: OSS řádek 23 % + OSVOBOZENÝ řádek (poštovné, `rateVAT none`).
     * Nulová sazba se do rekapitulace nepromítá, takže si doklad neodporuje.
     */
    private function pohodaOssWithExemptLine(string $number): string
    {
        return $this->pohodaEnvelope($number, <<<'XML'
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
                      <inv:text>Poštovné</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>none</inv:rateVAT>
                      <inv:foreignCurrency><typ:unitPrice>100</typ:unitPrice></inv:foreignCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
            XML, '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT rate="23">230.00</typ:priceHighVAT>');
    }

    /**
     * NAMĚŘENÝ DOKLAD PŘÍPADU B: doklad v PLN o jediném řádku s TUZEMSKOU sazbou pro
     * zahraničního spotřebitele bez DIČ. Kurz 6 Kč/PLN dělá ze základu 1 000 PLN přesně
     * 6 000 Kč, tedy naměřený ř. 1.
     */
    private function pohodaForeignSingleRate(
        string $number,
        int $percent,
        string $company = self::PL_CONSUMER,
        string $countryIso2 = 'PL',
    ): string {
        $level = $percent >= 21 ? 'high' : 'low';
        $bucket = $percent >= 21 ? 'High' : 'Low';
        $vat = number_format(1000 * $percent / 100, 2, '.', '');

        return $this->pohodaEnvelope($number, <<<XML
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Zboží spotřebiteli</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>{$level}</inv:rateVAT>
                      <inv:percentVAT>{$percent}</inv:percentVAT>
                      <inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
            XML, sprintf(
                '<typ:price%1$s>1000.00</typ:price%1$s><typ:price%1$sVAT rate="%2$d">%3$s</typ:price%1$sVAT>',
                $bucket,
                $percent,
                $vat,
            ), $company, $countryIso2);
    }

    /** Běžný tuzemský doklad se třemi sazbami — kontrolní vzorek nedotčeného provozu. */
    private function pohodaDomesticThreeRates(string $number): string
    {
        $detail = <<<'XML'
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Konzultace</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>high</inv:rateVAT>
                      <inv:percentVAT>21</inv:percentVAT>
                      <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                    <inv:invoiceItem>
                      <inv:text>Tiskoviny</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>low</inv:rateVAT>
                      <inv:percentVAT>12</inv:percentVAT>
                      <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                    <inv:invoiceItem>
                      <inv:text>Zaokrouhlení</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>none</inv:rateVAT>
                      <inv:homeCurrency><typ:unitPrice>1</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
            XML;

        return $this->pohodaEnvelope(
            $number,
            $detail,
            '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT rate="21">210.00</typ:priceHighVAT>'
                . '<typ:priceLow>1000.00</typ:priceLow><typ:priceLowVAT rate="12">120.00</typ:priceLowVAT>',
            self::CZ_CUSTOMER,
            'CZ',
            ic: '25596641',
            foreign: false,
        );
    }

    /**
     * Obálka Pohoda XML — hlavička, protistrana a rekapitulace. Doklad v PLN používá
     * `foreignCurrency` s kurzem 6 Kč/PLN, tuzemský `homeCurrency`.
     */
    private function pohodaEnvelope(
        string $number,
        string $detailXml,
        string $buckets,
        string $company = self::PL_CONSUMER,
        string $countryIso2 = 'PL',
        string $ic = '',
        bool $foreign = true,
    ): string {
        $ico = self::SUPPLIER_IC;
        $icoXml = $ic !== '' ? "<typ:ico>{$ic}</typ:ico>" : '';
        $summary = $foreign
            ? '<inv:invoiceSummary><inv:foreignCurrency>'
                . '<typ:currency><typ:ids>PLN</typ:ids></typ:currency>'
                . '<typ:rate>6</typ:rate><typ:amount>1</typ:amount>'
                . $buckets
                . '</inv:foreignCurrency></inv:invoiceSummary>'
            : '<inv:invoiceSummary><inv:homeCurrency>' . $buckets . '</inv:homeCurrency></inv:invoiceSummary>';
        $taxDate = self::TAX_DATE;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                          version="2.0" ico="{$ico}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>issuedInvoice</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$number}</inv:symVar>
                    <inv:date>{$taxDate}</inv:date>
                    <inv:dateTax>{$taxDate}</inv:dateTax>
                    <inv:dateDue>2096-06-15</inv:dateDue>
                    <inv:text>Prodej spotřebiteli</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>{$company}</typ:company>
                        {$icoXml}
                        <typ:street>Testovací 1</typ:street>
                        <typ:city>Město</typ:city>
                        <typ:zip>11000</typ:zip>
                        <typ:country><typ:ids>{$countryIso2}</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
            {$detailXml}
                  {$summary}
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }
}
