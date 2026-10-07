<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Údaje přijatého částečného přihlášení (PREZEC P1) přečtené z archivovaného
 * odeslaného souboru: variabilní symbol zaměstnavatele (`comp/@vs`) a
 * předpokládaný den nástupu (`employee/@predat`).
 *
 * Navazující podání se na ně musí odkazovat. Ukončení předregistrace (P2)
 * nese stejný variabilní symbol jako P1 (PREZEC Předregistrace 1.4, bod
 * Variabilní symbol) a plnou registraci A1 s jiným než předpokládaným dnem
 * nástupu jde dohlásit jen do osmi dnů po předpokládaném dni.
 */
final readonly class PayrollPreRegistrationFiling
{
    /** Nejpozdější nástup po předpokládaném dni, který ještě dohlásí A1. */
    public const MAX_LATE_START_DAYS = 8;

    public function __construct(
        public string $variableSymbol,
        public string $expectedStartOn,
    ) {}

    public static function fromXml(string $xml): ?self
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return null;
        }
        $xpath = new \DOMXPath($document);
        $employee = $xpath->query('/*[local-name()="PREZEC"]/*[local-name()="employees"]/*[local-name()="employee"]');
        $comp = $xpath->query('/*[local-name()="PREZEC"]/*[local-name()="employees"]/*[local-name()="employee"]/*[local-name()="comp"]');
        if ($employee === false || $comp === false
            || $employee->length !== 1 || $comp->length !== 1
            || !$employee->item(0) instanceof \DOMElement
            || !$comp->item(0) instanceof \DOMElement
        ) {
            return null;
        }
        $vs = trim($comp->item(0)->getAttribute('vs'));
        $predat = trim($employee->item(0)->getAttribute('predat'));
        if ($vs === '' || $predat === '') {
            return null;
        }

        return new self($vs, $predat);
    }

    /** Nástup později než osm dnů po předpokládaném dni. */
    public function startIsTooLate(string $actualStartOn): bool
    {
        $expected = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->expectedStartOn);
        $actual = \DateTimeImmutable::createFromFormat('!Y-m-d', $actualStartOn);
        if ($expected === false || $actual === false) {
            return false;
        }

        return $actual > $expected->modify('+' . self::MAX_LATE_START_DAYS . ' days');
    }
}
