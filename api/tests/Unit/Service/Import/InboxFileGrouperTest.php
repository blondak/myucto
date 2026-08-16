<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\InboxFileGroup;
use MyInvoice\Service\Import\InboxFileGrouper;
use PHPUnit\Framework\TestCase;

/**
 * Seskupení souborů inboxu do zásilek (issue #16).
 *
 * Jádro věci: dvojice `faktura.pdf` + `faktura.isdoc` je JEDEN doklad. Dřív o tom
 * rozhodovalo abecední pořadí souborů, což u `.xml` (> `.pdf`) nefungovalo vůbec —
 * proto se tu na pořadí vstupu záměrně testuje obojí.
 */
final class InboxFileGrouperTest extends TestCase
{
    private const DIR = '/inbox';

    public function testIsdocAndPdfWithSameStemFormOnePair(): void
    {
        $groups = InboxFileGrouper::group([
            self::DIR . '/faktura.isdoc',
            self::DIR . '/faktura.pdf',
        ]);

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->isPaired());
        $this->assertSame(self::DIR . '/faktura.isdoc', $groups[0]->data);
        $this->assertSame(self::DIR . '/faktura.pdf', $groups[0]->pdf);
        $this->assertSame(self::DIR . '/faktura.isdoc', $groups[0]->primary());
    }

    /**
     * Regrese na hlavní vadu: `.xml` se řadí AŽ ZA `.pdf`, takže dřív šlo PDF na AI
     * jako první a data z XML dorazila do už obsazeného unikátního klíče.
     */
    public function testXmlPairsWithPdfDespiteSortingAfterIt(): void
    {
        $files = [self::DIR . '/faktura.pdf', self::DIR . '/faktura.xml'];
        sort($files, SORT_STRING);
        $this->assertSame(self::DIR . '/faktura.pdf', $files[0], 'předpoklad testu: .pdf se řadí před .xml');

        $groups = InboxFileGrouper::group($files);

        $this->assertCount(1, $groups);
        $this->assertSame(self::DIR . '/faktura.xml', $groups[0]->data);
        $this->assertSame(self::DIR . '/faktura.pdf', $groups[0]->pdf);
    }

    public function testPairingIsCaseInsensitive(): void
    {
        $groups = InboxFileGrouper::group([
            self::DIR . '/Faktura-2026-001.PDF',
            self::DIR . '/faktura-2026-001.isdoc',
        ]);

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->isPaired());
    }

    public function testDifferentDirectoriesNeverPair(): void
    {
        $groups = InboxFileGrouper::group([
            '/inbox/a/faktura.isdoc',
            '/inbox/b/faktura.pdf',
        ]);

        $this->assertCount(2, $groups);
        foreach ($groups as $g) {
            $this->assertFalse($g->isPaired());
        }
    }

    public function testDifferentStemsNeverPair(): void
    {
        $groups = InboxFileGrouper::group([
            self::DIR . '/faktura-1.isdoc',
            self::DIR . '/faktura-2.pdf',
        ]);

        $this->assertCount(2, $groups);
        $this->assertSame(self::DIR . '/faktura-1.isdoc', $groups[0]->data);
        $this->assertNull($groups[0]->pdf);
        $this->assertNull($groups[1]->data);
        $this->assertSame(self::DIR . '/faktura-2.pdf', $groups[1]->pdf);
    }

    /** `.isdocx` má přednost před `.isdoc` — jako jediný nese čitelné PDF uvnitř sebe. */
    public function testIsdocxWinsOverIsdocAsDataSource(): void
    {
        $groups = InboxFileGrouper::group([
            self::DIR . '/faktura.isdoc',
            self::DIR . '/faktura.isdocx',
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(self::DIR . '/faktura.isdocx', $groups[0]->data);
        $this->assertSame([self::DIR . '/faktura.isdoc'], $groups[0]->extras);
    }

    public function testIsdocxPairsWithExternalPdf(): void
    {
        $groups = InboxFileGrouper::group([
            self::DIR . '/faktura.isdocx',
            self::DIR . '/faktura.pdf',
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(self::DIR . '/faktura.isdocx', $groups[0]->data);
        $this->assertSame(self::DIR . '/faktura.pdf', $groups[0]->pdf);
    }

    public function testStandalonePdfStaysItsOwnGroup(): void
    {
        $groups = InboxFileGrouper::group([self::DIR . '/sken.pdf']);

        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]->data);
        $this->assertSame(self::DIR . '/sken.pdf', $groups[0]->pdf);
        $this->assertFalse($groups[0]->isPaired());
    }

    /**
     * Case-sensitive FS umí mít `X.pdf` i `x.pdf` v jednom adresáři. Který obraz
     * patří ke kterým datům, hádat nebudeme — nepáruje se nic.
     */
    public function testAmbiguousDuplicateExtensionDisablesPairing(): void
    {
        $groups = InboxFileGrouper::group([
            self::DIR . '/Faktura.pdf',
            self::DIR . '/faktura.isdoc',
            self::DIR . '/faktura.pdf',
        ]);

        $this->assertCount(3, $groups);
        foreach ($groups as $g) {
            $this->assertFalse($g->isPaired());
        }
    }

    public function testMembersCoverEveryInputFileExactlyOnce(): void
    {
        $files = [
            self::DIR . '/a.isdoc',
            self::DIR . '/a.pdf',
            self::DIR . '/a.xml',
            self::DIR . '/b.pdf',
            '/inbox/sub/c.isdocx',
        ];

        $seen = [];
        foreach (InboxFileGrouper::group($files) as $g) {
            foreach ($g->members() as $m) {
                $seen[] = $m;
            }
        }

        sort($files);
        sort($seen);
        $this->assertSame($files, $seen);
    }

    public function testGroupRequiresAtLeastOneFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InboxFileGroup(null, null);
    }
}
