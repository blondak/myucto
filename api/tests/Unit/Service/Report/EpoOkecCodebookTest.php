<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use DateTimeImmutable;
use MyInvoice\Service\Report\EpoOkecCodebook;
use PHPUnit\Framework\TestCase;

/**
 * Kanonizace a platnost CZ-NACE kódů proti snapshotu číselníku ČINNOSTI
 * (OKEC) z Daňového portálu — api/resources/ciselniky/okec.txt.
 *
 * Číselník se k 1. 1. 2026 překlopil na NACE rev. 2.1: starší kódy mají
 * d_ukopl 2025-12-31 (např. 620200 „poradenství v IT", nástupce 622000).
 * Kódy sekcí 01–09 jsou v číselníku uloženy numericky bez vodicí nuly
 * („14800" = 01.48.00). Testy používají pevné referenční datum, aby
 * nezávisely na dnešku; hodnoty odpovídají snapshotu z 07/2026.
 */
final class EpoOkecCodebookTest extends TestCase
{
    private static function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    public function testActiveClassCodeIsPaddedToCodebookValue(): void
    {
        $r = EpoOkecCodebook::normalize('73.11', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame('731100', $r['code']);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r['status']);
        self::assertNotSame('', (string) $r['name']);
    }

    public function testCanonicalCodebookValueStaysUntouched(): void
    {
        $r = EpoOkecCodebook::normalize('731100', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame('731100', $r['code']);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r['status']);
    }

    public function testLeadingZeroSectionResolvesToNumericCanonicalForm(): void
    {
        // 01.48 (chov ostatních zvířat) je v číselníku uložen jako „14800".
        foreach (['0148', '01480', '014800', '14800'] as $input) {
            $r = EpoOkecCodebook::normalize($input, self::at('2026-07-28'));
            self::assertNotNull($r, "vstup $input");
            self::assertSame('14800', $r['code'], "vstup $input");
            self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r['status'], "vstup $input");
        }
    }

    /**
     * Dvojznačná 5místná hodnota: „31000" je ČSÚ zápis třídy 31.00 (výroba
     * nábytku) i kanonický kód sekce 03.10.00 (rybolov). Vyhrát musí ČSÚ
     * výklad — přesně ten posílá ARES prefill (czNace ['31','310','31000']).
     * Kdo myslí rybolov, zadá kód s vodicí nulou a trefí se taky.
     */
    public function testFiveDigitCsuNotationWinsOverBareSectionCode(): void
    {
        $furniture = EpoOkecCodebook::normalize('31000', self::at('2026-07-28'));
        self::assertNotNull($furniture);
        self::assertSame('310000', $furniture['code']);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $furniture['status']);
        self::assertStringContainsStringIgnoringCase('nábytku', (string) $furniture['name']);

        foreach (['03100', '031000', '03.10.0'] as $input) {
            $fishing = EpoOkecCodebook::normalize($input, self::at('2026-07-28'));
            self::assertNotNull($fishing, "vstup $input");
            self::assertSame('31000', $fishing['code'], "vstup $input");
            self::assertStringContainsStringIgnoringCase('rybolov', (string) $fishing['name'], "vstup $input");
        }
    }

    /** Záznam, jehož platnost teprve začne, se nesmí hlásit jako expirovaný s prázdným datem. */
    public function testNotYetValidRecordIsNotReportedAsExpired(): void
    {
        // 622000 platí od 2026-01-01 a nemá konec platnosti.
        $r = EpoOkecCodebook::normalize('622000', self::at('2025-06-30'));
        self::assertNotNull($r);
        self::assertSame('622000', $r['code']);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r['status']);
        self::assertNull($r['valid_to']);
    }

    public function testNace20CodeIsExpiredAfterSwitchToNace21(): void
    {
        // Přesně případ issue #157: 62020/620200 bylo do 31. 12. 2025 platné,
        // NACE rev. 2.1 ho nahradila kódem 622000. Odmítnutí v EPO způsobila
        // expirace, ne šířka kódu — „62020" v číselníku nikdy nebylo.
        $r = EpoOkecCodebook::normalize('62020', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame('620200', $r['code']);
        self::assertSame(EpoOkecCodebook::STATUS_EXPIRED, $r['status']);
        self::assertSame('2025-12-31', $r['valid_to']);

        // Totéž datum před přechodem: kód byl platný.
        $r2 = EpoOkecCodebook::normalize('62020', self::at('2025-06-30'));
        self::assertNotNull($r2);
        self::assertSame('620200', $r2['code']);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r2['status']);
    }

    public function testSuccessorCodeIsActive(): void
    {
        $r = EpoOkecCodebook::normalize('622000', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r['status']);
    }

    public function testUnknownCodeKeepsPaddedFormWithUnknownStatus(): void
    {
        $r = EpoOkecCodebook::normalize('9999', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame('999900', $r['code']);
        self::assertSame(EpoOkecCodebook::STATUS_UNKNOWN, $r['status']);
        self::assertNull($r['name']);
    }

    public function testSectionOnlyInputReturnsNull(): void
    {
        self::assertNull(EpoOkecCodebook::normalize('74', self::at('2026-07-28')));
        self::assertNull(EpoOkecCodebook::normalize('7.4', self::at('2026-07-28')));
        self::assertNull(EpoOkecCodebook::normalize('', self::at('2026-07-28')));
    }

    // ── describe() — podklad pro UI (název činnosti + stav platnosti) ──────────

    public function testDescribeAddsReadableDisplayAndName(): void
    {
        $r = EpoOkecCodebook::describe('73.11', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame('731100', $r['code']);
        self::assertSame('73.11.00', $r['display']);
        self::assertSame('Činnosti reklamních agentur', $r['name']);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r['status']);
    }

    public function testDescribeSectionCodeShowsLeadingZeroInDisplay(): void
    {
        // Číselník vede sekce 01–09 bez vodicí nuly („14800"), uživateli ale
        // musíme ukázat čitelnou třídu 01.48.00.
        $r = EpoOkecCodebook::describe('14800', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame('14800', $r['code']);
        self::assertSame('01.48.00', $r['display']);
    }

    public function testDescribeReportsExpiredCodeWithEndOfValidity(): void
    {
        $r = EpoOkecCodebook::describe('62020', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame(EpoOkecCodebook::STATUS_EXPIRED, $r['status']);
        self::assertSame('2025-12-31', $r['valid_to']);
    }

    public function testDescribeEmptyInputIsNull(): void
    {
        self::assertNull(EpoOkecCodebook::describe(null));
        self::assertNull(EpoOkecCodebook::describe('  '));
        self::assertNull(EpoOkecCodebook::describe('74')); // oddíl z ARES
    }

    // ── search() — našeptávač v Nastavení ────────────────────────────────────

    public function testSearchByCodePrefixReturnsOnlyMatchingClasses(): void
    {
        $hits = EpoOkecCodebook::search('73.1', 50, self::at('2026-07-28'));
        self::assertNotSame([], $hits);
        foreach ($hits as $h) {
            self::assertStringStartsWith('731', $h['code']);
        }
        self::assertContains('731100', array_column($hits, 'code'));
    }

    public function testSearchByNameIgnoresCaseAndDiacritics(): void
    {
        $hits = EpoOkecCodebook::search('ucetni', 50, self::at('2026-07-28'));
        self::assertNotSame([], $hits);
        foreach ($hits as $h) {
            self::assertStringContainsString('četni', mb_strtolower($h['name']));
        }
    }

    /**
     * Klíčová vlastnost našeptávače: expirovaný kód se NENABÍZÍ. Kdyby ano,
     * naváděl by uživatele přesně na propustnou chybu 30, kvůli které vznikl
     * (620200 „poradenství v IT" skončilo 2025-12-31, nástupce je 622000).
     */
    public function testSearchOffersOnlyCodesValidAtGivenDate(): void
    {
        $codes = array_column(EpoOkecCodebook::search('6202', 50, self::at('2026-07-28')), 'code');
        self::assertNotContains('620200', $codes);

        $before = array_column(EpoOkecCodebook::search('6202', 50, self::at('2025-06-30')), 'code');
        self::assertContains('620200', $before);
    }

    public function testSearchFindsSectionCodeByLeadingZeroPrefix(): void
    {
        // Uživatel i ARES píšou „01.48", číselník má „14800" — musí se potkat.
        $codes = array_column(EpoOkecCodebook::search('01.48', 50, self::at('2026-07-28')), 'code');
        self::assertContains('14800', $codes);
    }

    public function testSearchRespectsLimitAndEmptyQueryReturnsFirstPage(): void
    {
        $hits = EpoOkecCodebook::search('', 5, self::at('2026-07-28'));
        self::assertCount(5, $hits);
        foreach ($hits as $h) {
            self::assertArrayHasKey('display', $h);
            self::assertNotSame('', $h['name']);
        }
    }

    public function testSearchWithNoMatchReturnsEmptyList(): void
    {
        self::assertSame([], EpoOkecCodebook::search('xyzzy nic takového', 20, self::at('2026-07-28')));
    }

    public function testHumanizeNameTurnsCodebookCapsIntoSentence(): void
    {
        self::assertSame('Činnosti reklamních agentur', EpoOkecCodebook::humanizeName('ČINNOSTI REKLAMNÍCH AGENTUR'));
    }
}
