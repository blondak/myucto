<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\CzIscoCodebook;
use PHPUnit\Framework\TestCase;

final class CzIscoCodebookTest extends TestCase
{
    public function testFindsByCodePrefixAndPutsExactMatchFirst(): void
    {
        $items = $this->codebook()->search('2411', 10);

        self::assertNotSame([], $items);
        self::assertSame('2411', $items[0]['code'], 'Přesná shoda kódu patří na první místo.');
        self::assertSame('Specialisté v oblasti účetnictví', $items[0]['label']);
        foreach ($items as $item) {
            self::assertStringStartsWith('2411', $item['code']);
        }
    }

    public function testFindsByNameAndReturnsParentContext(): void
    {
        $items = $this->codebook()->search('Účetní všeobecní', 10);

        self::assertSame('43111', $items[0]['code']);
        self::assertSame(5, $items[0]['level']);
        self::assertSame('4311', $items[0]['parent_code']);
        self::assertSame('Úředníci v oblasti účetnictví', $items[0]['parent_label']);
    }

    /** Uživatel píše bez diakritiky a stejně musí najít „Účetní". */
    public function testSearchIgnoresDiacriticsAndCase(): void
    {
        $folded = array_column($this->codebook()->search('ucetni', 20), 'code');
        $exact = array_column($this->codebook()->search('ÚČETNÍ', 20), 'code');

        self::assertContains('43111', $folded);
        self::assertSame($exact, $folded, 'Diakritika ani velikost písmen nesmí měnit výsledek.');
    }

    public function testRejectsEmptyQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('alespoň 2 znaky');
        $this->codebook()->search('', 10);
    }

    public function testRejectsTooShortQueryIncludingWhitespaceOnly(): void
    {
        foreach (['a', '4', '   ', " \n"] as $query) {
            try {
                $this->codebook()->search($query, 10);
                self::fail("Dotaz " . var_export($query, true) . ' měl být odmítnut.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('alespoň 2 znaky', $e->getMessage());
            }
        }
    }

    public function testRejectsLimitOutsideRange(): void
    {
        foreach ([0, -1, CzIscoCodebook::MAX_SEARCH_LIMIT + 1] as $limit) {
            try {
                $this->codebook()->search('ucetni', $limit);
                self::fail("Limit {$limit} měl být odmítnut.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Limit', $e->getMessage());
            }
        }
    }

    public function testHonoursLimitAndOffersOnlySelectableLevels(): void
    {
        $items = $this->codebook()->search('pracovnic', 7);

        self::assertCount(7, $items);
        foreach ($items as $item) {
            self::assertContains(
                $item['level'],
                CzIscoCodebook::SELECTABLE_LEVELS,
                'Našeptávač nesmí nabízet hlavní třídy — ty do pole nepatří.',
            );
        }
    }

    public function testKnowsActiveRetiredAndUnknownCodes(): void
    {
        $codebook = $this->codebook();

        self::assertSame(CzIscoCodebook::STATUS_ACTIVE, $codebook->status('43111'));
        self::assertSame(CzIscoCodebook::STATUS_ACTIVE, $codebook->status('2512'));
        self::assertSame(CzIscoCodebook::STATUS_RETIRED, $codebook->status('32114'));
        self::assertSame(CzIscoCodebook::STATUS_UNKNOWN, $codebook->status('43110'));
        self::assertSame(CzIscoCodebook::STATUS_UNKNOWN, $codebook->status('99999'));
        self::assertNull($codebook->find('99999'));
    }

    /** Kódy hlavní třídy 0 mají vodicí nulu a nesmí se cestou ztratit. */
    public function testKeepsLeadingZeroCodes(): void
    {
        $entry = $this->codebook()->find('03101');

        self::assertNotNull($entry);
        self::assertSame('03101', $entry['code']);
        self::assertSame(5, $entry['level']);
        self::assertSame('0310', $entry['parent_code']);
    }

    public function testProvenanceNamesSourceLicenceAndVersion(): void
    {
        $provenance = $this->codebook()->provenance();

        self::assertSame(CzIscoCodebook::PACKAGE_KEY, $provenance['package_key']);
        self::assertSame(CzIscoCodebook::DEFAULT_MANIFEST_SHA256, $provenance['manifest_sha256']);
        self::assertSame('2026-02-01', $provenance['classification_version']);
        self::assertSame('CC BY 4.0', $provenance['licence']);
        self::assertStringContainsString('csu.gov.cz', $provenance['source_url']);
    }

    private function codebook(): CzIscoCodebook
    {
        return new CzIscoCodebook();
    }
}
