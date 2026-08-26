<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Invariants;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regrese issue #37 — logo v hlavičce PDF se roztáhlo přes půl stránky.
 *
 * Příčina: mPDF neaplikuje `max-width` / `max-height` ze stylopisu na `<img>`.
 * Pravidlo `td.logo-img-cell img { max-width: … }` ve `styles/invoice.css` proto
 * v PDF nic nedělá a obrázek se vykreslí v nativní velikosti (240px logo ≈ 63 mm).
 * Varianta bez názvu firmy problém neměla, protože strop má INLINE na `<img>`.
 *
 * Tenhle test hlídá, že každý `<img>` v hlavičce dokladu má strop inline. Kdo ho
 * odsud smaže „protože je to v CSS", shodí test.
 */
#[Group('invariants')]
final class PdfLogoSizingTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function headerTemplates(): array
    {
        return [
            'invoice'     => ['invoice/invoice.twig'],
            'work_report' => ['invoice/work_report.twig'],
        ];
    }

    #[DataProvider('headerTemplates')]
    public function testHeaderLogoImagesCarryInlineSizeCap(string $template): void
    {
        $path = \dirname(__DIR__, 2) . '/templates/' . $template;
        self::assertFileExists($path);

        $html = (string) file_get_contents($path);
        preg_match_all('/<img\b[^>]*\balt="logo"[^>]*>/i', $html, $m);

        self::assertNotEmpty($m[0], 'Šablona ' . $template . ' nemá žádné logo <img>.');

        foreach ($m[0] as $tag) {
            self::assertMatchesRegularExpression(
                '/style="[^"]*max-width:\s*\d+(?:\.\d+)?mm[^"]*max-height:\s*\d+(?:\.\d+)?mm/i',
                $tag,
                'Logo <img> bez inline max-width/max-height — mPDF ho vykreslí v nativní '
                . 'velikosti a rozbije hlavičku (issue #37): ' . $tag,
            );
        }
    }

    /**
     * Gradienty musí ze SVG blocklistu zůstat venku: mPDF je renderuje správně,
     * kdežto rastrový fallback ztrácí `<text>` na hostech bez daného fontu
     * (reportér issue #37 viděl z loga jen barevné pozadí bez písmen).
     */
    public function testGradientsAreNotOnSvgFallbackBlocklist(): void
    {
        foreach (['InvoicePdfRenderer.php', 'PdfBranding.php'] as $file) {
            $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Service/Pdf/' . $file);
            self::assertMatchesRegularExpression(
                '/\$bad\s*=\s*\'[^\']+\'/',
                $src,
                $file . ' už nemá SVG blocklist — zkontroluj tenhle test.',
            );
            preg_match('/\$bad\s*=\s*\'([^\']+)\'/', $src, $mm);
            self::assertStringNotContainsStringIgnoringCase('Gradient', $mm[1] ?? '', $file);
        }
    }
}
