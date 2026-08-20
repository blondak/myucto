<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayLoginPolicy;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistration;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationSource;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Registrace brány bez databáze.
 *
 * Certifikát je schválně nesmysl: testy adaptéru se nesmí dostat nikam, kde by
 * na něm záleželo — a kdyby se tam dostaly, mají spadnout hlasitě.
 */
final class FakeIsdsGatewayRegistrationSource implements IsdsGatewayRegistrationSource
{
    /** @var array<string,bool> */
    public array $ready = ['production' => true, 'test' => true];

    /** Chyba, kterou má `load()` vyhodit místo registrace. */
    public ?SubmissionChannelException $loadFailure = null;

    /** Vyhodí `isDispatchReady()` výjimku? (rozbitá databáze, chybějící tabulka) */
    public ?\Throwable $readyFailure = null;

    /** @var list<string> */
    public array $loadedEnvironments = [];

    public function isDispatchReady(string $environment): bool
    {
        if ($this->readyFailure !== null) {
            throw $this->readyFailure;
        }

        return $this->ready[$environment] ?? false;
    }

    public function load(string $environment): IsdsGatewayRegistration
    {
        $this->loadedEnvironments[] = $environment;
        if ($this->loadFailure !== null) {
            throw $this->loadFailure;
        }

        return new IsdsGatewayRegistration(
            environment: $environment,
            atsId: 'ATS-TEST',
            label: 'MyÚčto (test)',
            returnUrl: 'https://dev.myucto.cz/api/submissions/gateway/callback',
            errorUrl: null,
            conceptTtlSeconds: 900,
            portalHost: 'datovka-test.gov.cz',
            serviceHost: 'cert.datovka-test.gov.cz',
            loginPolicy: IsdsGatewayLoginPolicy::Unknown,
            certificate: SensitiveValue::fromProducer(static fn (): string => 'NENI-CERTIFIKAT'),
            certificatePassphrase: null,
            certificateFingerprint: str_repeat('ab', 32),
            certificateValidTo: '2030-01-01 00:00:00',
        );
    }
}
