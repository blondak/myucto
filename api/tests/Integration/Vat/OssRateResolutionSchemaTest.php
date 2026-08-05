<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Vat;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssClientContext;
use MyInvoice\Service\Oss\OssDerivationReason;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Vat\VatRateResolution;
use MyInvoice\Service\Vat\VatRateResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrakt obou nových služeb proti SKUTEČNÉMU schématu MariaDB.
 *
 * Rozhodovací pravidla pokrývají unit testy nad in-memory SQLite — ty ale ověřují
 * dotazy proti schématu, které si samy napsaly. Kdyby se sloupec ve skutečné databázi
 * jmenoval jinak (nebo ho migrace nepřidala), unit testy zůstanou zelené a chyba se
 * objeví až při importu u zákazníka. Tenhle test je proto úzký záměrně: nezkouší
 * pravidla, zkouší, že SQL na reálném schématu vůbec projde a vrátí smysluplný výsledek.
 *
 * Zapisující větev tu není z prostého důvodu: žádná neexistuje. `VatRateResolver` je
 * čistě čtecí a sazby nezakládá — `vat_rates` je globální tabulka bez `supplier_id`,
 * takže zápis z importu jednoho nájemníka by měnil číselník celé instalaci.
 */
#[Group('integration')]
final class OssRateResolutionSchemaTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private OssItemDeriver $deriver;
    private VatRateResolver $resolver;
    private int $supplierId = 0;
    private int $clientId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->deriver = $container->get(OssItemDeriver::class);
            $this->resolver = $container->get(VatRateResolver::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $plCountryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'PL' LIMIT 1")->fetchColumn() ?: 0);
        if ($source === 0 || $currencyId === 0 || $plCountryId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / měna / stát PL).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        $pdo->prepare(
            "UPDATE supplier
                SET oss_enabled = 1,
                    oss_valid_from = '2020-01-01',
                    oss_valid_to = NULL,
                    oss_identification_country = 'CZ',
                    oss_return_currency = 'EUR'
              WHERE id = ?"
        )->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Testovací 1", "Mesto", "11000", ?, NULL, "spotrebitel@example.test",
                     "cs", ?, 1, 0)'
        )->execute([$this->supplierId, 'Testovací spotřebitel PL', $plCountryId, $currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
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

    /** Dotazy deriveru (supplier + clients JOIN countries + číselník) na reálném schématu. */
    public function testDeriverRunsAgainstRealSchema(): void
    {
        $client = $this->deriver->clientContext($this->clientId);
        self::assertSame('PL', $client->countryIso2);
        self::assertTrue($client->isEu);
        self::assertFalse($client->hasVatId());

        $decision = $this->deriver->derive($this->supplierId, $client, 23.0, 'kg', '2026-07-15', false);

        self::assertTrue($decision->applicable);
        self::assertSame('PL', $decision->consumerCountry);
        self::assertSame('goods', $decision->supplyType);
        self::assertNull($decision->vatClassificationCode);
        self::assertSame(
            'standard',
            $decision->rateType,
            'migrace 1152 vede PL 23 % jako základní sazbu — pokud tohle padne, číselník na instalaci chybí',
        );
        self::assertContains(OssDerivationReason::RateTypeFromCodebook, $decision->notes);
    }

    /**
     * KRITICKÝ NÁLEZ na reálném schématu: tuzemskost sazby se nesmí ptát `vat_rates`.
     *
     * Zákazník z analýzy si v Nastavení → Sazby DPH založil 23% sazbu, ale se zemí CZ,
     * protože formulář má CZ předvyplněnou. Dotaz nad `vat_rates` by tedy vrátil „ČR zná
     * 23 %", polský řádek by spadl do nejednoznačnosti, zůstal tuzemský a skončil na ř. 1
     * českého přiznání. Autoritou je jedině `oss_member_state_rates`, kde ČR 23 % nemá.
     *
     * Sazba se zakládá uvnitř transakce testu, takže po rollbacku po ní nezůstane stopa.
     */
    public function testUserEditableVatRatesCannotTurnAnOssRowDomestic(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en,
                                    is_default, is_reverse_charge, valid_from, valid_to, display_order)
             VALUES ('TEST-PL-23', 23.00, 'CZ', 'Testovací PL 23', 'Test PL 23', 0, 0, '2021-07-01', NULL, 999)"
        )->execute();

        self::assertTrue(
            (new VatRateResolver($this->db))->resolve('CZ', 23.0, '2026-07-15')->found(),
            'fixture musí obsahovat 23% sazbu se zemí CZ, jinak test nic nehlídá',
        );

        $decision = $this->deriver->derive(
            $this->supplierId,
            $this->deriver->clientContext($this->clientId),
            23.0,
            'kg',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertSame(OssDerivationReason::B2cEuConsumer, $decision->reason);
        self::assertFalse($decision->needsManualReview());
    }

    /**
     * Rozhodovací tabulka na reálných datech: 21 % platí v ČR i v Nizozemsku, takže
     * z něj místo plnění určit nejde. Systém nesmí hádat — a pochybnost řeší VE PROSPĚCH
     * OSS, protože chybný OSS řádek uživatel uvidí v krátkém náhledu podání, kdežto chybný
     * tuzemský řádek zmizí mezi stovkami řádků přiznání k DPH.
     */
    public function testAmbiguousRateGoesToOssForManualReviewOnRealSchema(): void
    {
        $decision = $this->deriver->derive(
            $this->supplierId,
            new OssClientContext('NL', true, null),
            21.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertSame(OssDerivationReason::RateAmbiguousDomesticOrConsumer, $decision->reason);
        self::assertTrue($decision->needsManualReview());
        self::assertSame(1, $decision->toItemColumns()['oss_needs_manual_review']);
    }

    /**
     * Migrace 1292 doplnila do číselníku snížené sazby, které v 1152 chyběly. Bez nich
     * skončí maďarský doklad s 5 % bez typu sazby a `OssXmlExporter` ho do podání nepustí.
     *
     * @return list<array{0:string, 1:float, 2:string}>
     */
    public static function codebookGapsFilledIn1292(): array
    {
        return [
            'Maďarsko 5 %' => ['HU', 5.0, '2026-07-15'],
            'Slovensko 5 %' => ['SK', 5.0, '2026-07-15'],
        ];
    }

    #[DataProvider('codebookGapsFilledIn1292')]
    public function testCodebookKnowsRatesAddedByMigration1292(string $country, float $rate, string $onDate): void
    {
        $decision = $this->deriver->derive(
            $this->supplierId,
            new OssClientContext($country, true, null),
            $rate,
            'ks',
            $onDate,
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertNotNull($decision->rateType, 'sazbu musí potvrdit číselník, jinak řádek nepůjde podat');
        self::assertContains(OssDerivationReason::RateTypeFromCodebook, $decision->notes);
    }

    /** Vypnutý OSS je první brána, kterou musí projít i reálná data. */
    public function testSupplierWithoutOssIsNotOssOnRealSchema(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET oss_enabled = 0 WHERE id = ?')
            ->execute([$this->supplierId]);

        $decision = $this->deriver->derive(
            $this->supplierId,
            $this->deriver->clientContext($this->clientId),
            23.0,
            'kg',
            '2026-07-15',
            false,
        );

        self::assertFalse($decision->applicable);
        self::assertSame(OssDerivationReason::SupplierOssDisabled, $decision->reason);
    }

    /** Kaskáda resolveru běží na reálném `vat_rates` a tuzemskou sazbu najde. */
    public function testResolverFindsDomesticRateOnRealSchema(): void
    {
        $match = $this->resolver->resolve('CZ', 21.0, '2026-07-15');

        self::assertTrue($match->found(), 'CZ 21 % musí být na každé instalaci');
        self::assertSame('CZ', $match->country);
        self::assertContains(
            $match->status,
            [VatRateResolution::Matched, VatRateResolution::MatchedOutsideValidity],
        );
        self::assertNotNull($match->ratePercent);
        self::assertEqualsWithDelta(21.0, $match->ratePercent, VatRateResolver::EPSILON);
    }

    /**
     * Sazba, kterou tuzemská škála nezná, se NESMÍ napárovat na nejbližší českou.
     * Bez opravy dostávala polská 23% položka `vat_rate_id` sazby CZ-21.
     */
    public function testUnknownDomesticRateIsRejectedOnRealSchema(): void
    {
        $match = $this->resolver->resolve('CZ', 23.0, '2026-07-15');

        if ($match->found()) {
            self::assertSame('CZ', $this->rateCountry($match->id ?? 0), 'shoda smí být jen v požadované zemi');
            self::markTestSkipped('Instalace má vlastní tuzemskou sazbu 23 % — případ nelze změřit.');
        }

        self::assertSame(VatRateResolution::NoRateInCountry, $match->status);
        self::assertStringContainsString('CZ-23', $match->message);
    }

    private function rateCountry(int $rateId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT country FROM vat_rates WHERE id = ?');
        $stmt->execute([$rateId]);

        return strtoupper((string) $stmt->fetchColumn());
    }
}
