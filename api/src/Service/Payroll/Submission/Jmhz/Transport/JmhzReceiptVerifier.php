<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;

/**
 * Napojení protokolů JMHZ na platformu podání.
 *
 * Ověřený protokol je jediná cesta, jak se do `payroll_submissions` dostane
 * `remote_status` — proto se sem nesmí dostat nic, čemu není ověřený podpis.
 * Bez `JmhzProtocolSignatureVerifierInterface` verifier vyhodí výjimku a
 * `importReceipt()` musí být zavolané bez verifieru: uloží protokol jako
 * `verification_status='unverified'` a `remote_status` nechá prázdný. To je
 * správné chování, ne chyba — nepodepsaný protokol je jen příloha.
 */
final readonly class JmhzReceiptVerifier implements PayrollReceiptVerifierInterface
{
    private const CHANNEL = 'vrep_apep';

    /** @param array<string,int> $formPartIds GUID formuláře → id součásti podání */
    public function __construct(
        private ?JmhzProtocolSignatureVerifierInterface $signatures = null,
        private JmhzProtocolParser $parser = new JmhzProtocolParser(),
        private array $formPartIds = [],
        private int $packageCount = 1,
    ) {}

    /** @param array<array-key,int> $formPartIds klíče přicházejí zvenčí a ověřují se */
    public function withFormPartIds(array $formPartIds, int $packageCount = 1): self
    {
        $normalized = [];
        foreach ($formPartIds as $guid => $partId) {
            if (!is_string($guid) || $partId <= 0) {
                throw new JmhzTransportException(
                    'jmhz_protocol_form_mapping_invalid',
                    'Mapa GUID formulářů na součásti podání není platná.',
                );
            }
            $normalized[strtoupper($guid)] = $partId;
        }

        return new self(
            $this->signatures,
            $this->parser,
            $normalized,
            $packageCount,
        );
    }

    public function verify(
        string $bytes,
        string $channel,
        string $environment,
        ?string $expectedCorrelationReference,
    ): PayrollVerifiedReceipt {
        if ($channel !== self::CHANNEL) {
            throw new JmhzTransportException(
                'jmhz_protocol_channel_unsupported',
                'Protokol JMHZ umí ověřit jen kanál VREP/APEP.',
            );
        }
        if ($this->signatures === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_verifier_missing',
                'Protokol ČSSZ nelze prohlásit za důvěryhodný bez ověření podpisu.',
            );
        }
        $xml = $this->signatures->verifiedProtocolXml($bytes, $environment);
        $report = $this->parser->parse($xml, $this->packageCount);
        if ($expectedCorrelationReference !== null
            && $report->correlationReference !== null
            && !hash_equals($expectedCorrelationReference, $report->correlationReference)
        ) {
            throw new JmhzTransportException(
                'jmhz_protocol_correlation_mismatch',
                'Ověřený protokol patří jinému podání.',
            );
        }

        return new PayrollVerifiedReceipt(
            $report->status->payrollRemoteStatus(),
            $report->correlationReference,
            $this->partStatuses($report),
        );
    }

    /** @return array<int,string> */
    private function partStatuses(JmhzProtocolReport $report): array
    {
        if ($this->formPartIds === []) {
            return [];
        }
        $statuses = [];
        foreach ($report->formStatuses as $guid => $status) {
            $partId = $this->formPartIds[strtoupper($guid)] ?? null;
            if ($partId === null) {
                throw new JmhzTransportException(
                    'jmhz_protocol_form_unmapped',
                    'Protokol nese GUID formuláře, který k tomuhle podání nepatří.',
                );
            }
            $statuses[$partId] = $status->payrollRemoteStatus();
        }

        return $statuses;
    }
}
