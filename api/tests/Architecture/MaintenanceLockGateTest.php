<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Zámek údržby (H-03) má čtyři vlastnosti, které z něj dělají zámek — a všechny
 * čtyři jde tichou úpravou ztratit, aniž by spadl jediný funkční test:
 *
 * 1. Middleware musí být PŘED autentizací (503 dostane i nepřihlášený) a
 *    UVNITŘ ApiVersionRewrite (jinak `/api/v1/health` v údržbě spadne na 503,
 *    protože bypass se testuje na už přepsané cestě).
 * 2. SPA fallback ve front controlleru musí mít vlastní bránu. Do Slim pipeline
 *    se nikdy nedostane — `web/dist/index.html` se vydává ještě před
 *    `Bootstrap::buildApp()` —, takže bez ní by uživatel v údržbě dostal
 *    normální aplikaci, která pak spadne na prvním API volání.
 * 3. Brána v index.php musí být PŘED vydáním `web/dist/index.html`.
 * 4. Webserver pravidlo musí existovat v OBOU konfiguracích (Apache i IIS)
 *    a v obou musí mít výjimku pro `/api/health`. Jednostranná změna je přesně
 *    ta třída chyby, kterou multiplatformní pravidlo v AGENTS.md zakazuje.
 */
final class MaintenanceLockGateTest extends TestCase
{
    private static function read(string $relativeToRepoRoot): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../' . $relativeToRepoRoot);
    }

    public function testMiddlewareRunsBeforeAuthAndInsideVersionRewrite(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Bootstrap.php');

        $maintenance = strpos($src, '$app->add(MaintenanceModeMiddleware::class)');
        $auth        = strpos($src, '$app->add(AuthMiddleware::class)');
        $rewrite     = strpos($src, '$app->add(new ApiVersionRewriteMiddleware())');

        self::assertIsInt($maintenance, 'MaintenanceModeMiddleware není v pipeline vůbec zaregistrovaný.');
        self::assertIsInt($auth);
        self::assertIsInt($rewrite);

        // Slim 4 je LIFO: pozdější add() = vnější vrstva = běží dřív.
        self::assertGreaterThan(
            $auth,
            $maintenance,
            'Zámek údržby musí být VNĚ autentizace — 503 musí dostat i nepřihlášený.',
        );
        self::assertGreaterThan(
            $maintenance,
            $rewrite,
            'ApiVersionRewrite musí zůstat vně zámku, jinak /api/v1/health v údržbě nedostane výjimku.',
        );
    }

    public function testFrontControllerGatesTheSpaFallbackBeforeServingIndexHtml(): void
    {
        $src = self::read('api/public/index.php');

        $gate = strpos($src, 'MaintenanceLock(');
        // Záměrně se hledá VÝDEJ souboru, ne cesta k němu — tu zmiňují i komentáře.
        $serve = strpos($src, 'readfile($indexFile)');

        self::assertIsInt(
            $gate,
            'SPA fallback nemá bránu údržby — do Slim pipeline se nikdy nedostane, '
            . 'takže by v údržbě vydal normální aplikaci.',
        );
        self::assertIsInt($serve, 'V index.php chybí výdej SPA entry.');
        self::assertLessThan(
            $serve,
            $gate,
            'Brána údržby musí být nad vydáním web/dist/index.html, ne pod ním.',
        );
    }

    public function testBothWebserverConfigsCarryTheRuleWithTheHealthException(): void
    {
        foreach (['.htaccess', 'web.config'] as $file) {
            $src = self::read($file);

            self::assertStringContainsString(
                'maintenance.lock',
                $src,
                $file . ' postrádá pravidlo zámku údržby — konfigurace Apache a IIS '
                . 'se nesmí rozejít (viz AGENTS.md, multiplatformnost).',
            );
            self::assertStringContainsString(
                '^/api/(v1/)?health/?$',
                $src,
                $file . ': zrychlovací pravidlo musí vyjmout /api/health, jinak hosting '
                . 'nepozná rozdíl mezi údržbou a výpadkem.',
            );
        }
    }

    /**
     * Zámek leží mimo docroot, takže se na něj z webserveru míří relativně —
     * a musí se počítat s oběma rozloženími, jinak pravidlo na jednom z nich
     * tiše nedělá nic.
     */
    public function testWebserverRulesCoverBothDataDirLayouts(): void
    {
        foreach (['.htaccess', 'web.config'] as $file) {
            $src = self::read($file);

            self::assertStringContainsString('/../storage/maintenance.lock', $src, $file);
            self::assertMatchesRegularExpression(
                '~DOCUMENT_ROOT[}]?/storage/maintenance\.lock~',
                $src,
                $file . ': chybí varianta pro data dir = docroot (self-hosted default).',
            );
        }
    }
}
