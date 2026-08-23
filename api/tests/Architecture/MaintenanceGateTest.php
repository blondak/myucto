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

    /**
     * Zdravotní rozhraní musí přežít okno aktualizace (příloha B.7) — jinak
     * orchestrátor provozovatele nepozná „nasazuje se" od „instance je mrtvá".
     *
     * Testuje se opravdovým procesem nad `index.php`: brána je záměrně inline
     * closure nad autoloadem, takže jediný poctivý způsob, jak ji ověřit, je
     * nechat ji doopravdy odpovědět. Runner jen naplní `$_SERVER` a po `exit`
     * vypíše ze shutdown handleru HTTP kód.
     */
    public function testGateKeepsHealthEndpointAvailable(): void
    {
        [$code, $body] = $this->requestThroughGate('/api/health');

        self::assertSame(200, $code, 'health musí v údržbě odpovídat 200: ' . $body);

        $payload = json_decode($body, true);
        self::assertIsArray($payload, 'health musí vrátit JSON: ' . $body);
        self::assertSame('ok', $payload['status'] ?? null);
        self::assertTrue($payload['maintenance'] ?? null, 'údržba se musí přiznat příznakem, ne HTTP kódem');
        self::assertSame('9.9.9', $payload['update']['target'] ?? null);
        self::assertNotNull($payload['update']['started_at'] ?? null);

        // Neznámé hodnoty jsou explicitní null, ne chybějící klíč — klient se
        // nesmí muset ptát, jestli hodnotu neznáme, nebo jsme ji zapomněli.
        foreach (['db', 'version'] as $key) {
            self::assertArrayHasKey($key, $payload, $key . ' musí být přítomný i jako null');
            self::assertNull($payload[$key]);
        }
    }

    public function testGateStillReturns503ForEverythingElse(): void
    {
        foreach (['/api/invoices', '/', '/api/healthz', '/api/health/detail'] as $path) {
            [$code, $body] = $this->requestThroughGate($path);
            self::assertSame(503, $code, $path . ' musí v údržbě dostat 503: ' . $body);
            self::assertStringContainsString('maintenance', $body, $path);
        }
    }

    /** Query string nesmí health z výjimky vyřadit. */
    public function testGateMatchesHealthPathWithQueryString(): void
    {
        [$code, $body] = $this->requestThroughGate('/api/health?probe=1');

        self::assertSame(200, $code, 'query string nesmí rozhodovat: ' . $body);
        self::assertSame('ok', (json_decode($body, true)['status'] ?? null));
    }

    /**
     * Pustí `api/public/index.php` samostatným procesem se značkou údržby
     * v dočasném DATA_DIRu.
     *
     * @return array{0:int, 1:string} HTTP kód, tělo odpovědi
     */
    private function requestThroughGate(string $requestUri): array
    {
        $dir = sys_get_temp_dir() . '/myucto-gate-' . bin2hex(random_bytes(6));
        mkdir($dir . '/storage', 0775, true);
        file_put_contents($dir . '/storage/' . MaintenanceMode::FILE, (string) json_encode([
            'reason'     => 'update',
            'product'    => 'MyÚčto.cz',
            'target'     => '9.9.9',
            'started_at' => date(\DateTimeInterface::ATOM),
            'expires_at' => date(\DateTimeInterface::ATOM, time() + 600),
        ]));

        $index  = dirname(__DIR__, 2) . '/public/index.php';
        $runner = $dir . '/runner.php';
        file_put_contents($runner, '<?php' . "\n" . <<<PHP
            \$_SERVER['REQUEST_URI']    = {$this->export($requestUri)};
            \$_SERVER['REQUEST_METHOD'] = 'GET';
            \$_SERVER['HTTP_ACCEPT']    = 'application/json';
            register_shutdown_function(static function (): void {
                fwrite(STDOUT, "\\n__STATUS__" . http_response_code());
            });
            require {$this->export($index)};
            PHP);

        // MYINVOICE_DATA_DIR přesměruje bránu na dočasnou značku — potomek si
        // proměnnou zdědí, takže se repozitáře test vůbec nedotkne.
        $previous = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $dir);

        $out = [];
        $rc  = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($runner) . ' 2>&1', $out, $rc);
        $raw = implode("\n", $out);

        putenv(is_string($previous) ? 'MYINVOICE_DATA_DIR=' . $previous : 'MYINVOICE_DATA_DIR');

        foreach (glob($dir . '/storage/*') ?: [] as $f) {
            @unlink((string) $f);
        }
        @unlink($runner);
        @rmdir($dir . '/storage');
        @rmdir($dir);

        $pos = strrpos($raw, '__STATUS__');
        self::assertIsInt($pos, 'runner nedoběhl: ' . $raw);

        return [(int) substr($raw, $pos + strlen('__STATUS__')), rtrim(substr($raw, 0, $pos))];
    }

    private function export(string $value): string
    {
        return var_export($value, true);
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
