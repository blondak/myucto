<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;
use MyInvoice\Service\Accounting\ChartOfAccountsTemplate;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\TestCase;

/**
 * Tvar analytických kódů je SMLUVNÍ (migrace 1322/1323): celá osnova i účetní
 * používají TEČKOVANÝ zápis. Bez téhle brány se snadno vrátí bezteččková varianta
 * (221100) — kód pak projde všemi testy, ale exporty a ruční kontrola proti hlavní
 * knize se rozejdou na něčem, co vypadá jako kosmetika.
 */
final class AnalyticCodeFormatTest extends TestCase
{
    public function testBankAnalyticCodeIsDotted(): void
    {
        self::assertSame('221.100', BankAnalyticAssigner::codeFor('100'));
        self::assertSame('221.007', BankAnalyticAssigner::codeFor('007'));
        self::assertSame('221', BankAnalyticAssigner::BANK_SYNTHETIC, 'Syntetika sama tečku nemá.');
    }

    /** Uložený suffix zůstává BEZ tečky — tečku přidává až codeFor(). */
    public function testSuffixItselfStaysDigitsOnly(): void
    {
        self::assertTrue(BankAnalyticAssigner::isValidSuffix('100'));
        self::assertTrue(BankAnalyticAssigner::isValidSuffix('7'));
        self::assertFalse(BankAnalyticAssigner::isValidSuffix('.100'));
        self::assertFalse(BankAnalyticAssigner::isValidSuffix('221.100'));
        self::assertFalse(BankAnalyticAssigner::isValidSuffix('1000000'), 'Sedm číslic se do varchar(10) s prefixem nevejde.');
    }

    public function testEveryCandidateProducesADottedCodeThatFitsTheColumn(): void
    {
        foreach (BankAnalyticAssigner::candidateSuffixes() as $suffix) {
            $code = BankAnalyticAssigner::codeFor($suffix);
            self::assertMatchesRegularExpression('/^221[.][0-9]{1,6}$/', $code);
            // chart_of_accounts.account_code je VARCHAR(10).
            self::assertLessThanOrEqual(10, strlen($code), "Kód {$code} se nevejde do account_code.");
        }
    }

    /** Šablona osnovy nesmí obsahovat bezteččkovou analytiku pod 211/221/343. */
    public function testTemplateHasNoDotlessBankCashOrVatAnalytics(): void
    {
        $offenders = [];
        foreach (ChartOfAccountsTemplate::ACCOUNTS as $account) {
            if (preg_match('/^(211|221|343)[0-9]+$/', (string) $account['code']) === 1) {
                $offenders[] = (string) $account['code'];
            }
        }
        self::assertSame([], $offenders, 'Analytiky banky/pokladny/DPH patří psát s tečkou.');
    }

    public function testTemplateContainsAllThreeVatAnalyticsUnderSynthetic343(): void
    {
        $byCode = [];
        foreach (ChartOfAccountsTemplate::ACCOUNTS as $account) {
            $byCode[(string) $account['code']] = $account;
        }

        foreach ([
            PostingService::INPUT_VAT_ACCOUNT      => 'Daň z přidané hodnoty vstup',
            PostingService::OUTPUT_VAT_ACCOUNT     => 'Daň z přidané hodnoty výstup',
            PostingService::VAT_SETTLEMENT_ACCOUNT => 'Daň z přidané hodnoty zúčtování',
        ] as $code => $name) {
            self::assertArrayHasKey($code, $byCode, "Šablona osnovy musí obsahovat {$code}.");
            self::assertSame($name, $byCode[$code]['name']);
            self::assertSame('343', $byCode[$code]['parent_code'] ?? null, "{$code} musí viset pod syntetikou 343.");
            self::assertSame('liability', $byCode[$code]['type']);
            self::assertNull(
                $byCode[$code]['normal_side'],
                'Analytiky DPH jsou saldní — dobropis i nadměrný odpočet je posílají na druhou stranu.',
            );
        }

        self::assertArrayHasKey(PostingService::VAT_SYNTHETIC, $byCode, 'Syntetika 343 v šabloně zůstává.');
    }

    /** Konstanty musí zůstat pod svou syntetikou — jinak přestane platit degradace na 343. */
    public function testVatConstantsShareTheSyntheticPrefix(): void
    {
        foreach ([
            PostingService::INPUT_VAT_ACCOUNT,
            PostingService::OUTPUT_VAT_ACCOUNT,
            PostingService::VAT_SETTLEMENT_ACCOUNT,
        ] as $code) {
            self::assertStringStartsWith(PostingService::VAT_SYNTHETIC, $code);
            self::assertNotSame(PostingService::VAT_SYNTHETIC, $code);
        }
        self::assertCount(3, array_unique([
            PostingService::INPUT_VAT_ACCOUNT,
            PostingService::OUTPUT_VAT_ACCOUNT,
            PostingService::VAT_SETTLEMENT_ACCOUNT,
        ]), 'Tři různé analytiky — jinak by je zúčtovací doklad nedokázal rozlišit.');
    }
}
