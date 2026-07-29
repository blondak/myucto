<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\VatDeductionAdjustmentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 78–78e ZDPH — úprava odpočtu daně u dlouhodobého majetku (ř. 60 přiznání).
 *
 * Matice DPH (F3) vedla tuhle položku jako CHYBÍ s vysokým rizikem a platilo to
 * doslova: atribut `uprav_odp` měl v celém repozitáři NULA výskytů, sledování 5/10leté
 * lhůty neexistovalo. U nemovitosti nebo majetku s kráceným nárokem je přitom úprava
 * povinná po celou lhůtu — a nikdo na ni neupozornil ani ji nespočítal.
 *
 * Jádro pravidla je § 78a odst. 3: upravuje se JEN při odchylce poměru o víc než
 * 10 procentních bodů. Bez té hranice by systém generoval úpravu z každého kolísání
 * koeficientu, což zákon nechce a účetní by to zahltilo.
 */
#[Group('integration')]
final class VatDeductionAdjustmentTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private VatDeductionAdjustmentService $service;
    private \MyInvoice\Service\Report\DphPriznaniBuilder $builder;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->service = $c->get(VatDeductionAdjustmentService::class);
            // Builder MUSÍ pocházet z TÉHOŽ kontejneru — jinak dostane vlastní připojení
            // mimo transakci testu a izolovaného dodavatele vůbec neuvidí.
            $this->builder = $c->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'vat_deduction_adjustments'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1148 neproběhla.');
        }
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí supplier.');
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
     * § 78a odst. 3 — odchylka do 10 procentních bodů úpravu NEVYVOLÁ.
     * Tohle je hranice, bez které by systém generoval úpravu z každého kolísání
     * koeficientu.
     */
    public function testDeviationWithinTenPointsDoesNotAdjust(): void
    {
        $this->registerBuilding(originalRatio: 60);

        $items = $this->service->previewYear($this->supplierId, 2027, 70); // +10 p. b.

        self::assertCount(1, $items);
        self::assertFalse($items[0]['applies'], 'Rozdíl přesně 10 p. b. je ještě v toleranci.');
        self::assertSame(0.0, $items[0]['amount']);
        self::assertSame(0.0, $this->service->totalForReturn($this->supplierId, 2027, 70));
    }

    /** Nad hranicí se upraví 1/N původní daně poměrem rozdílu. */
    public function testDeviationAboveThresholdAdjustsOneTenth(): void
    {
        $this->registerBuilding(originalRatio: 60);

        // +21 p. b. → 200 000 × 21 / 100 / 10 = 4 200 Kč
        $items = $this->service->previewYear($this->supplierId, 2027, 81);

        self::assertTrue($items[0]['applies']);
        self::assertEqualsWithDelta(4200.0, $items[0]['amount'], 0.01);
        self::assertSame(4200.0, $this->service->totalForReturn($this->supplierId, 2027, 81));
    }

    /** Posun opačným směrem dá ZÁPORNOU úpravu — poplatník část odpočtu vrací. */
    public function testDeviationDownwardGivesNegativeAdjustment(): void
    {
        $this->registerBuilding(originalRatio: 80);

        // −30 p. b. → 200 000 × (−30) / 100 / 10 = −6 000 Kč
        $items = $this->service->previewYear($this->supplierId, 2027, 50);

        self::assertTrue($items[0]['applies']);
        self::assertEqualsWithDelta(-6000.0, $items[0]['amount'], 0.01);
        self::assertSame(-6000.0, $this->service->totalForReturn($this->supplierId, 2027, 50));
    }

    /**
     * Lhůta je konečná: po jejím uplynutí majetek z rozpisu zmizí. Bez toho by se
     * upravovalo donekonečna.
     */
    public function testAssetOutsideAdjustmentPeriodIsIgnored(): void
    {
        // Movitá věc pořízená 2020, lhůta 5 let → 2020–2024. Rok 2026 je mimo.
        $this->service->register(
            $this->supplierId,
            'Stroj',
            '2020-03-01',
            5,
            100000.0,
            100,
        );

        self::assertSame([], $this->service->previewYear($this->supplierId, 2026, 50));
        self::assertSame(0.0, $this->service->totalForReturn($this->supplierId, 2026, 50));
    }

    /** Rok pořízení se neupravuje — odpočet se v něm právě uplatnil. */
    public function testAcquisitionYearIsNotAdjusted(): void
    {
        $this->registerBuilding(originalRatio: 60);

        self::assertSame([], $this->service->previewYear($this->supplierId, 2025, 10));
    }

    /**
     * Bez známého poměru se NEDOSAZUJE původní hodnota. Tiché dosazení by tvrdilo,
     * že se použití nezměnilo — což nevíme, a u § 75 to systém z dokladů zjistit neumí.
     */
    public function testUnknownRatioIsReportedInsteadOfAssumed(): void
    {
        $this->registerBuilding(originalRatio: 60);

        $items = $this->service->previewYear($this->supplierId, 2027, null);

        self::assertTrue($items[0]['needs_input']);
        self::assertFalse($items[0]['applies']);
        self::assertSame(0.0, $items[0]['amount']);
    }

    /** Ručně zadaný poměr roku přebije koeficient — u § 75 je jediným zdrojem pravdy. */
    public function testManualYearRatioOverridesCoefficient(): void
    {
        $id = $this->registerBuilding(originalRatio: 60);
        $this->service->setYearRatio($this->supplierId, $id, 2027, 90);

        // Koeficient by dal 60 (bez úpravy), ruční hodnota 90 dá +30 p. b.
        $items = $this->service->previewYear($this->supplierId, 2027, 60);

        self::assertTrue($items[0]['applies']);
        self::assertSame(90, $items[0]['current_ratio_pct']);
        self::assertEqualsWithDelta(6000.0, $items[0]['amount'], 0.01);
    }

    /** Lhůta mimo § 78 odst. 3 se odmítne — 7 let neexistuje. */
    public function testInvalidPeriodLengthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->register($this->supplierId, 'Nesmysl', '2025-01-01', 7, 1000.0, 100);
    }

    /**
     * Úprava musí dotéct do PODÁNÍ, projít XSD a promítnout se do ř. 63.
     *
     * První verze psala `uprav_odp` na Veta5 a EPO by ji odmítlo — atribut patří na
     * Veta6 (rekapitulace). Odhalila to až validace proti XSD, ne čtení kódu: hodnota
     * se počítala správně, jen seděla na špatné větě. Proto tenhle test existuje.
     */
    public function testAdjustmentReachesReturnAndPassesXsd(): void
    {
        $id = $this->service->register($this->supplierId, 'Budova', '2024-06-01', 10, 200000.0, 60);
        $this->service->setYearRatio($this->supplierId, $id, 2025, 90);

        // Prosinec = poslední zdaňovací období roku → úprava se uvádí tady.
        $xml = (string) ($this->builder->build($this->supplierId, 2025, 12, 'monthly')['xml'] ?? '');

        self::assertMatchesRegularExpression('/uprav_odp="6000"/', $xml, 'ř. 60 = 200 000 × 30 / 100 / 10.');

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        libxml_use_internal_errors(true);
        $valid = $dom->schemaValidate(dirname(__DIR__, 3) . '/xsd/dphdp3.xsd');
        $errors = array_map(static fn ($e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();

        self::assertTrue($valid, "Přiznání s úpravou odpočtu musí projít XSD:\n" . implode("\n", $errors));
    }

    /** Mimo poslední období roku se úprava NEUVÁDÍ (XSD anotace k `uprav_odp`). */
    public function testAdjustmentIsOmittedOutsideLastPeriodOfYear(): void
    {
        $id = $this->service->register($this->supplierId, 'Budova', '2024-06-01', 10, 200000.0, 60);
        $this->service->setYearRatio($this->supplierId, $id, 2025, 90);

        $xml = (string) ($this->builder->build($this->supplierId, 2025, 6, 'monthly')['xml'] ?? '');

        self::assertStringNotContainsString('uprav_odp', $xml, 'Červen není poslední období roku.');
    }

    /** Stavba pořízená 2025, desetiletá lhůta, daň 200 000 Kč. */
    private function registerBuilding(int $originalRatio): int
    {
        return $this->service->register(
            $this->supplierId,
            'Administrativní budova',
            '2025-06-01',
            10,
            200000.0,
            $originalRatio,
        );
    }
}
