<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Adresa pojištěnce ve větě `zmenaZamestance`.
 *
 * Schéma zná uvnitř prvku `adresa` jen `ulice`, `obec` a `psc`; číslo popisné
 * vlastní prvek NEMÁ. Doména ho drží zvlášť, protože ho tak eviduje aplikace,
 * a do věty ho spojuje s ulicí přes {@see streetLine()} — zahodit ho by
 * znamenalo poslat pojišťovně adresu bez čísla.
 */
final readonly class HealthNotificationAddress
{
    public function __construct(
        public string $street,
        public string $houseNumber,
        public string $postalCode,
        public string $city,
        /** `street` už nese i číslo popisné (karta osoby ho drží v jednom poli). */
        public bool $streetContainsNumber = false,
    ) {}

    /**
     * Adresa z karty osoby, kde je ulice s číslem v jediném poli `street_line`.
     * Dělit ji na ulici a číslo by znamenalo odhadovat, co uživatel zapsal, a
     * schéma stejně obojí chce v jednom prvku `ulice`.
     */
    public static function fromStreetLine(
        string $streetLine,
        string $postalCode,
        string $city,
    ): self {
        return new self(
            street: $streetLine,
            houseNumber: '',
            postalCode: str_replace([' ', "\u{00A0}"], '', $postalCode),
            city: $city,
            streetContainsNumber: true,
        );
    }

    /** Ulice a číslo popisné v jednom prvku `ulice` (`string60Typ`). */
    public function streetLine(): string
    {
        return trim(trim($this->street) . ' ' . trim($this->houseNumber));
    }

    public function assertValid(): void
    {
        if (preg_match('/^[0-9]{5}$/', $this->postalCode) !== 1) {
            throw new HealthNotificationException(
                'zp_postal_code_invalid',
                'PSČ musí mít přesně pět číslic bez mezery.',
            );
        }
        if (trim($this->street) === ''
            || (trim($this->houseNumber) === '' && !$this->streetContainsNumber)
            || trim($this->city) === ''
        ) {
            throw new HealthNotificationException(
                'zp_change_address_incomplete',
                'Adresa ve větě hromadného oznámení není úplná.',
            );
        }
        if (mb_strlen($this->streetLine()) > 60
            || mb_strlen($this->city) > 60
        ) {
            throw new HealthNotificationException(
                'zp_change_address_too_long',
                'Adresa ve větě hromadného oznámení přesahuje šedesát znaků, '
                . 'které datová věta dovoluje.',
            );
        }
    }
}
