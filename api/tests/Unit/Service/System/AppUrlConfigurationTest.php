<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AppUrlConfigurationTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function testClassifiesCanonicalUrlWithoutChangingRoutingAndWebAuthnBoundaries(
        string $url,
        string $state,
        string $reasonCode,
        bool $routingCompatible,
        bool $webAuthnCompatible,
    ): void {
        self::assertSame([
            'state' => $state,
            'reason_code' => $reasonCode,
            'routing_compatible' => $routingCompatible,
            'webauthn_compatible' => $webAuthnCompatible,
        ], $this->configuration($url)->status());
    }

    /** @return iterable<string,array{string,string,string,bool,bool}> */
    public static function statusProvider(): iterable
    {
        yield 'missing' => [
            '',
            AppUrlConfiguration::STATE_MISSING,
            AppUrlConfiguration::REASON_MISSING,
            false,
            false,
        ];
        yield 'blank' => [
            " \t\r\n ",
            AppUrlConfiguration::STATE_MISSING,
            AppUrlConfiguration::REASON_MISSING,
            false,
            false,
        ];
        yield 'not a URL' => [
            'invoice.example.test',
            AppUrlConfiguration::STATE_INVALID,
            AppUrlConfiguration::REASON_INVALID_ORIGIN,
            false,
            false,
        ];
        yield 'non HTTP scheme' => [
            'ftp://invoice.example.test',
            AppUrlConfiguration::STATE_INVALID,
            AppUrlConfiguration::REASON_INVALID_ORIGIN,
            false,
            false,
        ];
        yield 'userinfo' => [
            'https://user:password@invoice.example.test',
            AppUrlConfiguration::STATE_INVALID,
            AppUrlConfiguration::REASON_INVALID_ORIGIN,
            false,
            false,
        ];
        yield 'path' => [
            'https://invoice.example.test/app',
            AppUrlConfiguration::STATE_INVALID,
            AppUrlConfiguration::REASON_INVALID_ORIGIN,
            false,
            false,
        ];
        yield 'query' => [
            'https://invoice.example.test/?token=secret',
            AppUrlConfiguration::STATE_INVALID,
            AppUrlConfiguration::REASON_INVALID_ORIGIN,
            false,
            false,
        ];
        yield 'fragment' => [
            'https://invoice.example.test/#login',
            AppUrlConfiguration::STATE_INVALID,
            AppUrlConfiguration::REASON_INVALID_ORIGIN,
            false,
            false,
        ];
        yield 'invalid hostname' => [
            'https://bad_host.example',
            AppUrlConfiguration::STATE_INVALID,
            AppUrlConfiguration::REASON_INVALID_ORIGIN,
            false,
            false,
        ];
        yield 'HTTP DNS routing' => [
            'http://invoice.example.test:8080',
            AppUrlConfiguration::STATE_ROUTING_ONLY,
            AppUrlConfiguration::REASON_WEBAUTHN_INCOMPATIBLE,
            true,
            false,
        ];
        yield 'HTTP LAN IPv4 routing' => [
            'http://192.168.10.20:8080',
            AppUrlConfiguration::STATE_ROUTING_ONLY,
            AppUrlConfiguration::REASON_WEBAUTHN_INCOMPATIBLE,
            true,
            false,
        ];
        yield 'HTTPS LAN IPv6 routing' => [
            'https://[fd00::20]:8443',
            AppUrlConfiguration::STATE_ROUTING_ONLY,
            AppUrlConfiguration::REASON_WEBAUTHN_INCOMPATIBLE,
            true,
            false,
        ];
        yield 'HTTPS DNS WebAuthn' => [
            'https://invoice.example.test/',
            AppUrlConfiguration::STATE_WEBAUTHN_READY,
            AppUrlConfiguration::REASON_VALID,
            true,
            true,
        ];
        yield 'HTTP localhost WebAuthn exception' => [
            'http://localhost:8080/',
            AppUrlConfiguration::STATE_WEBAUTHN_READY,
            AppUrlConfiguration::REASON_VALID,
            true,
            true,
        ];
    }

    public function testPublicStatusNeverReflectsTheConfiguredUrlOrItsSensitiveParts(): void
    {
        $url = 'https://diagnostic-user:diagnostic-password@example.test/private'
            . '?access_token=diagnostic-secret#diagnostic-fragment';

        $encoded = json_encode(
            $this->configuration($url)->status(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        foreach ([
            $url,
            'diagnostic-user',
            'diagnostic-password',
            'private',
            'access_token',
            'diagnostic-secret',
            'diagnostic-fragment',
            'example.test',
        ] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $encoded);
        }
        self::assertSame(AppUrlConfiguration::REASON_INVALID_ORIGIN, json_decode(
            $encoded,
            true,
            flags: JSON_THROW_ON_ERROR,
        )['reason_code']);
    }

    #[DataProvider('unusableLoggingProvider')]
    public function testUnusableCanonicalConfigurationLogsOnlyStableSafeIdentifiers(
        string $url,
        string $state,
        string $reasonCode,
        array $sensitiveParts,
    ): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'configuration.app_url_unusable',
                self::callback(function (array $context) use ($state, $reasonCode, $sensitiveParts): bool {
                    self::assertSame([
                        'state' => $state,
                        'reason_code' => $reasonCode,
                    ], $context);

                    $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    foreach ($sensitiveParts as $sensitive) {
                        self::assertStringNotContainsString($sensitive, $encoded);
                    }

                    return true;
                }),
            );

        $configuration = $this->configuration($url, $logger);
        $configuration->status();
        $configuration->status();
    }

    /** @return iterable<string,array{string,string,string,list<string>}> */
    public static function unusableLoggingProvider(): iterable
    {
        yield 'missing' => [
            '',
            AppUrlConfiguration::STATE_MISSING,
            AppUrlConfiguration::REASON_MISSING,
            [],
        ];
        yield 'invalid with secrets' => [
            'https://log-user:log-password@private.example.test/path?token=log-secret#fragment',
            AppUrlConfiguration::STATE_INVALID,
            AppUrlConfiguration::REASON_INVALID_ORIGIN,
            [
                'log-user',
                'log-password',
                'private.example.test',
                'path',
                'token',
                'log-secret',
                'fragment',
            ],
        ];
    }

    #[DataProvider('usableLoggingProvider')]
    public function testRoutingCompatibleCanonicalConfigurationDoesNotLogAnUnusableWarning(
        string $url,
    ): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->configuration($url, $logger)->status();
    }

    /** @return iterable<string,array{string}> */
    public static function usableLoggingProvider(): iterable
    {
        yield 'routing only' => ['http://192.168.10.20:8080'];
        yield 'WebAuthn ready' => ['https://invoice.example.test'];
    }

    public function testHostnameConflictOverridesCachedValidVerdictAndLogsOnlyOnce(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('configuration.app_url_unusable', [
                'state' => AppUrlConfiguration::STATE_HOSTNAME_CONFLICT,
                'reason_code' => AppUrlConfiguration::REASON_HOSTNAME_CONFLICT,
            ]);
        $configuration = $this->configuration('https://invoice.example.test', $logger);

        self::assertSame(
            AppUrlConfiguration::STATE_WEBAUTHN_READY,
            $configuration->status()['state'],
        );
        self::assertSame(
            AppUrlConfiguration::STATE_HOSTNAME_CONFLICT,
            $configuration->hostnameConflictStatus()['state'],
        );
        self::assertSame(
            AppUrlConfiguration::REASON_HOSTNAME_CONFLICT,
            $configuration->hostnameConflictStatus()['reason_code'],
        );
    }

    #[DataProvider('setupReplacementProvider')]
    public function testSetupOnlyReplacesMissingBlankOrKnownPlaceholder(
        string $url,
        bool $expected,
    ): void {
        self::assertSame($expected, $this->configuration($url)->shouldSetupUseDetectedOrigin());
    }

    /** @return iterable<string,array{string,bool}> */
    public static function setupReplacementProvider(): iterable
    {
        yield 'missing' => ['', true];
        yield 'blank' => [" \t\r\n ", true];
        yield 'Docker placeholder' => ['http://localhost:8080/', true];
        yield 'sample placeholder' => ['https://dev.example.com', true];
        yield 'generic placeholder' => ['https://example.com', true];
        yield 'malformed wrapped placeholder' => [' https://example.com ', false];
        yield 'explicit malformed value' => ['https://bad_host.example', false];
        yield 'explicit non-origin value' => ['https://example.test/app?token=secret', false];
        yield 'explicit valid value' => ['https://invoice.example.test', false];
    }

    private function configuration(
        string $url,
        ?LoggerInterface $logger = null,
    ): AppUrlConfiguration
    {
        return new AppUrlConfiguration(
            new Config(['app' => ['url' => $url]]),
            new HostnameNormalizer(),
            $logger ?? new NullLogger(),
        );
    }
}
