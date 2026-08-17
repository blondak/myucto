<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Jedna věta `zmenaZamestance` hromadného oznámení.
 *
 * `cisloPojistence` je devět nebo deset číslic; cizinec bez přiděleného čísla
 * má tvar `[MZ]DDMMYYYY`. Obojí je v datové větě rovnocenné, takže se validuje
 * jako alternativa, ne jako výjimka z číselného tvaru.
 */
final readonly class HealthNotificationChange
{
    public function __construct(
        public string $changeCode,
        public string $changedOn,
        public string $insuranceNumber,
        public string $firstName,
        public string $lastName,
        public ?HealthNotificationAddress $address = null,
    ) {}

    public function assertValid(
        HealthNotificationCodeCatalog $codes,
    ): void {
        $codes->assertReportableByEmployer($this->changeCode, $this->changedOn);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->changedOn) !== 1) {
            throw new HealthNotificationException(
                'zp_change_date_invalid',
                'Datum změny musí mít tvar RRRR-MM-DD.',
            );
        }
        if (preg_match('/^(\d{9,10}|[MZ]\d{8})$/', $this->insuranceNumber) !== 1) {
            throw new HealthNotificationException(
                'zp_insurance_number_invalid',
                'Číslo pojištěnce musí mít devět nebo deset číslic, '
                . 'u cizince bez přiděleného čísla tvar MDDMMRRRR nebo ZDDMMRRRR.',
            );
        }
        if (trim($this->firstName) === '' || trim($this->lastName) === '') {
            throw new HealthNotificationException(
                'zp_change_person_name_missing',
                'Věta hromadného oznámení musí mít jméno i příjmení.',
            );
        }
        $this->address?->assertValid();
    }
}
