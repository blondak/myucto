<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Oss;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Nejistý řádek („nevím", `oss_applicable = 0` + `oss_needs_manual_review = 1`) musí jít
 * NAJÍT.
 *
 * Vzniká, když kanál doklad zahodit nesmí (cron opakovaných faktur, iDoklad, Fakturoid,
 * AI extrakce, API bez OSS klíčů) a číselník členských států sazbu v zemi dodavatele
 * nepotvrdil. Řádek zůstane mimo OSS a nejistota se uloží k položce — jenže tím se stane
 * neviditelným: v OSS podání není (a být nemá, OSS řádek to není), v seznamu faktur
 * vypadá jako každý jiný, a přitom tiše vstupuje na ř. 1/2 tuzemského přiznání.
 *
 * Test drží obě cesty, kterými se k němu uživatel dostane, a zároveň to, že v OSS podání
 * NENÍ.
 */
#[Group('integration')]
final class OssUncertainRowsVisibilityTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private VatLedgerService $ledger;
    private InvoiceRepository $invoices;
    private DphPriznaniBuilder $dph;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private int $rateId = 0;
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
            $this->ledger   = $c->get(VatLedgerService::class);
            $this->invoices = $c->get(InvoiceRepository::class);
            $this->dph      = $c->get(DphPriznaniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('invoice_items', 'oss_needs_manual_review')) {
            $this->markTestSkipped('Chybí migrace 1293 (oss_needs_manual_review).');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czkId  = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn() ?: 0);
        $this->rateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0 || $this->czkId === 0 || $this->rateId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users / měna CZK / sazba DPH).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
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
     * Varování v přiznání k DPH. Poslední brána před odesláním na EPO: řádek na ř. 1/2
     * je, ale systém sám ví, že si jím není jistý — a nesmí to zamlčet.
     */
    public function testUncertainRowIsReportedForTheVatReturn(): void
    {
        $client = $this->client('CZ');
        $uncertain = $this->sale($client, '2099-04-10', 1000.0, 23.0, applicable: 0, needsReview: 1);
        $this->sale($client, '2099-04-11', 500.0, 21.0, applicable: 0, needsReview: 0);

        $docs = $this->ledger->uncertainOssDocuments($this->supplierId, '2099-04-01', '2099-04-30');

        self::assertCount(1, $docs, 'Hlásit se má jen řádek s příznakem k ručnímu posouzení.');
        self::assertSame($uncertain, $docs[0]['invoice_id']);
        self::assertSame(1, $docs[0]['items']);
    }

    /**
     * Doklad mimo vykazované období ani stornovaný doklad varovat nesmí — filtry jsou
     * ZÁMĚRNĚ tytéž jako ve `fetchSales()`, jinak by uživatel dostal šum o dokladech,
     * které v přiznání vůbec nejsou.
     */
    public function testWarningFollowsTheSameScopeAsTheVatReturnItself(): void
    {
        $client = $this->client('CZ');
        $this->sale($client, '2099-05-10', 1000.0, 23.0, applicable: 0, needsReview: 1);              // jiné období
        $this->sale($client, '2099-04-10', 1000.0, 23.0, applicable: 0, needsReview: 1, status: 'cancelled');
        $this->sale($client, '2099-04-10', 1000.0, 23.0, applicable: 0, needsReview: 1, status: 'draft');
        $this->sale($client, '2099-04-10', 1000.0, 23.0, applicable: 0, needsReview: 1, type: 'proforma');

        self::assertSame([], $this->ledger->uncertainOssDocuments($this->supplierId, '2099-04-01', '2099-04-30'));
    }

    /**
     * Řádek, který je OSS, se za nejistý nepovažuje ani tehdy, když má příznak k ručnímu
     * posouzení — ten na OSS řádku znamená „prověř parametry", ne „nevím, kam patří".
     * Do tuzemského přiznání nevstupuje, takže tam nemá o čem varovat.
     */
    public function testOssRowWithReviewFlagIsNotReportedAsUncertain(): void
    {
        $client = $this->client('PL');
        $this->sale($client, '2099-04-10', 1000.0, 23.0, applicable: 1, needsReview: 1, consumerCountry: 'PL');

        self::assertSame([], $this->ledger->uncertainOssDocuments($this->supplierId, '2099-04-01', '2099-04-30'));
    }

    /**
     * Celá cesta až do přiznání: varování musí být mezi `warnings` sestaveného DPHDP3,
     * ne jen v podkladovém dotazu. Přesně tady se rozhoduje, jestli se to uživatel
     * dozví před odesláním na EPO.
     */
    public function testVatReturnCarriesTheWarningToTheUser(): void
    {
        $client = $this->client('CZ');
        $this->sale($client, '2099-04-10', 1000.0, 23.0, applicable: 0, needsReview: 1);

        $warnings = implode("\n", $this->dph->build($this->supplierId, 2099, 4, 'monthly')['warnings']);

        self::assertStringContainsString('nepodařilo určit místo plnění', $warnings);
        self::assertStringContainsString('Nejisté místo plnění (OSS)', $warnings,
            'Varování musí pojmenovat filtr, kterým se ty řádky najdou — jinak je uživatel nemá kde hledat.');
    }

    /**
     * Filtr v seznamu faktur — druhá cesta k témuž. Varování v přiznání říká, ŽE něco je;
     * teprve seznam je místo, kde se s tím dá něco udělat (otevřít doklad, hromadná
     * editace OSS).
     */
    public function testInvoiceListCanFilterUncertainDocuments(): void
    {
        $client = $this->client('CZ');
        $uncertain = $this->sale($client, '2099-04-10', 1000.0, 23.0, applicable: 0, needsReview: 1);
        $this->sale($client, '2099-04-11', 500.0, 21.0, applicable: 0, needsReview: 0);

        $all = $this->listIds(['supplier_id' => $this->supplierId, 'year' => 2099]);
        self::assertCount(2, $all, 'Kontrola vzorku: bez filtru jsou vidět oba doklady.');

        $filtered = $this->listIds(['supplier_id' => $this->supplierId, 'year' => 2099, 'oss_review' => true]);
        self::assertSame([$uncertain], $filtered);
    }

    /**
     * Nejistý řádek do OSS podání NEPATŘÍ — OSS řádkem prokazatelně není. Kdyby ho tam
     * filtr přitáhl, odvedla by se cizí daň z plnění, o kterém nikdo neví, že do JČS
     * patří. Zůstává tedy v tuzemském přiznání a řeší se varováním a filtrem výše.
     */
    public function testUncertainRowStaysOutOfTheOssFiling(): void
    {
        $client = $this->client('CZ');
        $this->sale($client, '2099-04-10', 1000.0, 23.0, applicable: 0, needsReview: 1);

        self::assertSame([], $this->ledger->ossRows($this->supplierId, '2099-04-01', '2099-04-30'));
    }

    /**
     * Filtr musí pokrýt OBA konce nejistoty, ne jen ten tuzemský.
     *
     * Řádek, který se do OSS ZAŘADIL a přitom je označený k posouzení (nejednoznačná
     * sazba — 21 % platí v ČR i v NL/BE/ES/LT/LV — nebo doklad rozpadlý mezi OSS
     * a tuzemské přiznání), byl vidět jen ve varování náhledu podání a v reportu importu,
     * který po zavření stránky zmizí. Uživatel si filtr projel, vyčistil ho a v dobré víře
     * mu zůstala druhá polovina sporných řádků.
     */
    public function testInvoiceListFilterCoversBothEndsOfTheUncertainty(): void
    {
        $czClient = $this->client('CZ');
        $plClient = $this->client('PL');
        $domestic = $this->sale($czClient, '2099-04-10', 1000.0, 23.0, applicable: 0, needsReview: 1);
        $inOss = $this->sale($plClient, '2099-04-11', 800.0, 21.0, applicable: 1, needsReview: 1, consumerCountry: 'PL');
        $this->sale($czClient, '2099-04-12', 500.0, 21.0, applicable: 0, needsReview: 0);
        $this->sale($plClient, '2099-04-13', 400.0, 23.0, applicable: 1, needsReview: 0, consumerCountry: 'PL');

        $base = ['supplier_id' => $this->supplierId, 'year' => 2099];

        self::assertCount(4, $this->listIds($base), 'Kontrola vzorku: bez filtru jsou vidět všechny doklady.');

        $both = [$domestic, $inOss];
        sort($both);
        self::assertSame($both, $this->listIds($base + ['oss_review' => 'any']),
            'Rozsah „vše nejisté" musí vrátit obě větve — jinak si uživatel vyčistí filtr s polovinou práce.');
        self::assertSame([$inOss], $this->listIds($base + ['oss_review' => 'oss']));
        self::assertSame([$domestic], $this->listIds($base + ['oss_review' => 'domestic']));

        // Uložené filtry a starší odkazy nesou `1`. Musí se rozšířit na obě větve,
        // ne zůstat u původní poloviny.
        self::assertSame($both, $this->listIds($base + ['oss_review' => '1']));
        self::assertSame($both, $this->listIds($base + ['oss_review' => true]));
    }

    /**
     * Rozlišení v datech: „vše nejisté" nesmí být nerozlišený seznam. Každý konec se
     * řeší jinak — řádek v OSS znamená „zkontroluj zemi a typ sazby", řádek v tuzemsku
     * „zkontroluj, jestli tam vůbec patří" — a UI to bez těchhle příznaků neumí říct.
     */
    public function testInvoiceListTellsWhichEndOfTheUncertaintyEachDocumentCarries(): void
    {
        $czClient = $this->client('CZ');
        $plClient = $this->client('PL');
        $domestic = $this->sale($czClient, '2099-04-10', 1000.0, 23.0, applicable: 0, needsReview: 1);
        $inOss = $this->sale($plClient, '2099-04-11', 800.0, 21.0, applicable: 1, needsReview: 1, consumerCountry: 'PL');
        $clean = $this->sale($czClient, '2099-04-12', 500.0, 21.0, applicable: 0, needsReview: 0);

        $rows = $this->listRows(['supplier_id' => $this->supplierId, 'year' => 2099]);

        self::assertArrayHasKey('oss_review_domestic', $rows[$domestic],
            'Seznam musí příznak vracet, jinak ho UI nemá z čeho vykreslit.');
        self::assertTrue($rows[$domestic]['oss_review_domestic']);
        self::assertFalse($rows[$domestic]['oss_review_oss']);

        self::assertTrue($rows[$inOss]['oss_review_oss']);
        self::assertFalse($rows[$inOss]['oss_review_domestic']);

        self::assertFalse($rows[$clean]['oss_review_oss']);
        self::assertFalse($rows[$clean]['oss_review_domestic']);
    }

    /**
     * Doklad rozpadlý mezi OSS a tuzemské přiznání nese OBA otazníky najednou — takový
     * je celý smysl kontroly soudržnosti dokladu. Musí ho proto najít každý ze tří
     * rozsahů a nést oba příznaky, ne jen ten, který se v řádcích potkal první.
     */
    public function testDocumentSplitBetweenOssAndDomesticCarriesBothFlags(): void
    {
        $plClient = $this->client('PL');
        $split = $this->sale($plClient, '2099-04-10', 1000.0, 21.0, applicable: 0, needsReview: 1);
        $this->addItem($split, 700.0, 21.0, applicable: 1, needsReview: 1, consumerCountry: 'PL');

        $base = ['supplier_id' => $this->supplierId, 'year' => 2099];
        foreach (['any', 'oss', 'domestic'] as $scope) {
            self::assertSame([$split], $this->listIds($base + ['oss_review' => $scope]),
                'Rozpadlý doklad musí být vidět v rozsahu ' . $scope . '.');
        }

        $row = $this->listRows($base)[$split];
        self::assertTrue($row['oss_review_oss']);
        self::assertTrue($row['oss_review_domestic']);
    }

    /** @param array<string,mixed> $filters @return list<int> */
    private function listIds(array $filters): array
    {
        return array_keys($this->listRows($filters));
    }

    /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> id → řádek */
    private function listRows(array $filters): array
    {
        $result = $this->invoices->listGroupedByMonth($filters, 1, 100);
        $rows = [];
        foreach ($result['data'] as $group) {
            foreach ($group['invoices'] as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }
        ksort($rows);

        return $rows;
    }

    private function sale(
        int $clientId,
        string $taxDate,
        float $base,
        float $rate,
        int $applicable,
        int $needsReview,
        string $status = 'issued',
        string $type = 'invoice',
        ?string $consumerCountry = null,
    ): int {
        $pdo = $this->db->pdo();
        $vat = round($base * $rate / 100.0, 2);
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date,
                 due_date, currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", "{}", ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $clientId,
            substr(md5($taxDate . $base . $rate . $status . $type . $applicable . $needsReview), 0, 10),
            $type, $taxDate, $taxDate, $taxDate, $this->czkId,
            $base, $vat, $base + $vat, $status, $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();
        $this->addItem($invoiceId, $base, $rate, $applicable, $needsReview, $consumerCountry);

        return $invoiceId;
    }

    /** Další řádek k existujícímu dokladu — doklad může míchat OSS a tuzemské plnění. */
    private function addItem(
        int $invoiceId,
        float $base,
        float $rate,
        int $applicable,
        int $needsReview,
        ?string $consumerCountry = null,
    ): void {
        $pdo = $this->db->pdo();
        $vat = round($base * $rate / 100.0, 2);
        $order = (int) $pdo->query(
            'SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ' . $invoiceId
        )->fetchColumn();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type,
                 oss_needs_manual_review)
             VALUES (?, "Plnění", 1, "ks", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $invoiceId, $base, $this->rateId, $rate, $base, $vat, $base + $vat, $order,
            $applicable,
            $applicable === 1 ? ($consumerCountry ?? 'PL') : null,
            $applicable === 1 ? 'standard' : null,
            $applicable === 1 ? 'services' : null,
            $needsReview,
        ]);
    }

    private function client(string $iso2): int
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
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Mesto", "11000", ?, "c@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, 'Odběratel ' . $iso2, $countryId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }
}
