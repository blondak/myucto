<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IsdsGatewayOfficialHostTest extends TestCase
{
    /** @return iterable<string,array{string,string,string}> */
    public static function officialPairs(): iterable
    {
        yield 'production current' => [
            'production',
            'datovka.gov.cz',
            'cert.datovka.gov.cz',
        ];
        yield 'production transition domain' => [
            'production',
            'mojedatovaschranka.cz',
            'cert.mojedatovaschranka.cz',
        ];
        yield 'test' => [
            'test',
            'datovka-test.gov.cz',
            'cert.datovka-test.gov.cz',
        ];
    }

    #[DataProvider('officialPairs')]
    public function testOfficialPairsAreAccepted(
        string $environment,
        string $portalHost,
        string $serviceHost,
    ): void {
        self::assertTrue(IsdsGatewayRegistrationService::isOfficialHostPair(
            $environment,
            $portalHost,
            $serviceHost,
        ));
    }

    public function testArbitraryServiceHostCannotReceiveGatewayCredentials(): void
    {
        self::assertFalse(IsdsGatewayRegistrationService::isOfficialHostPair(
            'production',
            'datovka.gov.cz',
            'isds-proxy.example.invalid',
        ));
    }

    public function testHostsFromDifferentEnvironmentsCannotBeMixed(): void
    {
        self::assertFalse(IsdsGatewayRegistrationService::isOfficialHostPair(
            'production',
            'datovka.gov.cz',
            'cert.datovka-test.gov.cz',
        ));
    }

    public function testUnknownEnvironmentIsFailClosed(): void
    {
        self::assertFalse(IsdsGatewayRegistrationService::isOfficialHostPair(
            'staging',
            'datovka.gov.cz',
            'cert.datovka.gov.cz',
        ));
    }
}
