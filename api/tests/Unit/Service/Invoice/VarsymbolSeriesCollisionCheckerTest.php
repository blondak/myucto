<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\VarsymbolSeriesCollisionChecker;
use PHPUnit\Framework\TestCase;

/**
 * Featura G (private/REAL_data_followup_UX.md) — pure logika kolize VS mezi číselnými
 * řadami, bez DB. Reálný incident z produkce: proforma `Z{YY}{MM}{CCC}` a faktura
 * `{YY}{MM}{CCC}` po normalizaci na číslice (StatementMatcher) srovnají na totéž VS.
 */
final class VarsymbolSeriesCollisionCheckerTest extends TestCase
{
    public function testLetterPrefixCollidesWithBareTemplate(): void
    {
        // Přesně dnešní produkční incident — proforma s literálem 'Z' (písmeno, ne
        // číslice) kolabuje po normalizaci na stejný VS jako faktura bez prefixu.
        self::assertTrue(VarsymbolSeriesCollisionChecker::templatesCollide(
            'Z{YY}{MM}{CCC}',
            '{YY}{MM}{CCC}',
        ));
    }

    public function testDashAndSlashLiteralsAlsoCollide(): void
    {
        self::assertTrue(VarsymbolSeriesCollisionChecker::templatesCollide(
            'F-{YY}/{MM}-{CCCC}',
            '{YY}{MM}{CCCC}',
        ));
    }

    public function testDigitPrefixesAreDisjointNotFlagged(): void
    {
        // Výchozí šablony aplikace (9=proforma, 7=dobropis, bez prefixu=faktura) —
        // disjunktní číselné prefixy, žádná kolize.
        self::assertFalse(VarsymbolSeriesCollisionChecker::templatesCollide(
            '9{YY}{MM}{CCC}',
            '{YY}{MM}{CCC}',
        ));
        self::assertFalse(VarsymbolSeriesCollisionChecker::templatesCollide(
            '9{YY}{MM}{CCC}',
            '7{YY}{MM}{CCC}',
        ));
    }

    public function testDifferentYearWidthNotFlagged(): void
    {
        self::assertFalse(VarsymbolSeriesCollisionChecker::templatesCollide(
            '{YYYY}{MM}{CCC}',
            '{YY}{MM}{CCC}',
        ));
    }

    public function testDifferentCounterWidthNotFlagged(): void
    {
        // Vědomé omezení (viz doc-blok digitSkeleton) — jiná šířka počítadla se
        // nebere jako skutečná kolize, i když teoreticky pro malé hodnoty counteru
        // po CAST(...AS UNSIGNED) může dojít k číselné shodě.
        self::assertFalse(VarsymbolSeriesCollisionChecker::templatesCollide(
            '{YY}{MM}{CCC}',
            '{YY}{MM}{CCCC}',
        ));
    }

    public function testIdenticalTemplatesCollide(): void
    {
        self::assertTrue(VarsymbolSeriesCollisionChecker::templatesCollide(
            '9{YY}{MM}{CCC}',
            '9{YY}{MM}{CCC}',
        ));
    }

    public function testEmptyTemplateNeverCollides(): void
    {
        self::assertFalse(VarsymbolSeriesCollisionChecker::templatesCollide('', '{YY}{MM}{CCC}'));
        self::assertFalse(VarsymbolSeriesCollisionChecker::templatesCollide('', ''));
    }

    public function testPurchasePrefixPlaceholderIsDropped(): void
    {
        // {PP} (daňový prefix přijatých faktur PF/PN/KU/KN/NU/NN) je vždy písmenný —
        // normalizace ho odstraní stejně jako libovolný jiný literál.
        self::assertTrue(VarsymbolSeriesCollisionChecker::templatesCollide(
            '{PP}{YY}{MM}{CCC}',
            '{YY}{MM}{CCC}',
        ));
    }

    public function testFindCollisionsAcrossSeriesSet(): void
    {
        $series = [
            ['type' => 'invoice', 'client_id' => null, 'client_name' => null, 'template' => '{YY}{MM}{CCC}'],
            ['type' => 'proforma', 'client_id' => null, 'client_name' => null, 'template' => 'Z{YY}{MM}{CCC}'],
            ['type' => 'credit_note', 'client_id' => null, 'client_name' => null, 'template' => '7{YY}{MM}{CCC}'],
        ];

        $collisions = VarsymbolSeriesCollisionChecker::findCollisions($series);

        self::assertCount(1, $collisions);
        self::assertSame('invoice', $collisions[0]['a']['type']);
        self::assertSame('proforma', $collisions[0]['b']['type']);
    }

    public function testFindCollisionsCleanWhenDisjoint(): void
    {
        $series = [
            ['type' => 'invoice', 'client_id' => null, 'client_name' => null, 'template' => '{YY}{MM}{CCC}'],
            ['type' => 'proforma', 'client_id' => null, 'client_name' => null, 'template' => '9{YY}{MM}{CCC}'],
            ['type' => 'credit_note', 'client_id' => null, 'client_name' => null, 'template' => '7{YY}{MM}{CCC}'],
        ];

        self::assertSame([], VarsymbolSeriesCollisionChecker::findCollisions($series));
    }

    public function testFindCollisionsFlagsPerClientOverrideAgainstSupplierWide(): void
    {
        $series = [
            ['type' => 'invoice', 'client_id' => null, 'client_name' => null, 'template' => '{YY}{MM}{CCC}'],
            ['type' => 'proforma', 'client_id' => 7, 'client_name' => 'ACME s.r.o.', 'template' => 'AC-{YY}{MM}{CCC}'],
        ];

        $collisions = VarsymbolSeriesCollisionChecker::findCollisions($series);

        self::assertCount(1, $collisions);
        self::assertSame(7, $collisions[0]['b']['client_id']);
    }
}
