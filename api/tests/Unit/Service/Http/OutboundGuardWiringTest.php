<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Http;

use DI\ContainerBuilder;
use MyInvoice\Action\Settings\SigningProfilesAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Http\OutboundUrlGuard;
use MyInvoice\Service\Http\TsaUrlPolicy;
use MyInvoice\Service\Import\FakturoidClient;
use MyInvoice\Service\Pdf\PdfSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regrese na past PHP-DI: `ReflectionBasedAutowiring::getParametersDefinition()`
 * PŘESKAKUJE optional parametry (`if ($parameter->isOptional()) continue;`), takže
 * `?Config $config = null` v konstruktoru autowired služby zůstane VŽDY null.
 *
 * Důsledek téhle pasti byl, že `signing.tsa_allowed_hosts` i
 * `import.fakturoid.attachment_hosts` byly mrtvé konfigurační klíče — allowlist
 * se nikdy nenačetl a fail-closed brzda se nedala použít.
 *
 * Testy níž ověřují allowlist přes SKUTEČNÝ kontejner (ne ručně stavěný objekt,
 * který by díru zamaskoval) a hlídají, aby se optional class-param nevrátil.
 */
final class OutboundGuardWiringTest extends TestCase
{
    /** Kontejner postavený stejně jako v {@see \MyInvoice\Bootstrap} (bez atributů). */
    private function container(Config $config): \Psr\Container\ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAttributes(false);
        $builder->addDefinitions([Config::class => $config]);

        return $builder->build();
    }

    // --- allowlist přes kontejner ------------------------------------------

    public function testTsaAllowlistIsLoadedThroughContainer(): void
    {
        $container = $this->container(new Config([
            'signing' => ['tsa_allowed_hosts' => ['tsa.example.org', 'freetsa.org']],
        ]));

        $policy = $container->get(TsaUrlPolicy::class);

        self::assertSame(['tsa.example.org', 'freetsa.org'], $policy->allowedHosts());
    }

    public function testTsaAllowlistFromContainerActuallyRejectsUnlistedHost(): void
    {
        $container = $this->container(new Config([
            'signing' => ['tsa_allowed_hosts' => ['tsa.example.org']],
        ]));

        $this->expectException(\MyInvoice\Service\Http\OutboundRequestException::class);
        $container->get(TsaUrlPolicy::class)->assertStorable('https://freetsa.org/tsr');
    }

    public function testEmptyConfigThroughContainerMeansNoAllowlist(): void
    {
        $policy = $this->container(new Config([]))->get(TsaUrlPolicy::class);

        self::assertSame([], $policy->allowedHosts());
    }

    /**
     * PdfSigner i SigningProfilesAction dostávaly TsaUrlPolicy jako optional
     * class-param s defaultem `new TsaUrlPolicy()` — tedy bez Configu, i kdyby
     * byl TsaUrlPolicy v kontejneru správně nabindovaný.
     */
    public function testPdfSignerGetsPolicyWithConfigFromContainer(): void
    {
        $config = new Config([
            'app'     => ['pepper' => 'unit-test-pepper'],
            'signing' => ['tsa_allowed_hosts' => ['tsa.example.org']],
        ]);

        $signer = $this->container($config)->get(PdfSigner::class);

        $policy = (new \ReflectionProperty(PdfSigner::class, 'tsaPolicy'))->getValue($signer);
        self::assertInstanceOf(TsaUrlPolicy::class, $policy);
        self::assertSame(['tsa.example.org'], $policy->allowedHosts());
    }

    // --- Fakturoid attachment hosty ----------------------------------------

    /**
     * FakturoidClient nejde postavit z kontejneru bez DB spojení, takže se
     * `attachmentHosts()` ověřuje na instanci s injektovaným Configem.
     * Že Config VŮBEC doputuje, hlídá {@see testNoOptionalClassParamsInConstructors}.
     */
    public function testAttachmentHostsMergeConfigOnTopOfDefaults(): void
    {
        $hosts = $this->attachmentHosts(new Config([
            'import' => ['fakturoid' => ['attachment_hosts' => ['cdn.example-storage.test']]],
        ]));

        self::assertContains('app.fakturoid.cz', $hosts);
        self::assertContains('files.fakturoid.cz', $hosts);
        self::assertContains('cdn.example-storage.test', $hosts);
    }

    public function testAttachmentHostsAcceptCommaSeparatedString(): void
    {
        $hosts = $this->attachmentHosts(new Config([
            'import' => ['fakturoid' => ['attachment_hosts' => 'a.example.test, b.example.test']],
        ]));

        self::assertContains('a.example.test', $hosts);
        self::assertContains('b.example.test', $hosts);
    }

    public function testAttachmentHostsDefaultToBuiltInsOnly(): void
    {
        self::assertSame(
            FakturoidClient::defaultAttachmentHosts(),
            $this->attachmentHosts(new Config([]))
        );
    }

    /** @return list<string> */
    private function attachmentHosts(Config $config): array
    {
        $client = (new \ReflectionClass(FakturoidClient::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(FakturoidClient::class, 'config'))->setValue($client, $config);

        $method = new \ReflectionMethod(FakturoidClient::class, 'attachmentHosts');

        /** @var list<string> $hosts */
        $hosts = $method->invoke($client);

        return $hosts;
    }

    /**
     * SEC-13, sesterská cesta: `downloadInvoicePdf()` → `binaryGet()` musí jít přes
     * guard, ne přes syrový Guzzle. Guzzle následuje redirecty, takže odpověď
     * Fakturoidu mohla naši Authorization hlavičku poslat na cizí/vnitřní host.
     *
     * Guard je `final`, takže se nedá podstrčit špion; kontroluje se proto zdroj
     * metody. Test padne, jakmile se `$this->http` do binární cesty vrátí.
     */
    public function testBinaryGetDoesNotUseRawGuzzle(): void
    {
        $source = $this->methodSource(FakturoidClient::class, 'binaryGet');

        self::assertStringNotContainsString('$this->http', $source);
        self::assertStringContainsString('guardedApiGet', $source);
    }

    public function testGuardedApiGetPinsAllowlistToOwnApiHost(): void
    {
        $source = $this->methodSource(FakturoidClient::class, 'guardedApiGet');

        self::assertStringContainsString('$this->urlGuard->request', $source);
        // Vlastní API origin — konfigurační doplňky download hostů sem nepatří.
        self::assertStringContainsString('allowedHosts: [self::API_HOST]', $source);
        self::assertStringNotContainsString('attachmentHosts', $source);
    }

    /**
     * Guard redirecty nenásleduje, ale Fakturoid PDF může servírovat přes 302 na
     * storage. Hop se proto obsluhuje ručně — a cíl musí projít allowlistem
     * download hostů BEZ Authorization hlavičky (token k účtu nepatří na CDN).
     */
    public function testBinaryRedirectIsRevalidatedAndDropsAuthorization(): void
    {
        $source = $this->methodSource(FakturoidClient::class, 'followBinaryRedirect');

        self::assertStringContainsString('$this->urlGuard->request', $source);
        self::assertStringContainsString('allowedHosts: $this->attachmentHosts()', $source);
        self::assertStringNotContainsString('authHeaders', $source);
        self::assertStringNotContainsString('Authorization', $source);
    }

    public function testResponseHeaderLookupIsCaseInsensitive(): void
    {
        $resp = new \MyInvoice\Service\Http\OutboundResponse(
            302,
            '',
            '',
            ['location' => 'https://files.fakturoid.cz/x.pdf']
        );

        self::assertSame('https://files.fakturoid.cz/x.pdf', $resp->header('Location'));
        self::assertSame('https://files.fakturoid.cz/x.pdf', $resp->header('location'));
        self::assertNull($resp->header('x-missing'));
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $start = $reflection->getStartLine() - 1;
        $length = $reflection->getEndLine() - $start;

        return implode("\n", array_slice($lines, $start, $length));
    }

    // --- pojistka proti návratu pasti --------------------------------------

    /**
     * Žádná z těchto tříd nesmí mít optional parametr s class typehintem —
     * autowiring by ho nevyplnil a služba by tiše běžela s defaultem.
     *
     * @return list<array{class-string}>
     */
    public static function autowiredClassProvider(): array
    {
        return [
            [TsaUrlPolicy::class],
            [PdfSigner::class],
            [FakturoidClient::class],
            [SigningProfilesAction::class],
        ];
    }

    #[DataProvider('autowiredClassProvider')]
    public function testNoOptionalClassParamsInConstructors(string $class): void
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        self::assertNotNull($constructor, $class . ' nemá konstruktor.');

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            self::assertFalse(
                $parameter->isOptional(),
                sprintf(
                    '%s::__construct($%s) je optional class-param — PHP-DI ho nevyplní '
                    . 'a služba dostane default místo instance z kontejneru.',
                    $class,
                    $parameter->getName()
                )
            );
        }
    }

    // --- useknutá odpověď ---------------------------------------------------

    public function testCompleteTransferHasNoFailureReason(): void
    {
        self::assertNull(OutboundUrlGuard::transferFailureReason(true, false, ''));
    }

    /**
     * Jádro nálezu: chyba UPROSTŘED těla (timeout, reset) — status kód z hlaviček
     * už známe, ale tělo je neúplné. Nesmí projít jako úspěch.
     */
    public function testTruncatedBodyAfterHeadersIsAFailure(): void
    {
        $reason = OutboundUrlGuard::transferFailureReason(false, false, 'Operation timed out');

        self::assertNotNull($reason);
        self::assertStringContainsString('Operation timed out', $reason);
    }

    public function testTransferFailureWithoutCurlMessageStillFails(): void
    {
        self::assertNotNull(OutboundUrlGuard::transferFailureReason(false, false, ''));
    }

    public function testOverflowIsReportedBeforeTransferError(): void
    {
        // Přetečení limitu utne přenos → curl hlásí chybu; hláška má být o velikosti.
        $reason = OutboundUrlGuard::transferFailureReason(false, true, 'Failed writing body');

        self::assertNotNull($reason);
        self::assertStringContainsString('velikost', $reason);
    }
}
