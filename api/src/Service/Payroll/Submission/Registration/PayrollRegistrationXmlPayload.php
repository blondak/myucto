<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final readonly class PayrollRegistrationXmlPayload
{
    public function __construct(
        public PayrollRegistrationIdentitySnapshot $identity,
        public PayrollRegistrationInteraction $interaction,
        public int $sequenceNumber,
        public string $formGuid,
        public string $preparedOn,
        public ?string $expectedStartOn,
        public ?string $actualStartOn,
        public string $employerVariableSymbol,
        public ?string $employerName = null,
        public ?string $csszWorkplaceCode = null,
        /** @var array<string,mixed>|null */
        public ?array $eventSnapshot = null,
        /*
         * Identifikace odesílajícího software (`VENDOR`). PREZEC26 i REGZEC25
         * ji mají stejně jako hlášení a příloha k žádosti o dávku; je to
         * jediné místo, kde ČSSZ pozná, ze kterého programu podání vzniklo.
         * Zůstává nepovinná, aby starší volání serializéru dál procházela.
         */
        public ?string $productName = null,
        public ?string $productVersion = null,
        /*
         * GUID formuláře původní přijaté PREZEC P1, na který se odkazuje
         * ukončení předregistrace P2 (atribut 10012). Jen pro P2; u ostatních
         * podání se nepoužívá a formulář nese vlastní `formGuid`.
         */
        public ?string $referencedFormGuid = null,
    ) {}
}
