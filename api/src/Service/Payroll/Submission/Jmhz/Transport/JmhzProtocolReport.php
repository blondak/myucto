<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

final readonly class JmhzProtocolReport
{
    /**
     * @param list<JmhzProtocolPart> $parts
     * @param list<JmhzProtocolError> $errors
     * @param array<string,JmhzSubmissionStatus> $formStatuses klíčem je GUID formuláře
     */
    public function __construct(
        public JmhzProtocolKind $kind,
        public string $submissionClass,
        public JmhzSubmissionStatus $status,
        public ?string $correlationReference,
        public array $parts,
        public array $errors,
        public array $formStatuses,
        /**
         * GUID podání (`idPodani`), pod kterým jsme zprávu odeslali.
         * Protokol o zpracování ho vrací zpátky, takže se dvojice páruje
         * naším vlastním identifikátorem, ne jen tím, který přidělí ČSSZ.
         */
        public ?string $submissionGuid = null,
        /**
         * Variabilní symbol zaměstnavatele z protokolu.
         *
         * Je to JEDINÝ údaj, kterým se u načteného souboru dá ověřit, že
         * protokol patří téhle firmě. Obálka GovTalk ho nenese, takže u ní
         * zůstává `null` — a načtení takového protokolu se pak nesmí tvářit
         * jako ověřené.
         */
        public ?string $variableSymbol = null,
        /** Období hlášení, ke kterému protokol patří (`mesic`/`rok`). */
        public ?int $periodMonth = null,
        public ?int $periodYear = null,
        /** `datumProtokolu` a `datumPodani` tak, jak je ČSSZ napsala. */
        public ?string $protocolDate = null,
        public ?string $submittedDate = null,
    ) {}

    /** @return list<JmhzProtocolError> */
    public function errorsForForm(string $formGuid): array
    {
        $needle = strtoupper($formGuid);
        $found = [];
        foreach ($this->parts as $part) {
            if ($part->formGuid !== null && strtoupper($part->formGuid) === $needle) {
                $found = [...$found, ...$part->errors];
            }
        }

        return $found;
    }
}
