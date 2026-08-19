<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Update\MaintenanceMode;
use PHPUnit\Framework\TestCase;

/**
 * Brána údržby v `api/public/index.php` má tři vlastnosti, které z ní dělají
 * bránu — a všechny tři jde tichou úpravou ztratit.
 *
 * 1. MUSÍ být NAD `require vendor/autoload.php`. Přesně v okně, které hlídá
 *    (swap souborů + migrace), je autoloader nekonzistentní; brána pod ním by
 *    spadla dřív, než odpoví.
 * 2. NESMÍ použít třídu aplikace ze stejného důvodu — proto je čtenář inline
 *    a duplikuje jméno souboru, které jinak drží {@see MaintenanceMode::FILE}.
 *    Test tu duplicitu hlídá, ať se obě strany nerozejdou.
 * 3. MUSÍ respektovat expiraci, jinak by spadlý worker držel instalaci na 503
 *    navěky.
 *
 * Čtvrtá kontrola je na straně zapisovatele: značka se zakládá před swapem
 * a maže se ve `finally`, ne až po úspěchu — jinak by 503 přežilo i rollback
 * na funkční starou verzi.
 */
final class MaintenanceGateTest extends TestCase
{
    private static function indexPhp(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/index.php');
    }

    private static function updateService(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/Service/Update/NativeUpdateService.php');
    }

    public function testGateRunsBeforeTheAutoloader(): void
    {
        $src = self::indexPhp();

        $gate     = strpos($src, 'maintenance.json');
        $autoload = strpos($src, "require __DIR__ . '/../vendor/autoload.php'");

        self::assertIsInt($gate, 'V index.php chybí brána údržby (čtení storage/maintenance.json).');
        self::assertIsInt($autoload, 'V index.php chybí require autoloaderu.');
        self::assertLessThan(
            $autoload,
            $gate,
            'Brána údržby musí být nad require autoload.php — pod ním ji rozbije právě ten '
            . 'nekonzistentní autoloader, kvůli kterému existuje.'
        );
    }

    public function testGateReadsTheSameFileTheWriterWrites(): void
    {
        self::assertStringContainsString(
            '/storage/' . MaintenanceMode::FILE,
            self::indexPhp(),
            'Inline brána čte jiný soubor, než zakládá MaintenanceMode — jméno je duplikované '
            . 'schválně (brána nesmí autoloadovat), takže se musí měnit na obou místech.'
        );
    }

    public function testGateHonoursExpiry(): void
    {
        self::assertStringContainsString(
            "expires_at",
            self::indexPhp(),
            'Brána musí značku po expiraci ignorovat, jinak spadlý worker drží instalaci na 503 navěky.'
        );
    }

    public function testGateUsesNoApplicationClass(): void
    {
        $src   = self::indexPhp();
        $until = strpos($src, "require __DIR__ . '/../vendor/autoload.php'");
        self::assertIsInt($until);

        $gate = substr($src, 0, $until);
        // Komentáře smí třídu zmínit ({@see …}); kód nad autoloadem ne.
        $gate = (string) preg_replace('~/\*.*?\*/~s', '', $gate);

        self::assertDoesNotMatchRegularExpression(
            '~\\\\?MyInvoice\\\\[A-Za-z]~',
            $gate,
            'Kód brány nesmí sáhnout na třídu aplikace — autoloader v hlídaném okně nefunguje.'
        );
    }

    public function testWriterOpensBeforeSwapAndClosesInFinally(): void
    {
        $src = self::updateService();

        $begin  = strpos($src, 'MaintenanceMode::begin(');
        $swap   = strpos($src, '$this->swap(');
        $finally = strpos($src, '} finally {');

        self::assertIsInt($begin, 'NativeUpdateService nezakládá značku údržby.');
        self::assertIsInt($swap);
        self::assertLessThan($swap, $begin, 'Značka se musí založit dřív, než se sáhne na první soubor.');

        self::assertIsInt($finally, 'Úklid značky musí být ve finally, ne jen na úspěšné cestě.');
        self::assertStringContainsString(
            'MaintenanceMode::end(',
            substr($src, $finally),
            'Ve finally chybí MaintenanceMode::end() — po neúspěšném updatu by 503 přežilo rollback.'
        );
    }
}
