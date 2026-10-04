<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use MyInvoice\Service\Ai\OllamaEndpointException;
use MyInvoice\Service\Ai\OllamaEndpointGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Adresu Ollamy zadává admin firmy, takže guard je jediná věc mezi formulářem
 * a libovolným síťovým cílem serveru. DNS je injektovaný — test nesahá na síť.
 */
final class OllamaEndpointGuardTest extends TestCase
{
    /** @param array<string, list<string>> $dns */
    private function guard(array $dns = [], string $allowed = ''): OllamaEndpointGuard
    {
        return new OllamaEndpointGuard(static fn (string $host): array => $dns[$host] ?? [], $allowed);
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (OllamaEndpointException $e) {
            return $e->errorCode;
        }
        self::fail('očekávána OllamaEndpointException');
    }

    public function testNormalize_keepsOnlySchemeHostPort(): void
    {
        $g = $this->guard();
        self::assertSame('http://localhost:11434', $g->normalize(' http://localhost:11434/ '));
        self::assertSame('http://gpu.lan:11434', $g->normalize('HTTP://GPU.lan:11434'));
        self::assertSame('https://ollama.example.test', $g->normalize('https://ollama.example.test'));
        self::assertSame('http://[::1]:11434', $g->normalize('http://[::1]:11434'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUrls(): iterable
    {
        yield 'prázdné' => [''];
        yield 'bez schématu' => ['localhost:11434'];
        yield 'ftp' => ['ftp://gpu.lan:11434'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'userinfo' => ['http://user:pass@gpu.lan:11434'];
        yield 'cesta /api' => ['http://gpu.lan:11434/api'];
        yield 'OpenAI styl /v1' => ['http://gpu.lan:11434/v1'];
        yield 'query' => ['http://gpu.lan:11434?x=1'];
        yield 'fragment' => ['http://gpu.lan:11434#x'];
        yield 'backslash-@' => ['http://a.lan\@169.254.169.254'];
        yield '#@' => ['http://169.254.169.254#@a.lan'];
        yield 'procento v hostu' => ['http://%31%32%37.0.0.1:11434'];
        yield 'mezera v hostu' => ['http://gpu .lan:11434'];
        yield 'tabulátor v hostu' => ["http://gpu\t.lan:11434"];
        yield 'port 0' => ['http://gpu.lan:0'];
        yield 'port 70000' => ['http://gpu.lan:70000'];
        yield 'nečíselný port' => ['http://gpu.lan:abc'];
        yield 'prázdný port' => ['http://gpu.lan:'];
        yield 'podtržítko v hostu' => ['http://my_host:11434'];
        yield 'prázdný host' => ['http://:11434'];
        yield 'dvě zavináče' => ['http://a@b@gpu.lan:11434'];
        yield 'zkrácený IPv4 (curl = 127.0.0.1)' => ['http://127.1:11434'];
        yield 'číselný host' => ['http://2130706433:11434'];
        yield 'hex IPv4 (0x7f.0.0.0x1)' => ['http://0x7f.0.0.0x1:11434'];
        yield 'hex IPv4 metadata' => ['http://0xa9.0xfe.0xa9.0xfe:11434'];
        yield 'hex IPv4 jedno číslo' => ['http://0x7f000001:11434'];
        yield 'oktalová IPv4' => ['http://0177.0.0.1:11434'];
        yield 'IPv6 bez závorek' => ['http://::1:11434'];
        yield 'neplatný IPv6' => ['http://[zzz]:11434'];
    }

    #[DataProvider('invalidUrls')]
    public function testNormalize_rejectsAnythingButBaseUrl(string $url): void
    {
        self::assertSame(OllamaEndpointGuard::ERR_INVALID, $this->code(fn () => $this->guard()->normalize($url)));
    }

    /** @return iterable<string, array{string, array<string, list<string>>}> */
    public static function blockedTargets(): iterable
    {
        yield 'metadata literál' => ['http://169.254.169.254', []];
        yield 'metadata přes DNS' => ['http://meta.lan', ['meta.lan' => ['169.254.169.254']]];
        yield 'IPv4-mapped metadata' => ['http://mapped.lan:11434', ['mapped.lan' => ['::ffff:169.254.169.254']]];
        yield 'hex-mapped metadata' => ['http://hexmap.lan:11434', ['hexmap.lan' => ['::ffff:a9fe:a9fe']]];
        yield 'IPv4-compatible metadata' => ['http://compat.lan:11434', ['compat.lan' => ['::169.254.169.254']]];
        yield 'hex-compatible metadata' => ['http://hexcompat.lan:11434', ['hexcompat.lan' => ['::a9fe:a9fe']]];
        yield 'NAT64 metadata' => ['http://nat64.lan:11434', ['nat64.lan' => ['64:ff9b::a9fe:a9fe']]];
        yield 'AWS metadata IPv6 (v rozsahu ULA)' => ['http://aws6.lan:11434', ['aws6.lan' => ['fd00:ec2::254']]];
        yield 'AWS metadata IPv6 literál' => ['http://[fd00:ec2::254]', []];
        yield 'Alibaba Cloud metadata (CGNAT)' => ['http://100.100.100.200', []];
        yield 'GCP metadata IPv6 (v rozsahu ULA)' => ['http://gcp6.lan:11434', ['gcp6.lan' => ['fd20:ce::254']]];
        yield 'Azure WireServer' => ['http://168.63.129.16', []];
        yield '0.0.0.0' => ['http://0.0.0.0:11434', []];
        yield 'link-local IPv6' => ['http://[fe80::1]:11434', []];
        yield 'multicast' => ['http://224.0.0.1:11434', []];
        yield 'jedna z adres blokovaná' => ['http://mix.lan:11434', ['mix.lan' => ['192.168.1.10', '169.254.1.1']]];
    }

    /** @param array<string, list<string>> $dns */
    #[DataProvider('blockedTargets')]
    public function testResolve_blocksDangerousRanges(string $url, array $dns): void
    {
        self::assertSame(OllamaEndpointGuard::ERR_BLOCKED, $this->code(fn () => $this->guard($dns)->resolve($url)));
    }

    public function testResolve_unresolvableHostIsUnreachable(): void
    {
        self::assertSame(OllamaEndpointGuard::ERR_UNREACHABLE, $this->code(fn () => $this->guard()->resolve('http://nikde.lan:11434')));
    }

    public function testResolve_regionLocalOnlyForLoopbackAndPrivate(): void
    {
        $g = $this->guard([
            'gpu.lan'        => ['192.168.1.50'],
            'vpn.lan'        => ['10.8.0.2'],
            'k8s.lan'        => ['172.20.0.5'],
            'ula.lan'        => ['fd12:3456::1'],
            'public.example' => ['203.0.113.7'],
            'mixed.example'  => ['10.0.0.5', '203.0.113.7'],
        ]);
        foreach (['http://127.0.0.1:11434', 'http://[::1]:11434', 'http://gpu.lan:11434', 'http://vpn.lan:11434', 'http://k8s.lan:11434', 'http://ula.lan:11434'] as $url) {
            self::assertSame('eu', $g->resolve($url)->region, $url);
        }
        self::assertSame('us', $g->resolve('https://public.example')->region);
        self::assertSame('us', $g->resolve('http://mixed.example:11434')->region, 'smíšené adresy = fail-closed us');
    }

    public function testResolve_pinsVerifiedIp(): void
    {
        $ep = $this->guard(['gpu.lan' => ['192.168.1.50']])->resolve('http://gpu.lan:11434');
        self::assertSame('http://gpu.lan:11434', $ep->baseUrl);
        self::assertSame('gpu.lan:11434:192.168.1.50', $ep->curlResolve());
        self::assertNull($this->guard()->resolve('http://127.0.0.1:11434')->curlResolve(), 'IP literál nepotřebuje pinning');
        self::assertSame(80, $this->guard(['h.lan' => ['10.0.0.1']])->resolve('http://h.lan')->port);
        self::assertSame(443, $this->guard(['h.lan' => ['10.0.0.1']])->resolve('https://h.lan')->port);
        self::assertSame('v6.lan:11434:[fd00::5]', $this->guard(['v6.lan' => ['fd00::5']])->resolve('http://v6.lan:11434')->curlResolve());
    }

    public function testAllowlist_hostnameAndCidr(): void
    {
        $dns = ['gpu.lan' => ['192.168.1.50'], 'other.lan' => ['192.168.1.60'], 'lab.lan' => ['10.1.2.3']];
        $g = $this->guard($dns, 'GPU.lan, 10.0.0.0/8');
        self::assertSame('eu', $g->resolve('http://gpu.lan:11434')->region);
        self::assertSame('eu', $g->resolve('http://lab.lan:11434')->region);
        self::assertSame(OllamaEndpointGuard::ERR_BLOCKED, $this->code(fn () => $g->resolve('http://other.lan:11434')));
        self::assertSame(OllamaEndpointGuard::ERR_BLOCKED, $this->code(fn () => $g->resolve('http://127.0.0.1:11434')));
    }

    public function testAllowlist_readsEnvWhenNotInjected(): void
    {
        putenv(OllamaEndpointGuard::ENV_ALLOWED_HOSTS . '=gpu.lan');
        try {
            $g = new OllamaEndpointGuard(static fn (string $h): array => ['192.168.1.50']);
            self::assertSame('eu', $g->resolve('http://gpu.lan:11434')->region);
            self::assertSame(OllamaEndpointGuard::ERR_BLOCKED, $this->code(fn () => $g->resolve('http://jiny.lan:11434')));
        } finally {
            putenv(OllamaEndpointGuard::ENV_ALLOWED_HOSTS);
        }
    }

    public function testResolve_hostTooLongIsInvalidWithoutCallingResolver(): void
    {
        $resolverCalled = false;
        $resolver = function (string $host) use (&$resolverCalled): array {
            $resolverCalled = true;
            self::fail('resolver should not be called for hostname > 253 chars');
        };
        $longHost = str_repeat('a', 300);
        self::assertSame(OllamaEndpointGuard::ERR_INVALID, $this->code(fn () => (new OllamaEndpointGuard($resolver))->resolve('http://' . $longHost . ':11434')));
        self::assertFalse($resolverCalled, 'resolver should not be called');
    }

    public function testResolve_ipv6Loopback(): void
    {
        self::assertSame('eu', $this->guard()->resolve('http://[::1]:11434')->region);
    }

    public function testNormalize_rejectsNonAsciiHostAndTrailingDot(): void
    {
        $g = $this->guard();
        foreach (['http://gpů.lan:11434', 'http://gpu.lan.:11434', 'http://gpu.lan.'] as $url) {
            self::assertSame(OllamaEndpointGuard::ERR_INVALID, $this->code(fn () => $g->normalize($url)), $url);
        }
    }

    public function testNormalize_acceptsOrdinaryHostnames(): void
    {
        $g = $this->guard();
        foreach (['localhost', 'host.docker.internal', 'gpu.lan', 'ollama-01.lan', 'a0x.lan'] as $h) {
            self::assertSame('http://' . $h . ':11434', $g->normalize('http://' . $h . ':11434'), $h);
        }
    }
}
