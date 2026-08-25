<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * FastRoute cache mapuje vzor → identifikátor routy („route757"), a ty se
 * přidělují v pořadí registrace. Otisk v názvu cache souboru proto MUSÍ vznikat
 * z rout, které daný proces reálně zaregistroval — ne z metadat na disku.
 *
 * Otisk z `filemtime()`/`filesize()` je proti procesu se starým bytekódem
 * v opcache slepý: disk hlásí novou verzi, proces registruje staré routy a
 * zapíše je pod jméno nové. Ostatní procesy pak tiše routují na cizí handlery
 * (25. 8. 2026: `GET /api/dashboard/summary` → `undoBatch`, `invalid_batch`).
 */
final class RouteCacheFingerprintTest extends TestCase
{
    public function testFingerprintComesFromRegisteredRoutesNotDiskMetadata(): void
    {
        $body = self::methodSource('enableRouteCache');

        self::assertStringContainsString('getRoutes()', $body, 'Otisk se musí počítat z živých rout.');
        self::assertStringContainsString('as $identifier =>', $body, 'Do otisku patří i identifikátor routy — cache mapuje právě na něj.');
        self::assertStringContainsString('getPattern()', $body, 'Do otisku patří vzor routy.');
        foreach (['filemtime', 'filesize', 'VERSION'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $body,
                'Otisk route cache se nesmí odvozovat z metadat na disku (' . $forbidden . ').',
            );
        }
    }

    public function testRoutesAreRegisteredBeforeTheCacheFileIsChosen(): void
    {
        $body = self::methodSource('buildApp');
        $register = strpos($body, 'Routes::register($app)');
        $enable   = strpos($body, 'self::enableRouteCache($app');

        self::assertIsInt($register);
        self::assertIsInt($enable);
        self::assertLessThan(
            $enable,
            $register,
            'enableRouteCache() se musí volat AŽ za Routes::register() — jinak nemá z čeho otisk spočítat.',
        );
    }

    private static function methodSource(string $method): string
    {
        // Čte se soubor vedle testu, ne přes Reflection: guard má platit pro
        // zdroják v tomhle stromu i tam, kde autoloader ukazuje jinam.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Bootstrap.php');
        $start = strpos($source, ' function ' . $method . '(');
        self::assertIsInt($start, 'Bootstrap::' . $method . '() ve zdrojáku chybí.');
        $next = strpos($source, "
    private static function ", $start + 1);
        $next = $next === false ? strpos($source, "
    public static function ", $start + 1) : $next;

        return substr($source, $start, ($next === false ? strlen($source) : $next) - $start);
    }
}
