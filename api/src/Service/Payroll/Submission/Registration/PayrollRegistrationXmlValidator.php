<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use DOMDocument;

final readonly class PayrollRegistrationXmlValidator
{
    public function __construct(
        private PayrollRegistrationSchemaCatalog $schemas,
    ) {}

    public function validate(
        PayrollRegistrationXmlPayload $payload,
        string $xml,
    ): void {
        $this->validateBusinessBoundary($payload);
        $expected = (new PayrollRegistrationXmlSerializer())
            ->serialize($payload);
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $xml))) {
            $this->invalid(
                'registration_xml_snapshot_mismatch',
                'XML byteově neodpovídá zdrojovému snapshotu.',
            );
        }
        $schema = $this->schemas->schemaFor(
            $payload->interaction->documentType,
        );
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_NOBLANKS,
        );
        // `schemaValidate()` žádný `LIBXML_NONET` nepřijímá (druhý parametr umí
        // jen `LIBXML_SCHEMA_CREATE`), takže síť tu vypnout nejde. Offline běh
        // drží jinde: jediné `xs:import` obou připnutých schémat míří na
        // relativní `baseTypes2.xsd` a oba soubory se ověřují SHA-256
        // v `PayrollRegistrationSchemaCatalog` — vzdálený `schemaLocation`
        // by musel projít změnou otisku, která validaci zavře.
        $valid = $loaded && $document->schemaValidate($schema['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            $messages = array_map(
                static fn (\LibXMLError $error): string =>
                    trim($error->message),
                $errors,
            );
            $this->invalid(
                'registration_xsd_validation_failed',
                'Registrační XML neprošlo připnutým XSD: '
                    . implode('; ', array_unique($messages)),
            );
        }
    }

    private function validateBusinessBoundary(
        PayrollRegistrationXmlPayload $payload,
    ): void {
        // XSD tuhle hranici neuhlídá: REGZEC25 povoluje `act` 1..99, takže
        // opravy a storna (A2–A8) by prošly. Allowlist interakcí je jediné
        // místo, kde se rozhoduje, co core vůbec umí vyrobit.
        //
        // Payload je volně sestavitelný, takže tady se přehrává CELÁ vazba na
        // zmrazený snapshot (agenda, způsobilost, identifikátor, allowlist)
        // ze stejné implementace, kterou používá resolver — jinak by ji stačilo
        // obejít tím, že se resolver prostě nezavolá.
        (new PayrollRegistrationInteractionResolver())->assertBoundToSnapshot(
            $payload->identity,
            $payload->interaction,
        );
        if ($payload->sequenceNumber < 1
            || $payload->sequenceNumber > 1500
            || preg_match(
                '/^[0-9A-F]{8}(?:-[0-9A-F]{4}){3}-[0-9A-F]{12}$/D',
                $payload->formGuid,
            ) !== 1
            || preg_match('/^\d{8,10}$/D', $payload->employerVariableSymbol)
                !== 1
        ) {
            $this->invalid(
                'registration_payload_invalid',
                'Registrační payload nemá platná metadata.',
            );
        }
        if ($payload->interaction->documentType === 'PREZEC26') {
            // Způsobilost je vázaná na STÁTNÍ OBČANSTVÍ, ne na držení RČ.
            // Je to vědomě přísnější fail-closed varianta: cizinec s trvalým
            // pobytem a přiděleným českým rodným číslem by PREZEC strukturálně
            // naplnil, ale rozhodnout, jestli na něj částečné přihlášení
            // vůbec dosáhne, umí jedině katalog kontrol MH na
            // developers.mpsv.cz (lokálně ho nemáme, viz otevřený bod
            // v `private/MZDY-EPICs.md` § „Čeká na rozhodnutí člověka").
            // Do té doby musí hláška uživateli přesně říct, co udělat.
            if (($payload->identity->identity['citizenship_country_code']
                    ?? null) !== 'CZ'
            ) {
                $this->invalid(
                    'registration_prezec_foreign_requires_full_registration',
                    'PREZEC (částečné přihlášení před nástupem) se tady podává jen zaměstnanci s českým státním občanstvím. U zaměstnance s jiným občanstvím — i když má přidělené české rodné číslo — podejte místo něj plnou registraci REGZEC, a to před zahájením práce.',
                );
            }
            if ($payload->interaction->actionCode === 9) {
                // Bez striktní kontroly by se `null`/prázdný řetězec propadl do
                // `new DateTimeImmutable('')`, tedy do systémového „dneška",
                // a okno by se počítalo proti času běhu; nesmyslný řetězec by
                // navíc vyhodil `DateMalformedStringException` mimo kontrakt.
                $start = $this->exactDate($payload->expectedStartOn);
                $prepared = $this->exactDate($payload->preparedOn);
                $days = (int) $prepared->diff($start)->format('%r%a');
                if ($days < 0 || $days > 8) {
                    $this->invalid(
                        'registration_prezec_start_window_invalid',
                        'PREZEC P1 lze podat nejvýše osm dnů před nástupem.',
                    );
                }
            }
        } elseif ($payload->employerName === null
            || $payload->csszWorkplaceCode === null
            || $payload->actualStartOn === null
        ) {
            $this->invalid(
                'registration_regzec_full_payload_incomplete',
                'REGZEC A1 nemá úplná povinná metadata zaměstnavatele a nástupu.',
            );
        }
    }

    private function exactDate(?string $value): \DateTimeImmutable
    {
        $date = $value === null
            ? false
            : \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            $this->invalid(
                'registration_prezec_start_date_invalid',
                'PREZEC P1 vyžaduje datum nástupu i datum vyhotovení ve tvaru RRRR-MM-DD.',
            );
        }

        return $date;
    }

    private function invalid(string $code, string $message): never
    {
        throw new PayrollRegistrationXmlException($code, $message);
    }
}
