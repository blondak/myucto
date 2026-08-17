<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Identifikace zaměstnavatele, společná oběma dokumentům jednotné věty.
 *
 * `identifikacniCisloPlatce` je PŘESNĚ deset číslic: osmimístné IČO a za ním
 * dvoumístné číslo účtárny (výchozí `00`). Není to IČO doplněné nulami zleva —
 * doplňuje se zprava a záměna obojího dá číslo jiného plátce.
 */
final readonly class HealthEmployerIdentification
{
    public function __construct(
        public string $payerNumber,
        public string $name,
        public string $street,
        public string $houseNumber,
        public string $postalCode,
        public string $city,
        public string $phone,
    ) {}

    public static function fromBusinessId(
        string $businessId,
        string $accountingUnit,
        string $name,
        string $street,
        string $houseNumber,
        string $postalCode,
        string $city,
        string $phone,
    ): self {
        if (preg_match('/^[0-9]{8}$/', $businessId) !== 1) {
            throw new HealthNotificationException(
                'zp_payer_business_id_invalid',
                'IČO plátce musí mít přesně osm číslic.',
            );
        }
        if (preg_match('/^[0-9]{2}$/', $accountingUnit) !== 1) {
            throw new HealthNotificationException(
                'zp_payer_accounting_unit_invalid',
                'Číslo účtárny plátce musí mít přesně dvě číslice.',
            );
        }

        return new self(
            payerNumber: $businessId . $accountingUnit,
            name: $name,
            street: $street,
            houseNumber: $houseNumber,
            postalCode: $postalCode,
            city: $city,
            phone: $phone,
        );
    }

    public function assertValid(): void
    {
        if (preg_match('/^[0-9]{10}$/', $this->payerNumber) !== 1) {
            throw new HealthNotificationException(
                'zp_payer_number_invalid',
                'Identifikační číslo plátce musí mít přesně deset číslic '
                . '(osmimístné IČO a dvoumístné číslo účtárny).',
            );
        }
        if (preg_match('/^[0-9]{5}$/', $this->postalCode) !== 1) {
            throw new HealthNotificationException(
                'zp_postal_code_invalid',
                'PSČ musí mít přesně pět číslic bez mezery.',
            );
        }
        foreach ([
            'zp_employer_name_missing' => $this->name,
            'zp_employer_street_missing' => $this->street,
            'zp_employer_house_number_missing' => $this->houseNumber,
            'zp_employer_city_missing' => $this->city,
            'zp_employer_phone_missing' => $this->phone,
        ] as $code => $value) {
            if (trim($value) === '') {
                throw new HealthNotificationException(
                    $code,
                    'Identifikace zaměstnavatele není pro podání úplná.',
                );
            }
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'payer_number' => $this->payerNumber,
            'name' => $this->name,
            'street' => $this->street,
            'house_number' => $this->houseNumber,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'phone' => $this->phone,
        ];
    }
}
