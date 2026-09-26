<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end testy režimu „ceny s DPH" (prices_include_vat) na vydaných dokladech.
 *
 * V tomto režimu nese `unit_price_without_vat` BRUTTO (cenu s DPH) a DPH se počítá
 * koeficientem shora. Tady ověřujeme, že:
 *   - uložené řádkové totály (total_without_vat/total_vat/total_with_vat) sedí na haléř
 *     koeficientem — to jsou hodnoty, které sumují DPH výkazy (VatLedgerService čte
 *     ii.total_without_vat AS base a ii.total_vat AS vat, NE unit_price),
 *   - režim se DĚDÍ při tvorbě daňového dokladu z proformy (FinalFromProformaCreator),
 *     takže se zkopírované brutto ceny NEpřepočítají omylem jako netto (nafouknutí).
 *
 * Používá existující supplier/client/currency/vat_rate z dev DB; uklízí po sobě.
 */
#[Group('integration')]
final class PricesIncludeVatTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $repo;
    private InvoiceCalculator $calc;
    private FinalFromProformaCreator $finalCreator;

    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private float $vatRate = 0.0;
    private int $userId = 0;

    /** @var int[] */
    private array $createdInvoiceIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        try {
            $container = Bootstrap::buildContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->db = $container->get(Connection::class);
            $this->repo = $container->get(InvoiceRepository::class);
            $this->calc = $container->get(InvoiceCalculator::class);
            $this->finalCreator = $container->get(FinalFromProformaCreator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();

        $supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        if ($supplierId <= 0) {
            $this->markTestSkipped('Žádný supplier');
        }

        $stmt = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL LIMIT 1');
        $stmt->execute([$supplierId]);
        $this->clientId = (int) $stmt->fetchColumn();
        if ($this->clientId <= 0) {
            $this->markTestSkipped("Supplier #{$supplierId} nemá klienty");
        }

        $stmt = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        if ($this->currencyId <= 0) {
            $this->markTestSkipped('Supplier nemá aktivní měnu');
        }

        $row = $pdo->query(
            'SELECT id, rate_percent FROM vat_rates
              WHERE is_reverse_charge = 0 AND rate_percent > 0
                AND (valid_from IS NULL OR valid_from <= CURDATE())
                AND (valid_to IS NULL OR valid_to >= CURDATE())
              ORDER BY is_default DESC, rate_percent DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('Žádná použitelná VAT sazba');
        }
        $this->vatRateId = (int) $row['id'];
        $this->vatRate = (float) $row['rate_percent'];

        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->userId <= 0) {
            $this->markTestSkipped('Žádný uživatel');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->createdInvoiceIds !== []) {
            $pdo = $this->db->pdo();
            foreach ($this->createdInvoiceIds as $id) {
                $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
            }
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * @param array<string,mixed> $extraHeader
     */
    private function createGrossInvoice(string $type, float $grossUnit, float $qty, array $extraHeader = []): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $id = $this->repo->createDraft(array_merge([
            'invoice_type'       => $type,
            'client_id'          => $this->clientId,
            'issue_date'         => $today,
            'tax_date'           => $today,
            'due_date'           => $today,
            'currency_id'        => $this->currencyId,
            'reverse_charge'     => false,
            'prices_include_vat' => true,
            'language'           => 'cs',
        ], $extraHeader), $this->userId);
        $this->createdInvoiceIds[] = $id;
        $this->repo->replaceItems($id, [[
            'description'            => 'TEST účtenka s DPH (PHPUnit)',
            'quantity'               => $qty,
            'unit'                   => 'ks',
            'unit_price_without_vat' => $grossUnit, // v režimu s DPH = cena VČETNĚ DPH
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
        ]]);
        $this->calc->recompute($id);
        return $this->repo->find($id);
    }

    public function testGrossInvoiceStoresCoefficientTotalsForVatLedger(): void
    {
        $r = $this->vatRate;
        $gross = 1210.00; // 1 ks za 1210 vč. DPH
        $inv = $this->createGrossInvoice('invoice', $gross, 1.0);

        $expVat  = round($gross * $r / (100.0 + $r), 2);
        $expBase = round($gross - $expVat, 2);

        // Celek MUSÍ sedět přesně na zadané brutto.
        $this->assertEqualsWithDelta($gross, (float) $inv['totals']['with_vat'], 0.001, 'Celek s DPH = zadané brutto');
        $this->assertEqualsWithDelta($expVat, (float) $inv['totals']['vat'], 0.001);
        $this->assertEqualsWithDelta($expBase, (float) $inv['totals']['without_vat'], 0.001);

        // Položka: unit_price drží BRUTTO, ale řádkové totály jsou koeficientové (= co čte VatLedger).
        $item = $inv['items'][0];
        $this->assertEqualsWithDelta($gross, (float) $item['unit_price_without_vat'], 0.001, 'unit_price drží brutto');
        $this->assertEqualsWithDelta($expBase, (float) $item['total_without_vat'], 0.001);
        $this->assertEqualsWithDelta($expVat, (float) $item['total_vat'], 0.001);
        $this->assertEqualsWithDelta($gross, (float) $item['total_with_vat'], 0.001);

        // vat_breakdown (zdroj pro KH/přiznání) odpovídá koeficientu.
        $this->assertEqualsWithDelta($expBase, (float) $inv['vat_breakdown'][0]['base'], 0.011);
        $this->assertEqualsWithDelta($expVat, (float) $inv['vat_breakdown'][0]['vat'], 0.011);
    }

    public function testProformaToFinalPreservesPricesIncludeVatAndExactTotal(): void
    {
        $gross = 1210.00;
        $proforma = $this->createGrossInvoice('proforma', $gross, 1.0);
        $this->assertSame(1, (int) $proforma['prices_include_vat'], 'Proforma je v režimu s DPH');
        $proformaTotal = (float) $proforma['totals']['with_vat'];
        $this->assertEqualsWithDelta($gross, $proformaTotal, 0.001);

        // Daňový doklad k záloze — režim se MUSÍ zdědit.
        $finalId = $this->finalCreator->create((int) $proforma['id'], $this->userId);
        $this->createdInvoiceIds[] = $finalId;
        $final = $this->repo->find($finalId);

        $this->assertSame(1, (int) $final['prices_include_vat'], 'Daňový doklad musí zdědit režim ceny s DPH');
        // Bez dědění by se brutto 1210 přepočítalo jako netto → total_with_vat ~1464 (nafouknuto).
        $this->assertEqualsWithDelta($proformaTotal, (float) $final['totals']['with_vat'], 0.02,
            'Celek daňového dokladu nesmí být nafouknutý oproti proformě');

        // Zkopírovaná položka drží brutto a její koeficientové totály sedí.
        $item = $final['items'][0];
        $this->assertEqualsWithDelta($gross, (float) $item['unit_price_without_vat'], 0.001);
        $this->assertEqualsWithDelta($gross, (float) $item['total_with_vat'], 0.02);
    }

    public function testReverseChargeGrossInvoiceHasZeroTaxAndGrossBase(): void
    {
        // Reverse charge + režim ceny s DPH: daň 0 (odvede odběratel), základ = celé brutto.
        $gross = 1210.00;
        $inv = $this->createGrossInvoice('invoice', $gross, 1.0, ['reverse_charge' => true]);

        $this->assertSame(1, (int) $inv['prices_include_vat']);
        $this->assertEqualsWithDelta($gross, (float) $inv['totals']['with_vat'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $inv['totals']['vat'], 0.001, 'Reverse charge → daň 0');
        $this->assertEqualsWithDelta($gross, (float) $inv['totals']['without_vat'], 0.001, 'Základ = celé brutto');
    }

    public function testGrossCreditNoteNegativeQuantityCoefficientTotals(): void
    {
        // Dobropis (záporné množství) v režimu ceny s DPH: koeficient na záporném brutto.
        $r = $this->vatRate;
        $gross = 1210.00;
        $inv = $this->createGrossInvoice('credit_note', $gross, -1.0);

        $expVat  = round($gross * $r / (100.0 + $r), 2);
        $expBase = round($gross - $expVat, 2);

        $this->assertEqualsWithDelta(-$gross, (float) $inv['totals']['with_vat'], 0.001);
        $this->assertEqualsWithDelta(-$expVat, (float) $inv['totals']['vat'], 0.001);
        $this->assertEqualsWithDelta(-$expBase, (float) $inv['totals']['without_vat'], 0.001);
    }

    public function testNormalModeUnaffected(): void
    {
        // Kontrolní běžná varianta (bez DPH): unit_price = netto, DPH zdola.
        $r = $this->vatRate;
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $id = $this->repo->createDraft([
            'invoice_type'       => 'invoice',
            'client_id'          => $this->clientId,
            'issue_date'         => $today,
            'tax_date'           => $today,
            'due_date'           => $today,
            'currency_id'        => $this->currencyId,
            'reverse_charge'     => false,
            'prices_include_vat' => false,
            'language'           => 'cs',
        ], $this->userId);
        $this->createdInvoiceIds[] = $id;
        $this->repo->replaceItems($id, [[
            'description'            => 'TEST netto (PHPUnit)',
            'quantity'               => 1.0,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 1000.00,
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
        ]]);
        $this->calc->recompute($id);
        $inv = $this->repo->find($id);

        $this->assertEqualsWithDelta(1000.00, (float) $inv['totals']['without_vat'], 0.001);
        $this->assertEqualsWithDelta(round(1000.0 * $r / 100, 2), (float) $inv['totals']['vat'], 0.011);
        $this->assertEqualsWithDelta(round(1000.0 * (1 + $r / 100), 2), (float) $inv['totals']['with_vat'], 0.011);
    }
}
