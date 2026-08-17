<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Submission;

use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayCredential;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayLoginPolicy;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistration;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use PHPUnit\Framework\TestCase;

/**
 * Pověření odesílací brány nesmí uniknout ani výpisem, ani do auditní stopy.
 *
 * `timeLimitedId` je HESLO Basic autentizace vůči bráně a klientský certifikát
 * je pověření celé služby — kdo je má, může jménem MyÚčta vkládat koncepty
 * do cizích datových schránek.
 */
final class IsdsGatewaySecretLeakTest extends TestCase
{
    private const SECRET = 'T01-7616671e421f4efb8fa1f7bc5b80a913';

    public function testCredentialNeverPrintsTheTimeLimitedId(): void
    {
        $credential = $this->credential();

        foreach ([print_r($credential, true), var_export($credential, true), (string) json_encode($credential)] as $dump) {
            self::assertStringNotContainsString(self::SECRET, $dump);
        }

        ob_start();
        var_dump($credential);
        $dumped = (string) ob_get_clean();
        self::assertStringNotContainsString(self::SECRET, $dumped);
    }

    /**
     * Serializace je ta nejzrádnější cesta ven: stačilo by uložit pověření do
     * session nebo do fronty úloh a heslo k bráně by leželo v perzistentní
     * vrstvě nešifrovaně.
     */
    public function testCredentialRefusesSerialization(): void
    {
        $this->expectException(\LogicException::class);
        serialize($this->credential());
    }

    public function testRegistrationRefusesSerialization(): void
    {
        $this->expectException(\LogicException::class);
        serialize($this->registration());
    }

    public function testRegistrationLogContextCarriesNoSecret(): void
    {
        $context = $this->registration()->toLogContext();

        self::assertSame(['environment', 'ats_id', 'portal_host', 'service_host'], array_keys($context));
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($context));
    }

    public function testCredentialLogContextCarriesNoSecret(): void
    {
        $context = $this->credential()->toLogContext();

        self::assertSame(['concept_dm_id', 'concept_status_code'], array_keys($context));
    }

    /**
     * `ActivityLogger::REDACT_KEYS` musí pokrývat všechno, čím se brána
     * prokazuje. Test jde přes veřejné `redact()`, takže hlídá skutečné
     * chování, ne obsah konstanty.
     */
    public function testActivityLoggerRedactsGatewaySecrets(): void
    {
        $logger = new ActivityLogger(
            // Konstruktor `Connection` se tu nepoužije — `redact()` na databázi
            // nesahá. Reflexe je levnější než celý kontejner.
            (new \ReflectionClass(\MyInvoice\Infrastructure\Database\Connection::class))->newInstanceWithoutConstructor(),
        );

        $redacted = $logger->redact([
            'time_limited_id' => self::SECRET,
            'timeLimitedId' => self::SECRET,
            'session_id' => '01-8c57c8b70acb41598456914f17ae933b',
            'sessionId' => '01-8c57c8b70acb41598456914f17ae933b',
            'app_token' => '123456789012345678',
            'appToken' => '123456789012345678',
            'certificate' => 'PKCS12-BINARY',
            'certificate_password' => 'tajne',
            'certificate_passphrase' => 'tajne',
            'certificate_ciphertext' => 'enc:v2:abc',
            'pkey' => '-----BEGIN PRIVATE KEY-----',
            'nested' => ['time_limited_id' => self::SECRET],
            'concept_dm_id' => 'DM-9000',
        ]);

        foreach ([
            'time_limited_id', 'timeLimitedId', 'session_id', 'sessionId',
            'app_token', 'appToken', 'certificate', 'certificate_password',
            'certificate_passphrase', 'certificate_ciphertext', 'pkey',
        ] as $key) {
            self::assertSame('[REDACTED]', $redacted[$key], 'Klíč ' . $key . ' musí být redigovaný.');
        }
        self::assertSame('[REDACTED]', $redacted['nested']['time_limited_id']);

        // ID odeslané zprávy je naopak DŮKAZ a redigovat se nesmí — bez něj by
        // v auditní stopě nebylo poznat, co odešlo.
        self::assertSame('DM-9000', $redacted['concept_dm_id']);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($redacted));
    }

    /**
     * Nedoložená otázka o přihlášení Identitou občana zůstává pojmenovaná:
     * výchozí hodnota je `unknown` a text uživateli nic netvrdí navíc.
     */
    public function testLoginPolicyDefaultsToUndocumented(): void
    {
        self::assertSame(IsdsGatewayLoginPolicy::Unknown, IsdsGatewayLoginPolicy::fromDatabase(null));
        self::assertSame(IsdsGatewayLoginPolicy::Unknown, IsdsGatewayLoginPolicy::fromDatabase('nesmysl'));
        self::assertFalse(IsdsGatewayLoginPolicy::Unknown->isDocumented());
        self::assertTrue(IsdsGatewayLoginPolicy::PortalSsoOrPassword->isDocumented());

        foreach (IsdsGatewayLoginPolicy::cases() as $policy) {
            self::assertNotSame('', $policy->userGuidance());
            self::assertStringContainsString('neukládáme', $policy->userGuidance());
        }
    }

    /** Úspěch je přesně `0000`. `00xx` se nesmí brát jako úspěch (nález S-2). */
    public function testOnlyExactZeroCodeCountsAsDispatched(): void
    {
        self::assertTrue($this->credential('0000', 'DM-1')->isDispatched());
        self::assertFalse($this->credential('0099', 'DM-1')->isDispatched());
        self::assertFalse($this->credential('0000', null)->isDispatched());
        self::assertTrue($this->credential('2305', null)->isRejectedByUser());
        self::assertFalse($this->credential(null, null)->hasConceptOutcome());
    }

    private function credential(?string $statusCode = '0000', ?string $messageId = 'DM-9000'): IsdsGatewayCredential
    {
        return new IsdsGatewayCredential(
            timeLimitedId: SensitiveValue::fromProducer(static fn (): string => self::SECRET),
            appToken: '123456789012345678',
            conceptDmId: $messageId,
            conceptStatusCode: $statusCode,
            conceptStatusMessage: 'Provedeno úspěšně.',
        );
    }

    private function registration(): IsdsGatewayRegistration
    {
        return new IsdsGatewayRegistration(
            environment: 'test',
            atsId: 'TESTGW1',
            label: 'Testovací brána',
            returnUrl: 'https://dev.myucto.cz/api/submissions/gateway/callback',
            errorUrl: null,
            conceptTtlSeconds: 900,
            portalHost: 'datovka-test.gov.cz',
            serviceHost: 'cert.datovka-test.gov.cz',
            loginPolicy: IsdsGatewayLoginPolicy::Unknown,
            certificate: SensitiveValue::fromProducer(static fn (): string => self::SECRET),
            certificatePassphrase: null,
            certificateFingerprint: str_repeat('a', 64),
            certificateValidTo: null,
        );
    }
}
