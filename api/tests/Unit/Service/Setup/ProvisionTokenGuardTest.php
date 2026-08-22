<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Setup;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Setup\ProvisionTokenGuard;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * H-01 — okno mezi zřízením instance a naším setupem.
 *
 * Testy jsou psané tak, aby padaly na obou způsobech, jak se dá pravidlo pokazit:
 *
 *  1. navázat ho na přítomnost klíče místo na `app.managed` (fail-open ve chvíli,
 *     kdy selže zápis do `cfg.local.php`) — {@see testManagedInstanceWithoutConfiguredTokenRejectsEverything},
 *  2. rozšířit ho i na self-hosted instalace — {@see testSelfHostedInstanceIsUntouched}.
 *
 * Všechny tokeny v testech jsou syntetické.
 */
final class ProvisionTokenGuardTest extends TestCase
{
    private const TOKEN = 'aaaabbbbccccddddeeeeffff00001111';
    private const OTHER = 'ffff0000111122223333444455556666';

    private string $tmpRoot = '';

    protected function tearDown(): void
    {
        if ($this->tmpRoot === '') {
            return;
        }
        foreach (glob($this->tmpRoot . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpRoot);
        $this->tmpRoot = '';
    }

    public function testSelfHostedInstanceIsUntouched(): void
    {
        $guard = new ProvisionTokenGuard(new Config([]));

        self::assertFalse($guard->isEnforced());
        self::assertNull(
            $guard->verify($this->request()),
            'Bez app.managed se chování self-hosted instalace nesmí změnit.',
        );
    }

    public function testSelfHostedInstanceIgnoresConfiguredToken(): void
    {
        // Klíč vyplněný je, `app.managed` ne → pravidlo se nesmí aktivovat.
        // Kdyby viselo na přítomnosti klíče, tenhle případ by setup zablokoval.
        $guard = new ProvisionTokenGuard(new Config([
            'setup' => ['provision_token' => self::TOKEN],
        ]));

        self::assertNull($guard->verify($this->request()));
    }

    public function testManagedInstanceWithoutConfiguredTokenRejectsEverything(): void
    {
        // Přesně ta situace, kvůli které pravidlo visí na app.managed: zápis do
        // cfg.local.php selhal, takže token v konfiguraci chybí. Fail-closed.
        $guard = new ProvisionTokenGuard(new Config([
            'app'   => ['managed' => true],
            'setup' => ['provision_token' => ''],
        ]));

        $withoutToken = $guard->verify($this->request());
        self::assertNotNull($withoutToken, 'Chybějící token v konfiguraci nesmí setup otevřít.');
        self::assertSame(ProvisionTokenGuard::CODE_REQUIRED, $withoutToken['code']);
        self::assertSame(ProvisionTokenGuard::REASON_NOT_CONFIGURED, $withoutToken['reason']);

        // A hlavně: ani útočník, který si nějaký token vymyslí, se nesmí trefit.
        $withAnyToken = $guard->verify($this->request(header: 'cokoliv-co-si-utocnik-vymysli'));
        self::assertNotNull($withAnyToken);
        self::assertSame(ProvisionTokenGuard::REASON_NOT_CONFIGURED, $withAnyToken['reason']);
    }

    public function testManagedInstanceRejectsRequestWithoutToken(): void
    {
        $rejection = $this->managedGuard()->verify($this->request());

        self::assertNotNull($rejection);
        self::assertSame(ProvisionTokenGuard::CODE_REQUIRED, $rejection['code']);
        self::assertSame(ProvisionTokenGuard::REASON_NOT_SUPPLIED, $rejection['reason']);
    }

    public function testManagedInstanceRejectsWrongToken(): void
    {
        $rejection = $this->managedGuard()->verify($this->request(header: self::OTHER));

        self::assertNotNull($rejection);
        self::assertSame(ProvisionTokenGuard::CODE_INVALID, $rejection['code']);
        self::assertSame(ProvisionTokenGuard::REASON_MISMATCH, $rejection['reason']);
    }

    public function testManagedInstanceRejectsTokenThatIsOnlyAPrefix(): void
    {
        $rejection = $this->managedGuard()->verify($this->request(header: substr(self::TOKEN, 0, 8)));

        self::assertNotNull($rejection);
        self::assertSame(ProvisionTokenGuard::CODE_INVALID, $rejection['code']);
    }

    public function testManagedInstanceAcceptsHeader(): void
    {
        self::assertNull($this->managedGuard()->verify($this->request(header: self::TOKEN)));
    }

    public function testManagedInstanceAcceptsHeaderWithSurroundingWhitespace(): void
    {
        // Proxy umí hodnotu obalit mezerami; token je hex, takže trim nic nemění.
        self::assertNull($this->managedGuard()->verify($this->request(header: '  ' . self::TOKEN . ' ')));
    }

    public function testManagedInstanceAcceptsBodyFallback(): void
    {
        // Hlavička je čistší (nepotřebuje JSON schéma, neskončí v logu těla),
        // tělo je odolnější vůči proxy — proto obojí.
        self::assertNull($this->managedGuard()->verify($this->request(body: self::TOKEN)));
    }

    public function testHeaderTakesPrecedenceOverBody(): void
    {
        $rejection = $this->managedGuard()->verify($this->request(header: self::OTHER, body: self::TOKEN));

        self::assertNotNull($rejection, 'Poslaná hlavička je závazná; tělo ji nesmí přebít.');
        self::assertSame(ProvisionTokenGuard::CODE_INVALID, $rejection['code']);
    }

    public function testNonScalarBodyTokenIsNotAccepted(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/setup')
            ->withParsedBody([ProvisionTokenGuard::BODY_FIELD => ['nested' => self::TOKEN]]);

        $rejection = $this->managedGuard()->verify($request);

        self::assertNotNull($rejection);
        self::assertSame(ProvisionTokenGuard::REASON_NOT_SUPPLIED, $rejection['reason']);
    }

    public function testManagedFlagToleratesStringifiedBooleans(): void
    {
        // cfg.php i env proměnná umí dodat '1'/'true' místo bool.
        foreach ([true, 1, '1', 'true'] as $value) {
            $guard = new ProvisionTokenGuard(new Config([
                'app'   => ['managed' => $value],
                'setup' => ['provision_token' => self::TOKEN],
            ]));
            self::assertTrue($guard->isEnforced(), 'app.managed = ' . var_export($value, true));
        }

        foreach ([false, 0, '0', '', 'false'] as $value) {
            $guard = new ProvisionTokenGuard(new Config(['app' => ['managed' => $value]]));
            self::assertFalse($guard->isEnforced(), 'app.managed = ' . var_export($value, true));
        }
    }

    public function testRejectionMessageLeaksNothingAboutTheToken(): void
    {
        $message = ProvisionTokenGuard::MESSAGE;

        self::assertStringNotContainsString(self::TOKEN, $message);
        self::assertStringNotContainsString(substr(self::TOKEN, 0, 4), $message);
        self::assertStringNotContainsString((string) strlen(self::TOKEN), $message);
        // Důvody jsou určené výhradně pro auditní log, nikdy pro odpověď.
        self::assertStringNotContainsString(ProvisionTokenGuard::REASON_MISMATCH, $message);
        self::assertStringNotContainsString(ProvisionTokenGuard::REASON_NOT_CONFIGURED, $message);
    }

    public function testConsumeEmptiesTokenInCfgLocal(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/myinvoice-provision-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0700, true);
        file_put_contents(
            $this->tmpRoot . '/cfg.local.php',
            "<?php return ['app' => ['managed' => true], 'setup' => ['provision_token' => '" . self::TOKEN . "']];",
        );

        $this->managedGuard()->consume($this->tmpRoot);

        $loaded = require $this->tmpRoot . '/cfg.local.php';
        self::assertSame('', $loaded['setup']['provision_token'], 'Token musí být po použití neplatný.');
        self::assertTrue($loaded['app']['managed'], 'Spotřebování tokenu nesmí zahodit ostatní klíče.');
    }

    private function managedGuard(): ProvisionTokenGuard
    {
        return new ProvisionTokenGuard(new Config([
            'app'   => ['managed' => true],
            'setup' => ['provision_token' => self::TOKEN],
        ]));
    }

    private function request(?string $header = null, ?string $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/setup');
        if ($header !== null) {
            $request = $request->withHeader(ProvisionTokenGuard::HEADER, $header);
        }
        if ($body !== null) {
            $request = $request->withParsedBody([ProvisionTokenGuard::BODY_FIELD => $body]);
        }

        return $request;
    }
}
