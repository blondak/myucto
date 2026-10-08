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

    /**
     * Protokol, jehož JEDINOU chybou je kontrola 22 ve variantě „shodné R nebo
     * S už existuje", není zamítnutí. Podání, na které odpovídá, je duplikát
     * originálu, který ČSSZ už má, typicky opakované odeslání po ztracené
     * odpovědi. Vzít ho jako zamítnutí by vedlo k zahození a novému podání
     * s novým GUID, tedy k opravdové duplicitě.
     */
    public function originalAlreadyAtCssz(): bool
    {
        $errors = $this->allErrors();
        if ($errors === []) {
            return false;
        }
        foreach ($errors as $error) {
            if (!$error->reportsExistingIdenticalSubmission()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stav podání, který protokol platformě podání sděluje. Jediné místo, kudy
     * se z protokolu ČSSZ bere `remote_status`: VREP, protokol z datové
     * schránky i znovu ověřovaný protokol.
     *
     * Protokol „originál je u ČSSZ" podání neposouvá ({@see self::originalAlreadyAtCssz()}):
     * zůstává odeslané, dokud se nedoloží protokol originálu.
     */
    public function payrollRemoteStatus(): string
    {
        return $this->originalAlreadyAtCssz()
            ? 'submitted'
            : $this->status->payrollRemoteStatus();
    }

    /** Odmítla ČSSZ podání kvůli chybějícímu pověření k e-službě u OSSZ (chyba 103)? */
    public function missingServiceAuthorization(): bool
    {
        foreach ($this->allErrors() as $error) {
            if ($error->reportsMissingServiceAuthorization()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<JmhzProtocolError> chyby souhrnu i všech částí protokolu */
    public function allErrors(): array
    {
        $errors = $this->errors;
        foreach ($this->parts as $part) {
            $errors = [...$errors, ...$part->errors];
        }

        return $errors;
    }

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
