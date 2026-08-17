<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

final readonly class HealthNotificationAddress
{
    public function __construct(
        public string $street,
        public string $houseNumber,
        public string $postalCode,
        public string $city,
    ) {}

    public function assertValid(): void
    {
        if (preg_match('/^[0-9]{5}$/', $this->postalCode) !== 1) {
            throw new HealthNotificationException(
                'zp_postal_code_invalid',
                'PSČ musí mít přesně pět číslic bez mezery.',
            );
        }
        if (trim($this->street) === ''
            || trim($this->houseNumber) === ''
            || trim($this->city) === ''
        ) {
            throw new HealthNotificationException(
                'zp_change_address_incomplete',
                'Adresa ve větě hromadného oznámení není úplná.',
            );
        }
    }
}
