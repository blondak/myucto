<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Service\Oss\OssDerivationReason;
use MyInvoice\Service\Oss\OssItemDecision;
use MyInvoice\Service\Oss\OssTemplateItemPolicy;
use PHPUnit\Framework\TestCase;

/**
 * OSS na položce šablony opakované faktury (migrace 1297).
 *
 * Nález OSS-11: šablony OSS sloupce vůbec neměly, takže KAŽDÁ vygenerovaná faktura
 * vznikla bez OSS — u zákazníka, který polským spotřebitelům fakturuje měsíčně ze
 * šablony, by se tatáž chyba (cizí daň v českém přiznání) vyráběla dál cronem, i po
 * vyčištění historických dat.
 *
 * Testuje se pravidlo, ne generátor: policy je pure, takže se dá zamknout bez DB.
 */
final class OssTemplateItemPolicyTest extends TestCase
{
    // ── Co se uloží na šablonu ───────────────────────────────────────────────

    public function testCompleteOssRowIsStored(): void
    {
        $stored = OssTemplateItemPolicy::storedColumns([
            'oss_applicable' => 1,
            'oss_consumer_country' => 'pl',
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'goods',
        ]);

        self::assertSame([1, 'PL', 'standard', 'goods'], $stored);
    }

    /**
     * OSS bez země spotřeby se uloží jako NE-OSS. Uložit ho znamená vyrobit šablonu,
     * ze které cron měsíc co měsíc generuje položku, kterou validace dokladu odmítne —
     * a uživatel na to přijde tím, že mu přestanou chodit faktury.
     */
    public function testOssWithoutConsumerCountryIsStoredAsNonOss(): void
    {
        $stored = OssTemplateItemPolicy::storedColumns([
            'oss_applicable' => 1,
            'oss_consumer_country' => '',
            'oss_supply_type' => 'goods',
        ]);

        self::assertSame([0, null, null, null], $stored);
    }

    /** Prázdný typ sazby je legitimní — číselník ho nemusí znát a doplní se při generování. */
    public function testMissingRateTypeIsAllowedOnTemplate(): void
    {
        $stored = OssTemplateItemPolicy::storedColumns([
            'oss_applicable' => 1,
            'oss_consumer_country' => 'PL',
            'oss_rate_type' => 'nesmysl',
            'oss_supply_type' => 'goods',
        ]);

        self::assertSame([1, 'PL', null, 'goods'], $stored);
    }

    // ── Co z toho vznikne na vygenerované faktuře ────────────────────────────

    /**
     * Rozhodnutí člověka na šabloně přebíjí derivaci. Bez toho by se typ sazby, který
     * účetní dohledala, musel doplňovat na každé vygenerované faktuře znovu — a šablona
     * by k ničemu nebyla.
     */
    public function testTemplateDecisionWinsOverDerivation(): void
    {
        $columns = OssTemplateItemPolicy::generatedColumns(
            [
                'oss_applicable' => 1,
                'oss_consumer_country' => 'PL',
                'oss_rate_type' => 'reduced',
                'oss_supply_type' => 'goods',
            ],
            OssItemDecision::notApplicable(OssDerivationReason::ClientDomestic),
        );

        self::assertSame([
            'oss_applicable' => 1,
            'oss_consumer_country' => 'PL',
            'oss_rate_type' => 'reduced',
            'oss_supply_type' => 'goods',
            'oss_needs_manual_review' => 0,
        ], $columns);
    }

    /** Prázdný typ sazby si smí doplnit derivace — ale jen když mluví o TÉŽE zemi. */
    public function testDerivationFillsMissingRateTypeForSameCountry(): void
    {
        $columns = OssTemplateItemPolicy::generatedColumns(
            ['oss_applicable' => 1, 'oss_consumer_country' => 'PL', 'oss_supply_type' => 'goods'],
            OssItemDecision::oss('PL', 'standard', 'services'),
        );

        self::assertSame('standard', $columns['oss_rate_type']);
        self::assertSame('goods', $columns['oss_supply_type'], 'typ plnění ze šablony se nepřepisuje');
    }

    public function testDerivationDoesNotFillRateTypeFromAnotherCountry(): void
    {
        $columns = OssTemplateItemPolicy::generatedColumns(
            ['oss_applicable' => 1, 'oss_consumer_country' => 'PL', 'oss_supply_type' => 'goods'],
            OssItemDecision::oss('NL', 'standard', 'services'),
        );

        self::assertNull($columns['oss_rate_type'], 'typ sazby jiného státu je typ jiné sazby');
    }

    /** Šablona mlčí (typicky založená před migrací 1297) → rozhoduje derivace. */
    public function testSilentTemplateFallsBackToDerivation(): void
    {
        $columns = OssTemplateItemPolicy::generatedColumns(
            [],
            OssItemDecision::oss('PL', 'standard', 'goods', [], OssDerivationReason::B2cEuConsumer),
        );

        self::assertSame(1, $columns['oss_applicable']);
        self::assertSame('PL', $columns['oss_consumer_country']);
        self::assertSame(0, $columns['oss_needs_manual_review']);
    }

    /** Nejednoznačné místo plnění se přenese včetně příznaku k ručnímu posouzení. */
    public function testAmbiguousDerivationCarriesManualReviewFlag(): void
    {
        $columns = OssTemplateItemPolicy::generatedColumns(
            [],
            OssItemDecision::oss('NL', 'standard', 'services', [], OssDerivationReason::RateAmbiguousDomesticOrConsumer),
        );

        self::assertSame(1, $columns['oss_needs_manual_review']);
    }

    /**
     * ODMÍTNUTÍ nesmí zastavit cron. Chybějící číselník (neproběhlá migrace 1152)
     * odmítne každý řádek se sazbou > 0 %, tedy i ryze českou šablonu — a zastavil by
     * tím zákazníkovi celou fakturaci. Řádek proto zůstane mimo OSS, ale MUSÍ nést
     * příznak k ručnímu posouzení: bez něj by vypadal jako rozhodnutý.
     */
    public function testRejectionDegradesToManualReviewInsteadOfBlockingGeneration(): void
    {
        $columns = OssTemplateItemPolicy::generatedColumns(
            [],
            OssItemDecision::rejected(OssDerivationReason::ClientHasVatId, 'Sazbu 23 % nelze ověřit…'),
        );

        self::assertSame(0, $columns['oss_applicable']);
        self::assertSame(1, $columns['oss_needs_manual_review']);
    }

    // ── Registrace do OSS ke dni plnění GENEROVANÉHO dokladu ─────────────────

    /**
     * Šablona se zakládá jednou a generuje roky; registrace do OSS mezitím může skončit.
     * Uložené rozhodnutí je pak rozhodnutím o jiném období — a řádek s `oss_applicable = 1`
     * mimo registraci by nespadl do ŽÁDNÉHO přiznání: z OSS podání ho vyřadí platnost
     * registrace, z tuzemského OSS příznak. Daň by tiše zmizela z obou stran.
     */
    public function testExpiredOssRegistrationOverridesStoredTemplateDecision(): void
    {
        $columns = OssTemplateItemPolicy::generatedColumns(
            [
                'oss_applicable' => 1,
                'oss_consumer_country' => 'PL',
                'oss_rate_type' => 'standard',
                'oss_supply_type' => 'goods',
            ],
            OssItemDecision::notApplicable(OssDerivationReason::SupplierOssNotValidOnDate),
        );

        self::assertSame(0, $columns['oss_applicable']);
        self::assertNull($columns['oss_consumer_country']);
        self::assertSame(
            1,
            $columns['oss_needs_manual_review'],
            'přebití rozhodnutí člověka nesmí být tiché',
        );
    }

    /** Vypnutý režim OSS v nastavení firmy je totéž jako prošlá platnost. */
    public function testDisabledOssModeOverridesStoredTemplateDecision(): void
    {
        $columns = OssTemplateItemPolicy::generatedColumns(
            ['oss_applicable' => 1, 'oss_consumer_country' => 'PL', 'oss_supply_type' => 'goods'],
            OssItemDecision::notApplicable(OssDerivationReason::SupplierOssDisabled),
        );

        self::assertSame(0, $columns['oss_applicable']);
        self::assertSame(1, $columns['oss_needs_manual_review']);
    }

    /**
     * Mimo registraci a zároveň bez potvrzení číselníku: řádek nemůže být ani OSS, ani
     * tuzemský. Cron doklad zahodit nesmí, takže platí táž degradace jako jinde —
     * mimo OSS, s příznakem.
     */
    public function testExpiredRegistrationWithRejectedDerivationStillGeneratesFlaggedLine(): void
    {
        $columns = OssTemplateItemPolicy::generatedColumns(
            ['oss_applicable' => 1, 'oss_consumer_country' => 'PL', 'oss_supply_type' => 'goods'],
            OssItemDecision::rejected(
                OssDerivationReason::SupplierOssNotValidOnDate,
                'Sazba 23 % podle číselníku v zemi dodavatele (CZ) neplatí…',
            ),
        );

        self::assertSame(0, $columns['oss_applicable']);
        self::assertSame(1, $columns['oss_needs_manual_review']);
    }

    /**
     * Výjimka je ÚZKÁ. Ostatní blokující důvody mluví o DODÁVCE, ne o tom, jestli firma
     * smí OSS použít — tam uložené rozhodnutí člověka platí dál, jinak by šablona
     * přestala fungovat u každého odběratele, kterému derivace nerozumí.
     */
    public function testOtherBlockingReasonsDoNotOverrideStoredTemplateDecision(): void
    {
        $columns = OssTemplateItemPolicy::generatedColumns(
            [
                'oss_applicable' => 1,
                'oss_consumer_country' => 'PL',
                'oss_rate_type' => 'standard',
                'oss_supply_type' => 'goods',
            ],
            OssItemDecision::notApplicable(OssDerivationReason::ClientHasVatId),
        );

        self::assertSame(1, $columns['oss_applicable']);
        self::assertSame('PL', $columns['oss_consumer_country']);
        self::assertSame(0, $columns['oss_needs_manual_review']);
    }

    /** Kanál, který doklad zahodit SMÍ, si degradaci může vypnout. */
    public function testRejectionCanBeEscalatedWhenChannelMayDropTheDocument(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sazbu 23 % nelze ověřit…');

        OssTemplateItemPolicy::generatedColumns(
            [],
            OssItemDecision::rejected(OssDerivationReason::ClientHasVatId, 'Sazbu 23 % nelze ověřit…'),
            degradeRejection: false,
        );
    }
}
