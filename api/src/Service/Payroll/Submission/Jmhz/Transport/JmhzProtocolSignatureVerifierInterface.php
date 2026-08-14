<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Ověření podpisu protokolu ČSSZ. Vrací kanonické bajty XML, jejichž podpis byl
 * ověřen — nikdy jen `true`/`false`, aby parser nemohl dostat jiné bajty, než
 * které prošly ověřením.
 */
interface JmhzProtocolSignatureVerifierInterface
{
    public function verifiedProtocolXml(string $bytes, string $environment): string;
}
