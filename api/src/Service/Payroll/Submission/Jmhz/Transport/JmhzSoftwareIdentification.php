<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Identifikace odesílajícího software. Doložené místo je `VENDOR/@productName`
 * a `VENDOR/@productVersion` přímo v datové větě (`jmhzPodani.xsd`), ne
 * v GovTalk obálce — obálka ji proto jen ověřuje proti tělu podání.
 */
final readonly class JmhzSoftwareIdentification
{
    public function __construct(
        public string $productName,
        public string $productVersion,
    ) {
        if ($productName === '' || $productVersion === '') {
            throw new JmhzTransportException(
                'jmhz_software_identification_invalid',
                'Identifikace odesílajícího software nesmí být prázdná.',
            );
        }
    }
}
