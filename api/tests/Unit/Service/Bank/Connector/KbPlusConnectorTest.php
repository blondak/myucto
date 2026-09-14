<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Repository\KbPlusOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\KbPlusAboBatchMapper;
use MyInvoice\Service\Bank\Connector\KbPlusApiClient;
use MyInvoice\Service\Bank\Connector\KbPlusConnector;
use MyInvoice\Service\Bank\Connector\KbPlusCredentialVault;
use MyInvoice\Service\Bank\Connector\KbPlusTransactionParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class KbPlusConnectorTest extends TestCase
{
    private KbPlusApiClient&MockObject $api;
    private KbPlusCredentialVault $vault;
    private KbPlusConnector $connector;

    protected function setUp(): void
    {
        $this->api = $this->createMock(KbPlusApiClient::class);
        $this->vault = new KbPlusCredentialVault(
            $this->api,
            $this->createMock(KbPlusOAuthRepository::class),
            $this->createMock(SecretEncryption::class),
        );
        $this->connector = new KbPlusConnector($this->api, $this->vault, new KbPlusTransactionParser(), new KbPlusAboBatchMapper());
    }

    public function testReadOnlyConsentRefusesPaymentsBeforeCallingBank(): void
    {
        $this->api->expects(self::never())->method('submitPaymentBatch');
        $this->api->expects(self::never())->method('refreshAccessToken');
        $token = $this->vault->encode($this->credentials('', 'adaa'));

        self::assertFalse($this->connector->canSubmitPaymentOrder($token));
        try {
            $this->connector->submitPaymentOrder($token, 'SYNTHETIC-ABO');
            self::fail('Bez souhlasu bpisp se příkaz nesmí předat bance.');
        } catch (BankConnectorException $e) {
            self::assertSame('payment_submission_unavailable', $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
        }
    }

    public function testSeparateBatchKeyWithoutBpispConsentStillRefusesPayments(): void
    {
        $this->api->expects(self::never())->method('submitPaymentBatch');
        $token = $this->vault->encode($this->credentials('synthetic-batchda-key', 'adaa'));

        self::assertFalse($this->connector->canSubmitPaymentOrder($token));
    }

    public function testBpispConsentEnablesPaymentsWithoutSeparateBatchKey(): void
    {
        $token = $this->vault->encode($this->credentials('', 'adaa bpisp'));

        self::assertTrue($this->connector->canSubmitPaymentOrder($token));
    }

    public function testBpispConsentWithSeparateBatchKeyCanSubmitPayments(): void
    {
        $token = $this->vault->encode($this->credentials('synthetic-batchda-key', 'adaa bpisp'));

        self::assertTrue($this->connector->canSubmitPaymentOrder($token));
    }

    public function testBasicPlanRefusesPaymentsEvenWithBpispConsent(): void
    {
        $this->api->expects(self::never())->method('submitPaymentBatch');
        $token = $this->vault->encode(['api_plan' => 'basic'] + $this->credentials('', 'adaa bpisp'));

        self::assertFalse($this->connector->canSubmitPaymentOrder($token));
        try {
            $this->connector->submitPaymentOrder($token, 'SYNTHETIC-ABO');
            self::fail('Varianta Basic příkazy neumí, nesmí se předat bance.');
        } catch (BankConnectorException $e) {
            self::assertSame('payment_submission_unavailable', $e->errorCode);
        }
    }

    public function testBasicPlanDownloadsOnlyUpToYesterday(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $weekAgo = (new \DateTimeImmutable('-7 days'))->format('Y-m-d');
        $periods = [];
        $this->api->method('transactions')->willReturnCallback(
            static function (array $credentials, string $accessToken, string $accountId, string $from, string $to) use (&$periods): array {
                $periods[] = [$from, $to];
                return ['transactions' => [], 'pages' => 1];
            },
        );
        $token = $this->vault->encode(['api_plan' => 'basic'] + $this->credentials('', 'adaa'));

        $this->connector->downloadStatement($token, $weekAgo, $today);
        $this->connector->downloadStatement($token, $today, $today);

        self::assertSame([[$weekAgo, $yesterday], [$yesterday, $yesterday]], $periods);
    }

    /** Údaje uložené před zavedením volby varianty se chovají jako Plus. */
    public function testPlusAndLegacyCredentialsKeepRequestedPeriod(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $periods = [];
        $this->api->method('transactions')->willReturnCallback(
            static function (array $credentials, string $accessToken, string $accountId, string $from, string $to) use (&$periods): array {
                $periods[] = [$from, $to];
                return ['transactions' => [], 'pages' => 1];
            },
        );

        $this->connector->downloadStatement($this->vault->encode($this->credentials('', 'adaa')), $today, $today);
        $this->connector->downloadStatement($this->vault->encode(['api_plan' => 'plus'] + $this->credentials('', 'adaa')), $today, $today);

        self::assertSame([[$today, $today], [$today, $today]], $periods);
    }

    public function testAutomaticSyncIntervalFollowsPlan(): void
    {
        self::assertSame(86400, $this->connector->minimumAutomaticSyncIntervalSeconds(
            $this->vault->encode(['api_plan' => 'basic'] + $this->credentials('', 'adaa')),
        ));
        self::assertSame(3660, $this->connector->minimumAutomaticSyncIntervalSeconds(
            $this->vault->encode(['api_plan' => 'plus'] + $this->credentials('', 'adaa')),
        ));
        self::assertSame(3660, $this->connector->minimumAutomaticSyncIntervalSeconds(
            $this->vault->encode($this->credentials('', 'adaa')),
        ));
    }

    public function testUnknownPlanIsRejectedByVault(): void
    {
        $this->expectException(BankConnectorException::class);

        $this->vault->encode(['api_plan' => 'pro'] + $this->credentials('', 'adaa'));
    }

    /** @return array<string,mixed> */
    private function credentials(string $batchKey, string $scope): array
    {
        return [
            'version' => 1,
            'supplier_id' => 7,
            'connection_id' => 19,
            'oauth_api_key' => 'synthetic-oauth-key',
            'adaa_api_key' => 'synthetic-adaa-key',
            'batchda_api_key' => $batchKey,
            'client_id' => 'synthetic-client',
            'client_secret' => 'synthetic-client-secret',
            'redirect_uri' => 'https://example.invalid/callback',
            'scope' => $scope,
            'refresh_token' => 'synthetic-refresh-token-0001',
            'access_token' => 'synthetic-access-token-0001',
            'access_expires_at' => time() + 180,
            'account_id' => 'synthetic-account',
            'account_iban' => 'CZ0000000000000000000000',
            'account_currency' => 'CZK',
            'call_guard_key' => str_repeat('g', 43),
        ];
    }
}
