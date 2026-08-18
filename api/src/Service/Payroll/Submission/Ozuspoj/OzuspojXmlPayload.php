<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

/**
 * Obsah jednoho e-podání OZUSPOJ.
 *
 * Datová věta nese právě JEDEN formulář za JEDNOHO zaměstnance — podávací
 * protokol ČSSZ v1.47 to u téhle služby uvádí výslovně („Ne (jen jeden
 * formulář)") a popis datové věty proto atribut `partialAccept` ignoruje.
 * Payload tedy vědomě není seznam; dávkové podání za víc lidí by ČSSZ odmítla.
 *
 * `duvod` (písmeno § 7a odst. 1) tu SCHVÁLNĚ NENÍ. § 23e odst. 1 do oznámení
 * pouští jen údaje podle § 23f odst. 3 písm. a) a b), tedy identifikaci
 * zaměstnance a den, od kterého záměr platí, a XSD OZUSPOJ23 žádný prvek pro
 * důvod nemá. Důvod se vykazuje až v měsíčním hlášení (položka 10374) a
 * v evidenci zaměstnavatele podle § 23d odst. 1 písm. b).
 */
final readonly class OzuspojXmlPayload
{
    public function __construct(
        public OzuspojSubmissionKind $kind,
        public int $osszCode,
        public ?string $intentFrom,
        public ?string $intentTo,
        public string $employerVariableSymbol,
        public ?string $employerIdentificationNumber,
        public string $employerName,
        public string $employeeFirstName,
        public string $employeeLastName,
        public string $employeeBirthDate,
        public ?string $employeeBirthNumber,
        public string $productName,
        public string $productVersion,
        public ?string $notificationEmail = null,
        public ?string $contactFirstName = null,
        public ?string $contactLastName = null,
        public ?string $contactPhone = null,
        public ?string $contactEmail = null,
    ) {}
}
