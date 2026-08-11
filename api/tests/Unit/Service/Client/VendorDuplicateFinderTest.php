<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Client;

use MyInvoice\Service\Client\VendorDuplicateFinder;
use PHPUnit\Framework\TestCase;

/**
 * FR 2 (vendor bugreport 2026-08-06) — pure-logic testy pro seskupení/dohledání
 * duplicitních karet dodavatele podle normalizovaného IČO/DIČ. Žádná DB — repository
 * volá stejnou funkci nad řádky z `clients` (viz ClientRepository::findDuplicateGroups()
 * / findDuplicateCandidates()).
 */
final class VendorDuplicateFinderTest extends TestCase
{
    // ── findGroups() — report v přehledu dodavatelů ─────────────────────────

    public function testFindGroups_leadingZeroIcFormsGroup(): void
    {
        $clients = [
            ['id' => 1, 'company_name' => 'Firma A', 'ic' => '1234567', 'dic' => null],
            ['id' => 2, 'company_name' => 'Firma A s.r.o.', 'ic' => '01234567', 'dic' => null],
        ];
        $groups = VendorDuplicateFinder::findGroups($clients);

        self::assertCount(1, $groups);
        self::assertSame('ic', $groups[0]['key_type']);
        self::assertSame('01234567', $groups[0]['key_value']);
        self::assertCount(2, $groups[0]['clients']);
    }

    public function testFindGroups_dicWithSpaceFormsGroup(): void
    {
        $clients = [
            ['id' => 1, 'company_name' => 'Firma B', 'ic' => null, 'dic' => 'CZ 87654321'],
            ['id' => 2, 'company_name' => 'Firma B s.r.o.', 'ic' => null, 'dic' => 'CZ87654321'],
        ];
        $groups = VendorDuplicateFinder::findGroups($clients);

        self::assertCount(1, $groups);
        self::assertSame('dic', $groups[0]['key_type']);
        self::assertSame('CZ87654321', $groups[0]['key_value']);
    }

    public function testFindGroups_noDuplicatesReturnsEmpty(): void
    {
        $clients = [
            ['id' => 1, 'company_name' => 'Firma A', 'ic' => '11111111', 'dic' => null],
            ['id' => 2, 'company_name' => 'Firma B', 'ic' => '22222222', 'dic' => null],
        ];
        self::assertSame([], VendorDuplicateFinder::findGroups($clients));
    }

    public function testFindGroups_emptyIcAndDicIgnored(): void
    {
        $clients = [
            ['id' => 1, 'company_name' => 'Firma A', 'ic' => null, 'dic' => null],
            ['id' => 2, 'company_name' => 'Firma B', 'ic' => '', 'dic' => ''],
        ];
        self::assertSame([], VendorDuplicateFinder::findGroups($clients));
    }

    public function testFindGroups_clientMatchingByBothIcAndDicYieldsTwoGroups(): void
    {
        // Tři karty: 1+2 sdílí IČO, 2+3 sdílí DIČ — samostatná IČO i DIČ skupina.
        $clients = [
            ['id' => 1, 'company_name' => 'A', 'ic' => '11111111', 'dic' => null],
            ['id' => 2, 'company_name' => 'B', 'ic' => '11111111', 'dic' => 'CZ22222222'],
            ['id' => 3, 'company_name' => 'C', 'ic' => null, 'dic' => 'CZ 222 222 22'],
        ];
        $groups = VendorDuplicateFinder::findGroups($clients);
        self::assertCount(2, $groups);

        $types = array_column($groups, 'key_type');
        self::assertContains('ic', $types);
        self::assertContains('dic', $types);
    }

    public function testFindGroups_threeWayDuplicateSingleGroup(): void
    {
        $clients = [
            ['id' => 1, 'company_name' => 'A', 'ic' => '11111111', 'dic' => null],
            ['id' => 2, 'company_name' => 'B', 'ic' => '011111110', 'dic' => null], // liší se skutečně (delší) → NEshoduje
            ['id' => 3, 'company_name' => 'C', 'ic' => '11111111', 'dic' => null],
        ];
        $groups = VendorDuplicateFinder::findGroups($clients);
        self::assertCount(1, $groups);
        self::assertCount(2, $groups[0]['clients']); // jen 1 a 3, karta 2 má jiné IČO
    }

    // ── findMatches() — guard při zakládání/editaci jedné karty ─────────────

    public function testFindMatches_findsExistingByNormalizedIc(): void
    {
        $clients = [
            ['id' => 5, 'company_name' => 'Existující s.r.o.', 'ic' => '1234567', 'dic' => null],
        ];
        $matches = VendorDuplicateFinder::findMatches($clients, '01234567', null);

        self::assertCount(1, $matches);
        self::assertSame(5, $matches[0]['id']);
        self::assertSame('ic', $matches[0]['match_field']);
    }

    public function testFindMatches_findsExistingByNormalizedDic(): void
    {
        $clients = [
            ['id' => 7, 'company_name' => 'Existující DIČ s.r.o.', 'ic' => null, 'dic' => 'CZ 87654321'],
        ];
        $matches = VendorDuplicateFinder::findMatches($clients, null, 'CZ87654321');

        self::assertCount(1, $matches);
        self::assertSame(7, $matches[0]['id']);
        self::assertSame('dic', $matches[0]['match_field']);
    }

    public function testFindMatches_excludesGivenClientId(): void
    {
        // Editace karty #5: samotná karta se nesmí ohlásit jako "duplicita sama sebe".
        $clients = [
            ['id' => 5, 'company_name' => 'Karta', 'ic' => '01234567', 'dic' => null],
        ];
        self::assertSame([], VendorDuplicateFinder::findMatches($clients, '01234567', null, excludeClientId: 5));
    }

    public function testFindMatches_noIcOrDicReturnsEmpty(): void
    {
        $clients = [['id' => 1, 'company_name' => 'X', 'ic' => '11111111', 'dic' => null]];
        self::assertSame([], VendorDuplicateFinder::findMatches($clients, null, null));
    }

    public function testFindMatches_differentIcNoMatch(): void
    {
        $clients = [['id' => 1, 'company_name' => 'X', 'ic' => '11111111', 'dic' => null]];
        self::assertSame([], VendorDuplicateFinder::findMatches($clients, '22222222', null));
    }

    public function testFindMatches_icTakesPriorityOverDicWhenBothMatchSameRow(): void
    {
        $clients = [
            ['id' => 9, 'company_name' => 'Obojí', 'ic' => '11111111', 'dic' => 'CZ11111111'],
        ];
        $matches = VendorDuplicateFinder::findMatches($clients, '11111111', 'CZ11111111');
        self::assertCount(1, $matches, 'Jeden řádek se nesmí nahlásit dvakrát, i když sedí obě pole.');
        self::assertSame('ic', $matches[0]['match_field']);
    }
}
