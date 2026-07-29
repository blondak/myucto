<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * N-016: `invoices.pdf_path` se ukládá RELATIVNĚ ke `storage/invoices`.
 *
 * Absolutní cesta přežije jen do prvního přesunu instance — v produkci se takhle
 * nasbíralo 43 řádků ze dvou různých kořenů a ani jeden už netrefil existující
 * soubor. Přijatá větev (`purchase_invoices.pdf_path`) je relativní odjakživa.
 *
 * Čtení musí zároveň snést LEGACY absolutní hodnoty: migrace 1145 je převede, ale
 * řádky ze záloh a z jiných prostředí se můžou objevit kdykoliv. Kdyby je resolve
 * překlopil na `storage/invoices/C:/...`, cache by se netrefila NIKDY a PDF by se
 * generovalo při každém otevření.
 */
final class InvoicePdfPathTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = rtrim(str_replace('\\', '/', RuntimePaths::storage('invoices')), '/');
    }

    public function testAbsolutePathUnderStorageIsStoredRelative(): void
    {
        $abs = $this->root . '/sup-1/2026-04/Faktura-2604001.pdf';

        self::assertSame('sup-1/2026-04/Faktura-2604001.pdf', $this->toRelative($abs));
    }

    /** Windows zápis se stejnou cestou musí dát stejný výsledek. */
    public function testBackslashPathIsNormalised(): void
    {
        $abs = str_replace('/', '\\', $this->root) . '\\sup-1\\2026-04\\Faktura-2604001.pdf';

        self::assertSame('sup-1/2026-04/Faktura-2604001.pdf', $this->toRelative($abs));
    }

    /** Uložit → načíst musí vrátit původní absolutní cestu. */
    public function testRoundTrip(): void
    {
        $abs = $this->root . '/sup-3/2025-12/Dobropis-2512009.pdf';

        self::assertSame($abs, InvoicePdfRenderer::resolvePdfPath($this->toRelative($abs)));
    }

    /**
     * Jádro nálezu: legacy absolutní hodnota se NESMÍ lepit za kořen.
     * Přesně takové řádky (ze dvou různých produkčních instancí) v DB ležely.
     */
    public function testLegacyAbsoluteValueIsReturnedAsIs(): void
    {
        foreach ([
            'C:\\inetpub\\wwwroot\\invoice.example.cz/storage/invoices/sup-1/2026-04/Faktura-2604001.pdf',
            'C:\\inetpub\\wwwroot\\ucto.example.cz/storage/invoices/sup-1/2026-04/Faktura-2604004.pdf',
            '/var/www/myucto/storage/invoices/sup-1/2026-04/Faktura-2604006.pdf',
            '//nas01/share/storage/invoices/sup-1/2026-04/Faktura-2604007.pdf',
        ] as $legacy) {
            $resolved = (string) InvoicePdfRenderer::resolvePdfPath($legacy);

            self::assertStringNotContainsString(
                $this->root . '/' . ltrim(str_replace('\\', '/', $legacy), '/'),
                $resolved,
                'Legacy absolutní cesta se nesmí zřetězit za kořen: ' . $legacy,
            );
            self::assertSame(str_replace('\\', '/', $legacy), $resolved);
        }
    }

    public function testEmptyValuesResolveToNull(): void
    {
        self::assertNull(InvoicePdfRenderer::resolvePdfPath(null));
        self::assertNull(InvoicePdfRenderer::resolvePdfPath(''));
        self::assertNull(InvoicePdfRenderer::resolvePdfPath('   '));
    }

    private function toRelative(string $absolute): string
    {
        $m = new \ReflectionMethod(InvoicePdfRenderer::class, 'toRelativePdfPath');

        return (string) $m->invoke(null, $absolute);
    }
}
