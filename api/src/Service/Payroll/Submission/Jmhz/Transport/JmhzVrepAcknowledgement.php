<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Potvrzení VREP o převzetí zprávy. Není to protokol o zpracování — říká jen
 * „mám ji" a přiděluje `CorrelationID`, pod kterým se pak na výsledek doptáme.
 *
 * Rozdíl je podstatný: kdo si potvrzení převzetí splete s přijetím podání,
 * ohlásí uživateli hotovo ve chvíli, kdy se hlášení teprve začíná kontrolovat.
 */
final readonly class JmhzVrepAcknowledgement
{
    public function __construct(
        public string $correlationId,
        public string $pollEndpoint,
        public int $pollIntervalSeconds,
        public string $gatewayTimestamp,
        public string $submissionClass,
    ) {}
}
