<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\License;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\License\LicenseClient;
use PHPUnit\Framework\TestCase;

final class LicenseClientPurchaseHandoffTest extends TestCase
{
    public function testStartAndClaimUseServerSideJsonContracts(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"ok":true,"buy_url":"https://myucto.cz/objednavka?h=x","expires_in":7200}'),
            new Response(200, [], '{"ok":true,"license_key":"MYU-TEST","token":"signed"}'),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new LicenseClient(new Config([]), http: $http);

        $client->purchaseSession('iid', 'state', 'challenge', 'https://app.example/activation/purchase');
        $start = $mock->getLastRequest();
        self::assertSame('api/license/purchase-session', $start?->getUri()->getPath());
        self::assertSame([
            'instance_id' => 'iid',
            'state' => 'state',
            'code_challenge' => 'challenge',
            'return_url' => 'https://app.example/activation/purchase',
        ], json_decode((string) $start?->getBody(), true));

        $client->purchaseClaim('order', 'verifier', 'iid', 'fingerprint', '1.0.0', 2, 3);
        $claim = $mock->getLastRequest();
        self::assertSame('api/license/purchase-claim', $claim?->getUri()->getPath());
        self::assertSame([
            'order_token' => 'order',
            'code_verifier' => 'verifier',
            'instance_id' => 'iid',
            'fingerprint' => 'fingerprint',
            'app_version' => '1.0.0',
            'users_active' => 2,
            'companies_active' => 3,
        ], json_decode((string) $claim?->getBody(), true));
    }
}
