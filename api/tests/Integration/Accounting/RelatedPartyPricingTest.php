<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Tax\RelatedPartyService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 36a ZDPH + § 23 odst. 7 ZDP — spojené osoby a ceny obvyklé.
 *
 * Matice vedly obojí jako CHYBÍ s vysokým rizikem a platilo to doslova: pojem „spojená
 * osoba" neměl v repozitáři jediný výskyt. Je to přitom typický terč doměrku — správce
 * daně ho hledá přednostně, protože je snadno prokazatelný z rejstříků a účetnictví.
 *
 * ── Co se testuje a proč právě to ───────────────────────────────────────────
 * „Cenu obvyklou" systém obecně nezná, je to tržní veličina. Zná ale její silnou
 * aproximaci z vlastních dat: za kolik prodal TOTÉŽ nespojeným osobám. Testy proto
 * zamykají tři věci, na kterých ta aproximace stojí:
 *
 *   • MEDIÁN, ne průměr — jediná odlehlá faktura (výprodej) by průměr utáhla a systém
 *     by hlásil odchylku tam, kde žádná není ({@see testOutlierDoesNotDragTheComparison()}),
 *   • bez srovnatelného vzorku se odchylka NETVRDÍ ({@see testWithoutComparableSalesNothingIsClaimed()}),
 *   • práh odchylky — běžné cenové rozpětí se hlásit nemá, jinak účetní kontrolu
 *     přestane číst.
 */
#[Group('integration')]
final class RelatedPartyPricingTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2089;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private RelatedPartyService $service;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $periodId = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;
    private int $seq = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db      = $c->get(Connection::class);
            $this->service = $c->get(RelatedPartyService::class);
            $this->closing = $c->get(ClosingService::class);
            $this->periods = $c->get(AccountingPeriodRepository::class);
            $seeder        = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('clients', 'related_party')) {
            $this->markTestSkipped('Migrace 1163 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$source, $this->userId, $this->currencyId, $this->vatRateId, $this->czId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);
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

    // ── § 36a: odchylka od ceny obvyklé ──────────────────────────────────────

    /**
     * Prodej spojené osobě výrazně pod cenou pro nespojené se nahlásí. Tohle je jádro
     * § 36a — základem daně má být cena obvyklá, ne sjednaná.
     */
    public function testUnderpricedSaleToRelatedPartyIsReported(): void
    {
        $unrelated = $this->client('Nespojený odběratel', related: false);
        $related = $this->client('Sesterská s.r.o.', related: true, type: 'capital');

        $this->invoice($unrelated, 'Konzultace', 1000.0);
        $this->invoice($unrelated, 'Konzultace', 1000.0);
        $this->invoice($related, 'Konzultace', 400.0); // −60 %

        $rows = $this->service->priceDeviations($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertCount(1, $rows);
        self::assertSame('Sesterská s.r.o.', $rows[0]['partner_name']);
        self::assertEqualsWithDelta(1000.0, $rows[0]['market_price'], 0.01);
        self::assertEqualsWithDelta(-60.0, $rows[0]['deviation_pct'], 0.01);
        self::assertSame(2, $rows[0]['samples']);
        self::assertFalse($this->checkOk('related_party_price_deviation'));
    }

    /** Nadhodnocený prodej se hlásí stejně — § 36a míří oběma směry. */
    public function testOverpricedSaleToRelatedPartyIsReported(): void
    {
        $unrelated = $this->client('Nespojený', related: false);
        $related = $this->client('Spojená', related: true, type: 'otherwise');

        $this->invoice($unrelated, 'Servis', 500.0);
        $this->invoice($unrelated, 'Servis', 500.0);
        $this->invoice($related, 'Servis', 900.0); // +80 %

        $rows = $this->service->priceDeviations($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(80.0, $rows[0]['deviation_pct'], 0.01);
    }

    /** Odchylka pod prahem se nehlásí — běžné cenové rozpětí není převod zisku. */
    public function testSmallDeviationIsIgnored(): void
    {
        $unrelated = $this->client('Nespojený', related: false);
        $related = $this->client('Spojená', related: true);

        $this->invoice($unrelated, 'Zboží', 1000.0);
        $this->invoice($unrelated, 'Zboží', 1000.0);
        $this->invoice($related, 'Zboží', 900.0); // −10 %, pod prahem 20 %

        self::assertSame([], $this->service->priceDeviations($this->supplierId, self::STARTS_ON, self::ENDS_ON));
        self::assertTrue($this->checkOk('related_party_price_deviation'));
    }

    /**
     * Srovnává se MEDIÁN, ne průměr. S průměrem by jediný výprodej za korunu srazil
     * srovnávací cenu a systém by hlásil odchylku u ceny, která je ve skutečnosti běžná.
     */
    public function testOutlierDoesNotDragTheComparison(): void
    {
        $unrelated = $this->client('Nespojený', related: false);
        $related = $this->client('Spojená', related: true);

        $this->invoice($unrelated, 'Licence', 1000.0);
        $this->invoice($unrelated, 'Licence', 1000.0);
        $this->invoice($unrelated, 'Licence', 1.0);      // výprodej — průměr by spadl na ~667
        $this->invoice($related, 'Licence', 1000.0);     // běžná cena

        self::assertSame([], $this->service->priceDeviations($this->supplierId, self::STARTS_ON, self::ENDS_ON),
            'Medián 1000 → nulová odchylka; průměr by hlásil +50 %.');
    }

    /**
     * Bez srovnatelného vzorku se odchylka NETVRDÍ. Podložit daňové tvrzení odhadem
     * ceny obvyklé by bylo horší než mlčet — doložení je na účetní.
     */
    public function testWithoutComparableSalesNothingIsClaimed(): void
    {
        $related = $this->client('Spojená', related: true);
        $this->invoice($related, 'Unikátní služba', 400.0);

        self::assertSame([], $this->service->priceDeviations($this->supplierId, self::STARTS_ON, self::ENDS_ON));
    }

    /** Jediná srovnávací faktura na medián nestačí. */
    public function testSingleComparableSampleIsNotEnough(): void
    {
        $unrelated = $this->client('Nespojený', related: false);
        $related = $this->client('Spojená', related: true);

        $this->invoice($unrelated, 'Zboží', 1000.0);
        $this->invoice($related, 'Zboží', 100.0);

        self::assertSame([], $this->service->priceDeviations($this->supplierId, self::STARTS_ON, self::ENDS_ON));
    }

    /**
     * Měsíční SOUHRN (množství 1 ks, cena = celý souhrn) se neporovnává.
     *
     * Tohle byl zdroj 71 planých nálezů ze 71 na ostrých datech: řádky „Výkaz víceprací
     * — 2026-05" mají množství 1 a v ceně celý měsíční rozsah práce daného zákazníka.
     * Medián takové množiny nemá věcný obsah — mezi dvěma měsíci skočil o 130 %.
     */
    public function testLumpSumLineIsNotComparedAsUnitPrice(): void
    {
        $unrelated = $this->client('Nespojený', related: false);
        $unrelated2 = $this->client('Nespojený 2', related: false);
        $related = $this->client('Spojená', related: true);

        $this->invoice($unrelated,  'Výkaz víceprací — červen', 7_500.0, quantity: 1.0);
        $this->invoice($unrelated2, 'Výkaz víceprací — červen', 36_000.0, quantity: 1.0);
        $this->invoice($related,    'Výkaz víceprací — červen', 1_500.0, quantity: 1.0);

        self::assertSame(
            [],
            $this->service->priceDeviations($this->supplierId, self::STARTS_ON, self::ENDS_ON),
            'Souhrn na 1 ks není jednotková cena a nesmí se porovnávat.',
        );
    }

    /** Položka bez jednotky se neporovnává — není z čeho poznat, že cena je za kus. */
    public function testLineWithoutUnitIsNotCompared(): void
    {
        $unrelated = $this->client('Nespojený', related: false);
        $unrelated2 = $this->client('Nespojený 2', related: false);
        $related = $this->client('Spojená', related: true);

        $this->invoice($unrelated,  'Paušál', 10_000.0, quantity: 5.0, unit: '');
        $this->invoice($unrelated2, 'Paušál', 12_000.0, quantity: 5.0, unit: '');
        $this->invoice($related,    'Paušál', 600.0, quantity: 5.0, unit: '');

        self::assertSame([], $this->service->priceDeviations($this->supplierId, self::STARTS_ON, self::ENDS_ON));
    }

    /**
     * Zálohová faktura NENÍ zdanitelné plnění a nesmí se počítat vedle navazujícího
     * dokladu. V produkci šlo o desítky proforem v řádu milionů, všechny
     * s navazující ostrou fakturou — plnění by se vykázalo dvakrát.
     */
    public function testProformaIsNotListedAsTaxableSupply(): void
    {
        $related = $this->client('Spojená', related: true);
        $this->invoice($related, 'Zboží', 1_000.0);
        $proformaId = $this->invoice($related, 'Záloha na zboží', 1_000.0);
        $this->db->pdo()->prepare('UPDATE invoices SET invoice_type = "proforma" WHERE id = ?')
            ->execute([$proformaId]);

        $rows = $this->service->transactions($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertCount(1, $rows, 'Proforma se do zdanitelných plnění nepočítá.');
        self::assertSame(1_000.0, $rows[0]['amount']);
    }

    /** Popis se páruje bez ohledu na velikost písmen a přebytečné mezery. */
    public function testItemMatchingIgnoresCaseAndWhitespace(): void
    {
        $unrelated = $this->client('Nespojený', related: false);
        $related = $this->client('Spojená', related: true);

        $this->invoice($unrelated, 'Konzultace  IT', 1000.0);
        $this->invoice($unrelated, 'konzultace it', 1000.0);
        $this->invoice($related, 'KONZULTACE IT', 300.0);

        self::assertCount(1, $this->service->priceDeviations($this->supplierId, self::STARTS_ON, self::ENDS_ON));
    }

    // ── seznam transakcí ─────────────────────────────────────────────────────

    /** Transakce se spojenou osobou se vypíšou i tam, kde odchylku spočítat nelze. */
    public function testTransactionsAreListedRegardlessOfComparison(): void
    {
        $related = $this->client('Spojená', related: true, type: 'capital');
        $this->invoice($related, 'Cokoli', 5000.0);

        $rows = $this->service->transactions($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertCount(1, $rows);
        self::assertSame('issued', $rows[0]['direction']);
        self::assertSame('capital', $rows[0]['related_party_type']);
        self::assertEqualsWithDelta(5000.0, $rows[0]['amount'], 0.01);
    }

    /** Nespojené osoby se do seznamu nepletou. */
    public function testUnrelatedPartiesAreNotListed(): void
    {
        $this->invoice($this->client('Běžný zákazník', related: false), 'Zboží', 1000.0);

        self::assertSame([], $this->service->transactions($this->supplierId, self::STARTS_ON, self::ENDS_ON));
    }

    // ── § 23/7: úprava základu daně ──────────────────────────────────────────

    /** Úprava se eviduje per protistrana i s důvodem a sčítá se do net delty. */
    public function testAdjustmentsAreAggregated(): void
    {
        $client = $this->client('Spojená', related: true);

        $this->service->recordAdjustment($this->supplierId, self::YEAR, 50000.0, 'Rozdíl nedoložen', $client);
        $this->service->recordAdjustment($this->supplierId, self::YEAR, 20000.0, 'Protistrana snižuje', $client, 'decrease');

        $out = $this->service->adjustments($this->supplierId, self::YEAR);

        self::assertCount(2, $out['rows']);
        self::assertEqualsWithDelta(50000.0, $out['total_increase'], 0.01);
        self::assertEqualsWithDelta(20000.0, $out['total_decrease'], 0.01);
        self::assertEqualsWithDelta(30000.0, $out['net_delta'], 0.01);
    }

    /**
     * Úprava bez důvodu se neuloží. § 23/7 se uplatní právě tehdy, když rozdíl NENÍ
     * uspokojivě doložen — důvod je jádro položky, ne poznámka.
     */
    public function testAdjustmentWithoutReasonIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/[Dd]ůvod/');

        $this->service->recordAdjustment($this->supplierId, self::YEAR, 1000.0, '   ');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function client(string $name, bool $related, ?string $type = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, related_party, related_party_type)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "cs", ?, 1, ?, ?)'
        )->execute([
            $this->supplierId, $name, $this->czId,
            'c' . (++$this->seq) . '@example.com', $this->currencyId,
            $related ? 1 : 0, $related ? ($type ?? 'otherwise') : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Výchozí tvar je SROVNATELNÁ položka (množství > 1, jednotka ks) — jen takovou
     * kontrola porovnává. Paušál nebo měsíční souhrn se předá jako quantity 1
     * a/nebo bez jednotky.
     */
    private function invoice(int $clientId, string $description, float $unitPrice, float $quantity = 2.0, ?string $unit = 'ks'): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, invoice_type, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, 0, "{}", "{}", ?, 0, ?, "sent", ?)'
        )->execute([
            $this->supplierId, $clientId, $date, $date, $date, $this->currencyId,
            $unitPrice, $unitPrice, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, ?, ?, ?, ?, 21.00, ?, 0, ?, 1)'
        )->execute([$id, $description, $quantity, $unit, $unitPrice, $this->vatRateId, $unitPrice, $unitPrice]);

        return $id;
    }

    private function checkOk(string $key): bool
    {
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, null, null);
        foreach ($result['checks'] as $c) {
            if ($c['key'] === $key) {
                return (bool) $c['ok'];
            }
        }
        self::fail('Kontrola ' . $key . ' chybí v seznamu kontrol.');
    }
}
