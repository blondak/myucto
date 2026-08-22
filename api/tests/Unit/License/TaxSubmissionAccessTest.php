<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\License;

use MyInvoice\Service\License\CommercialFeatureAccess;
use MyInvoice\Service\License\TaxSubmissionAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kde přesně vede hranice u daňových výkazů.
 *
 * ⚠️ Archiv podání byl zamčený celý, přestože bezplatná část výslovně zahrnuje
 * přiznání k DPH i kontrolní hlášení. Zákazník bez licence si výkaz
 * vygeneroval, aplikace ho poslala do archivu pro stažení XML — a tam ho
 * vyhodila na aktivační obrazovku. Výkaz tedy fakticky nešlo dostat ven.
 *
 * Dělicí čára není „výkaz vs. archiv", ale XML vs. ODESLÁNÍ: soubor si zákazník
 * stáhne a podá sám na portálu, přímé podání do EPO je placená služba.
 */
final class TaxSubmissionAccessTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function freeForms(): iterable
    {
        yield 'přiznání k DPH'    => ['dphdp3'];
        yield 'kontrolní hlášení' => ['dphkh1'];
        yield 'souhrnné hlášení'  => ['dphshv'];
    }

    #[DataProvider('freeForms')]
    public function testVatFormsBelongToTheFreeTier(string $formCode): void
    {
        self::assertTrue(TaxSubmissionAccess::isFreeForm($formCode));
        self::assertTrue(TaxSubmissionAccess::isFreeForm(strtoupper($formCode)), 'velikost písmen nerozhoduje');
    }

    /**
     * OSS je celý za licencí, takže ani jeho podání do bezplatné části nepatří.
     * Bez tohohle tvrzení by test výš prošel i s implementací, která pouští vše.
     */
    public function testOssStaysBehindTheLicence(): void
    {
        self::assertFalse(TaxSubmissionAccess::isFreeForm('ossei1'));
    }

    /** Neznámý výkaz je placený — nový se musí přidat vědomě. */
    public function testUnknownFormDefaultsToPaid(): void
    {
        self::assertFalse(TaxSubmissionAccess::isFreeForm('novy-vykaz'));
        self::assertFalse(TaxSubmissionAccess::isFreeForm(null));
        self::assertFalse(TaxSubmissionAccess::isFreeForm(''));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function archivePaths(): iterable
    {
        yield 'seznam podání'      => ['/api/reports/submissions', false];
        yield 'detail podání'      => ['/api/reports/submissions/12', false];
        yield 'stažení XML'        => ['/api/reports/submissions/12/xml', false];
        yield 'označení podaného'  => ['/api/reports/submissions/12/submit', false];
        yield 'nastavení EPO'      => ['/api/reports/submissions/settings', true];
        yield 'certifikáty EPO'    => ['/api/reports/submissions/epo-credentials', true];
        yield 'předání do EPO'     => ['/api/reports/submissions/12/epo-handoff', true];
        yield 'přímé odeslání'     => ['/api/reports/submissions/12/epo-submit', true];
        yield 'potvrzenka'         => ['/api/reports/submissions/12/epo-status', true];
    }

    #[DataProvider('archivePaths')]
    public function testOnlyTheFilingItselfIsRestricted(string $path, bool $restricted): void
    {
        self::assertSame($restricted, CommercialFeatureAccess::restrictsApiPath($path), $path);
    }
}
