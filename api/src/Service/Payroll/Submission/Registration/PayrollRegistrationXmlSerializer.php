<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use DOMDocument;
use DOMElement;

final class PayrollRegistrationXmlSerializer
{
    public function serialize(PayrollRegistrationXmlPayload $payload): string
    {
        if (!$payload->interaction->supported()) {
            throw new PayrollRegistrationXmlException(
                'registration_interaction_unsupported',
                'Core umí jen PREZEC P1/P2 a REGZEC A1; opravy, storna a další akce zůstávají uzavřené.',
            );
        }

        return match ($payload->interaction->documentType) {
            'PREZEC26' => $this->prezec($payload),
            'REGZEC25' => $this->regzec($payload),
            default => throw new PayrollRegistrationXmlException(
                'registration_serializer_unavailable',
                'Požadovaný registrační formulář nemá serializér.',
            ),
        };
    }

    private function prezec(PayrollRegistrationXmlPayload $payload): string
    {
        $namespace = 'http://schemas.cssz.cz/PREZEC/2026';
        $document = $this->document();
        $root = $document->createElementNS($namespace, 'PREZEC');
        $document->appendChild($root);
        $employees = $this->element($document, $namespace, 'employees');
        $employee = $this->element($document, $namespace, 'employee');
        $this->employeeAttributes($employee, $payload, true);
        $client = $this->element($document, $namespace, 'client');
        $client->setAttribute('bno', $this->bno($payload));
        if ($payload->interaction->actionCode === 9) {
            $this->identityElements($document, $namespace, $client, $payload);
        }
        $employee->appendChild($client);
        $comp = $this->element($document, $namespace, 'comp');
        $comp->setAttribute('vs', $payload->employerVariableSymbol);
        $employee->appendChild($comp);
        $employees->appendChild($employee);
        $root->appendChild($employees);

        return $this->save($document);
    }

    private function regzec(PayrollRegistrationXmlPayload $payload): string
    {
        $namespace = 'http://schemas.cssz.cz/REGZEC/2025';
        $document = $this->document();
        $root = $document->createElementNS($namespace, 'REGZEC');
        $document->appendChild($root);
        $employees = $this->element($document, $namespace, 'employees');
        $employee = $this->element($document, $namespace, 'employee');
        $this->employeeAttributes($employee, $payload, false);
        $client = $this->element($document, $namespace, 'client');
        $bno = $this->nullableBno($payload);
        if ($bno !== null) {
            $client->setAttribute('bno', $bno);
        } elseif ($payload->identity->identifiers['vcp'] !== null) {
            $client->setAttribute(
                'vcp',
                $payload->identity->identifiers['vcp'],
            );
        }
        $this->identityElements($document, $namespace, $client, $payload);
        $employee->appendChild($client);
        $comp = $this->element($document, $namespace, 'comp');
        $comp->setAttribute('vs', $payload->employerVariableSymbol);
        $comp->setAttribute('nam', (string) $payload->employerName);
        $employee->appendChild($comp);
        $job = $this->element($document, $namespace, 'job');
        $job->setAttribute('fro', (string) $payload->actualStartOn);
        $employee->appendChild($job);
        $employees->appendChild($employee);
        $root->appendChild($employees);

        return $this->save($document);
    }

    private function employeeAttributes(
        DOMElement $employee,
        PayrollRegistrationXmlPayload $payload,
        bool $prezec,
    ): void {
        $employee->setAttribute('sqnr', (string) $payload->sequenceNumber);
        if (!$prezec) {
            $employee->setAttribute('dep', (string) $payload->csszWorkplaceCode);
        }
        $employee->setAttribute(
            'act',
            (string) $payload->interaction->actionCode,
        );
        if ($prezec) {
            $employee->setAttribute('idform', $payload->formGuid);
        }
        $employee->setAttribute('dat', $payload->preparedOn);
        if ($prezec && $payload->interaction->actionCode === 9) {
            $employee->setAttribute(
                'predat',
                (string) $payload->expectedStartOn,
            );
        }
    }

    private function identityElements(
        DOMDocument $document,
        string $namespace,
        DOMElement $client,
        PayrollRegistrationXmlPayload $payload,
    ): void {
        $identity = $payload->identity->identity;
        $name = $this->element($document, $namespace, 'name');
        $name->setAttribute(
            'sur',
            $this->requiredIdentityString($identity, 'last_name'),
        );
        $name->setAttribute(
            'fir',
            $this->requiredIdentityString($identity, 'first_name'),
        );
        $title = $this->nullableIdentityString($identity, 'title_prefix');
        if ($payload->interaction->documentType === 'REGZEC25'
            && $title !== null
        ) {
            $name->setAttribute('tit', $title);
        }
        $client->appendChild($name);
        $birth = $this->element($document, $namespace, 'birth');
        $birthDate = $this->nullableIdentityString(
            $identity,
            'birth_date',
        );
        if ($payload->interaction->documentType === 'REGZEC25'
            && $birthDate !== null
        ) {
            $birth->setAttribute('dat', $birthDate);
        }
        $birth->setAttribute(
            'nam',
            $this->requiredIdentityString($identity, 'birth_surname'),
        );
        $birth->setAttribute(
            'cit',
            $this->requiredIdentityString($identity, 'birth_place'),
        );
        $birthCountry = $this->nullableIdentityString(
            $identity,
            'birth_country_code',
        );
        if ($payload->interaction->documentType === 'REGZEC25'
            && $birthCountry !== null
        ) {
            $birth->setAttribute('stat', $birthCountry);
        }
        $client->appendChild($birth);
        $stat = $this->element($document, $namespace, 'stat');
        $stat->setAttribute(
            'cnt',
            $this->requiredIdentityString(
                $identity,
                'citizenship_country_code',
            ),
        );
        $client->appendChild($stat);
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function requiredIdentityString(
        array $identity,
        string $key,
    ): string {
        $value = $identity[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new PayrollRegistrationXmlException(
                'registration_identity_invalid',
                "Registrační identita nemá platné pole {$key}.",
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function nullableIdentityString(
        array $identity,
        string $key,
    ): ?string {
        $value = $identity[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new PayrollRegistrationXmlException(
                'registration_identity_invalid',
                "Registrační identita nemá platné pole {$key}.",
            );
        }

        return $value;
    }

    /**
     * `client/@bno` je v obou připnutých schématech popsané jako
     * „Rodné číslo / EČP" (PREZEC26 ID 10057, REGZEC25 ID 10057/10058)
     * a `t:simpleNNType` délky 9–10 pojme obojí. Jediný zdroj pravdy pro
     * tuhle dvojici je `PayrollRegistrationIdentitySnapshotBuilder`, který
     * pro PREZEC26 vyžaduje rodné číslo NEBO EČP; serializér ho jen čte,
     * aby se obě vrstvy nerozešly.
     */
    private function bno(PayrollRegistrationXmlPayload $payload): string
    {
        $value = $this->nullableBno($payload);
        if ($value === null) {
            throw new PayrollRegistrationXmlException(
                'registration_prezec_identifier_required',
                'PREZEC vyžaduje přidělené rodné číslo nebo EČP; bez osobního identifikátoru nelze částečné přihlášení podat.',
            );
        }

        return $value;
    }

    private function nullableBno(
        PayrollRegistrationXmlPayload $payload,
    ): ?string {
        return $payload->identity->identifiers['birth_number']
            ?? $payload->identity->identifiers['ecp'];
    }

    private function document(): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        return $document;
    }

    private function element(
        DOMDocument $document,
        string $namespace,
        string $name,
    ): DOMElement {
        return $document->createElementNS($namespace, $name);
    }

    private function save(DOMDocument $document): string
    {
        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Registrační XML nelze serializovat.');
        }

        return rtrim($xml, "\r\n");
    }
}
