<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PayrollDocumentEmployerSnapshot
{
    public function __construct(
        public string $name,
        public string $identificationNumber,
        public string $taxIdentificationNumber,
        public string $streetLine,
        public string $city,
        public string $postalCode,
        public string $countryCode,
        public string $countryName,
        public string $issuerName,
        public string $issuerEmail,
        public string $issuerPhone,
    ) {
        foreach ([
            'name' => [$name, 190],
            'identification_number' => [$identificationNumber, 20],
            'tax_identification_number' => [$taxIdentificationNumber, 20],
            'street_line' => [$streetLine, 190],
            'city' => [$city, 120],
            'postal_code' => [$postalCode, 10],
            'country_name' => [$countryName, 120],
            'issuer_name' => [$issuerName, 190],
            'issuer_email' => [$issuerEmail, 190],
            'issuer_phone' => [$issuerPhone, 40],
        ] as $field => [$value, $maxLength]) {
            if ($value === ''
                || trim($value) !== $value
                || mb_strlen($value) > $maxLength
                || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
            ) {
                throw new \InvalidArgumentException(
                    "Pole zaměstnavatele {$field} není platné.",
                );
            }
        }
        if (preg_match('/^[A-Z]{2}$/D', $countryCode) !== 1) {
            throw new \InvalidArgumentException(
                'Kód země zaměstnavatele není platný.',
            );
        }
        foreach ([
            'IČ' => $identificationNumber,
            'DIČ' => $taxIdentificationNumber,
        ] as $label => $identifier) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/_-]{0,19}$/D', $identifier) !== 1) {
                throw new \InvalidArgumentException(
                    "{$label} zaměstnavatele není platné.",
                );
            }
        }
        if (filter_var($issuerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(
                'E-mail vystavitele mzdového dokumentu není platný.',
            );
        }
        if (preg_match('/^\+?[0-9][0-9 ()\/.-]{4,39}$/D', $issuerPhone) !== 1) {
            throw new \InvalidArgumentException(
                'Telefon vystavitele mzdového dokumentu není platný.',
            );
        }
    }

    /**
     * @return array{
     *   name:string,
     *   identification_number:string,
     *   tax_identification_number:string,
     *   address:array{
     *     street_line:string,
     *     city:string,
     *     postal_code:string,
     *     country_code:string,
     *     country_name:string
     *   },
     *   issuer:array{name:string,email:string,phone:string}
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'identification_number' => $this->identificationNumber,
            'tax_identification_number' => $this->taxIdentificationNumber,
            'address' => [
                'street_line' => $this->streetLine,
                'city' => $this->city,
                'postal_code' => $this->postalCode,
                'country_code' => $this->countryCode,
                'country_name' => $this->countryName,
            ],
            'issuer' => [
                'name' => $this->issuerName,
                'email' => $this->issuerEmail,
                'phone' => $this->issuerPhone,
            ],
        ];
    }
}
