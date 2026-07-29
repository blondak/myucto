<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\Section79Service;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 79 / § 79a ZDPH — odpočet při registraci a jeho snížení při zrušení registrace (ř. 45).
 *
 * Matice DPH to vedla jako CHYBÍ s vysokým rizikem a platilo to doslova: atribut
 * `odp_rez_nar` měl v repozitáři nula výskytů a generátor ř. 45 vědomě přeskakoval.
 * Obě situace přitom potkají firmu nejvýš párkrát za život, takže na ně nikdo nemyslí —
 * a chyba je jednosměrná: neuplatněný nárok při registraci propadne, neprovedené
 * snížení při zrušení registrace je doměrek.
 *
 * Znaménko ani období si nevymýšlím, říká je doslova anotace XSD u `odp_rez_nar`:
 * nárok při registraci KLADNĚ v období, do něhož spadá den vzniku plátcovství; snížení
 * při zrušení ZÁPORNĚ v posledním období registrace. Období proto řídí `effective_on`,
 * ne datum pořízení majetku — ověřuje {@see testPeriodIsDrivenByEffectiveDateNotAcquisition()}.
 */
#[Group('integration')]
final class Section79Test extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private Section79Service $service;
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
            $this->service = $c->get(Section79Service::class);
            // Builder MUSÍ pocházet z TÉHOŽ kontejneru — jinak dostane vlastní připojení
            // mimo transakci testu a izolovaného dodavatele vůbec neuvidí.
            $this->builder = $c->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'vat_registration_corrections'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1162 neproběhla.');
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

    // ── § 79: nárok při registraci ───────────────────────────────────────────

    /** Zásoby pořízené ve lhůtě 12 měsíců → nárok v plné výši, KLADNĚ. */
    public function testStockAcquiredWithinWindowGivesPositiveClaim(): void
    {
        $this->service->register(
            $this->supplierId, 'registration', 'Zásoby zboží na skladě',
            '2025-08-15', '2026-02-01', 'inventory', 21000.0,
        );

        $items = $this->service->preview($this->supplierId, '2026-02-01', '2026-02-28');

        self::assertCount(1, $items);
        self::assertTrue($items[0]['applies']);
        self::assertEqualsWithDelta(21000.0, $items[0]['amount'], 0.01, 'Nárok se uvádí kladně.');
        self::assertEqualsWithDelta(21000.0, $this->service->totalForReturn($this->supplierId, '2026-02-01', '2026-02-28'), 0.01);
    }

    /**
     * Pořízení mimo lhůtu 12 měsíců nárok nezakládá. Bez téhle hranice by se odpočet
     * uplatnil ze všeho, co firma kdy koupila — lhůta je jádro § 79 odst. 1.
     */
    public function testAcquisitionOlderThanTwelveMonthsGivesNoClaim(): void
    {
        $this->service->register(
            $this->supplierId, 'registration', 'Zboží koupené dávno',
            '2025-01-15', '2026-02-01', 'inventory', 21000.0,
        );

        $items = $this->service->preview($this->supplierId, '2026-02-01', '2026-02-28');

        self::assertFalse($items[0]['applies']);
        self::assertSame(0.0, $items[0]['amount']);
        self::assertStringContainsString('§ 79 odst. 1', $items[0]['reason']);
    }

    /** Hranice lhůty je přesně 12 měsíců zpět — den na ní ještě nárok zakládá. */
    public function testAcquisitionExactlyOnWindowBoundaryStillQualifies(): void
    {
        $this->service->register(
            $this->supplierId, 'registration', 'Zboží na hranici lhůty',
            '2025-02-01', '2026-02-01', 'inventory', 10000.0,
        );

        self::assertTrue($this->service->preview($this->supplierId, '2026-02-01', '2026-02-28')[0]['applies']);
    }

    // ── § 79a: snížení při zrušení registrace ────────────────────────────────

    /** Zásoby při zrušení registrace vracejí odpočet CELÝ, a to záporně. */
    public function testDeregistrationOfStockReturnsWholeDeduction(): void
    {
        $this->service->register(
            $this->supplierId, 'deregistration', 'Zásoby ke dni zrušení registrace',
            '2025-11-01', '2026-06-30', 'inventory', 15000.0,
        );

        $items = $this->service->preview($this->supplierId, '2026-06-01', '2026-06-30');

        self::assertTrue($items[0]['applies']);
        self::assertEqualsWithDelta(-15000.0, $items[0]['amount'], 0.01, 'Snížení se uvádí záporně.');
    }

    /**
     * Dlouhodobý majetek vrací jen POMĚRNOU část podle roků zbývajících do konce lhůty
     * (§ 79a odst. 2 → § 78d obdobně). Vrátit celý odpočet by znamenalo vrátit i tu část,
     * která už byla oprávněně spotřebovaná ve zdaňovaných letech.
     */
    public function testDeregistrationOfFixedAssetReturnsOnlyRemainingYears(): void
    {
        // Pořízeno 2024, zrušení 2026 → uplynuly 2 roky z 5, zbývají 3 → 3/5 ze 100 000.
        $this->service->register(
            $this->supplierId, 'deregistration', 'Stroj',
            '2024-03-01', '2026-06-30', 'fixed_asset', 100000.0, 5,
        );

        $items = $this->service->preview($this->supplierId, '2026-06-01', '2026-06-30');

        self::assertTrue($items[0]['applies']);
        self::assertEqualsWithDelta(-60000.0, $items[0]['amount'], 0.01, '3/5 ze 100 000.');
        self::assertStringContainsString('§ 79a odst. 2', $items[0]['reason']);
    }

    /** Stavba má lhůtu 10 let — poměr se počítá z ní, ne z pětileté. */
    public function testBuildingUsesTenYearPeriod(): void
    {
        $this->service->register(
            $this->supplierId, 'deregistration', 'Budova',
            '2024-03-01', '2026-06-30', 'fixed_asset', 1000000.0, 10,
        );

        // Uplynuly 2 roky z 10, zbývá 8 → 8/10 z 1 000 000.
        self::assertEqualsWithDelta(
            -800000.0,
            $this->service->preview($this->supplierId, '2026-06-01', '2026-06-30')[0]['amount'],
            0.01,
        );
    }

    /** Po uplynutí lhůty se nevrací nic — majetek už úpravě odpočtu nepodléhá. */
    public function testFixedAssetAfterPeriodExpiryReturnsNothing(): void
    {
        $this->service->register(
            $this->supplierId, 'deregistration', 'Starý stroj',
            '2019-03-01', '2026-06-30', 'fixed_asset', 100000.0, 5,
        );

        $items = $this->service->preview($this->supplierId, '2026-06-01', '2026-06-30');

        self::assertFalse($items[0]['applies']);
        self::assertSame(0.0, $items[0]['amount']);
        self::assertStringContainsString('uplynula', $items[0]['reason']);
    }

    /**
     * Období vykázání řídí `effective_on` (den registrace / zrušení), NE datum pořízení.
     * Kdyby se řídilo pořízením, korekce by spadla do období, kdy firma ještě nebyla
     * plátcem — tedy do přiznání, které vůbec neexistuje.
     */
    public function testPeriodIsDrivenByEffectiveDateNotAcquisition(): void
    {
        $this->service->register(
            $this->supplierId, 'registration', 'Zboží',
            '2025-08-15', '2026-02-01', 'inventory', 21000.0,
        );

        self::assertSame([], $this->service->preview($this->supplierId, '2025-08-01', '2025-08-31'),
            'V období pořízení se korekce neobjeví.');
        self::assertCount(1, $this->service->preview($this->supplierId, '2026-02-01', '2026-02-28'),
            'Objeví se v období dne vzniku plátcovství.');
    }

    // ── validace vstupu ──────────────────────────────────────────────────────

    /**
     * Dlouhodobý majetek bez lhůty se zaevidovat NEDÁ. Tiché dosazení pětiletky by
     * u stavby vrátilo dvojnásobek toho, co má.
     */
    public function testFixedAssetWithoutPeriodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pět nebo deset let/');

        $this->service->register(
            $this->supplierId, 'deregistration', 'Stroj bez lhůty',
            '2024-03-01', '2026-06-30', 'fixed_asset', 100000.0,
        );
    }

    /** Znaménko určuje DRUH položky, ne vstup — záporná daň na vstupu nedává smysl. */
    public function testNegativeVatAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->register(
            $this->supplierId, 'registration', 'Nesmysl',
            '2025-08-15', '2026-02-01', 'inventory', -100.0,
        );
    }

    // ── promítnutí do přiznání ───────────────────────────────────────────────

    /**
     * Korekce se objeví na ř. 45 (`odp_rez_nar`), promítne se do součtu ř. 46 a XML
     * projde XSD. Bez validace proti schématu by šlo tvrdit cokoli — atribut mohl
     * patřit na jinou Vetu, jak se to stalo u `uprav_odp`.
     */
    public function testCorrectionAppearsOnLine45AndValidatesAgainstXsd(): void
    {
        $this->service->register(
            $this->supplierId, 'registration', 'Zásoby při registraci',
            '2025-08-15', '2026-02-10', 'inventory', 21000.0,
        );

        $xml = (string) ($this->builder->build($this->supplierId, 2026, 2, 'monthly')['xml'] ?? '');
        self::assertNotSame('', $xml, 'Přiznání se nevygenerovalo.');
        self::assertStringContainsString('odp_rez_nar="21000"', $xml, 'Korekce musí být na ř. 45.');
        self::assertStringContainsString('odp_sum_nar="21000"', $xml, 'ř. 46 je součet ř. 40–45.');

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        libxml_use_internal_errors(true);
        $valid = $dom->schemaValidate(dirname(__DIR__, 3) . '/xsd/dphdp3.xsd');
        $errors = array_map(static fn ($e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        self::assertTrue($valid, 'XML neprošlo XSD: ' . implode(' | ', $errors));
    }

    /** Snížení při zrušení registrace jde do XML se ZÁPORNÝM znaménkem. */
    public function testDeregistrationIsNegativeInXml(): void
    {
        $this->service->register(
            $this->supplierId, 'deregistration', 'Zásoby ke zrušení registrace',
            '2025-11-01', '2026-03-31', 'inventory', 5000.0,
        );

        $xml = (string) ($this->builder->build($this->supplierId, 2026, 3, 'monthly')['xml'] ?? '');

        self::assertStringContainsString('odp_rez_nar="-5000"', $xml);
    }

    /** Bez evidované položky zůstane ř. 45 prázdný — chování se zpětně nemění. */
    public function testWithoutItemsLine45StaysAbsent(): void
    {
        $xml = (string) ($this->builder->build($this->supplierId, 2026, 4, 'monthly')['xml'] ?? '');

        self::assertStringNotContainsString('odp_rez_nar', $xml);
    }
}
