<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Hromadné oznámení zaměstnavatele (HOZ) pro jednu pojišťovnu.
 *
 * Věty se do jednoho oznámení sdružují jen tehdy, když patří téže pojišťovně:
 * `kodZdravotniPojistovny` je na úrovni dokumentu, ne věty.
 */
final readonly class HealthBulkNotificationPayload
{
    /** @param list<HealthNotificationChange> $changes */
    public function __construct(
        public string $insurerCode,
        public HealthEmployerIdentification $employer,
        public array $changes,
        public ?string $internalReference = null,
    ) {}

    public function assertValid(
        HealthInsuranceSchemaCatalog $schemas,
        HealthNotificationCodeCatalog $codes,
    ): void {
        $schemas->assertInsurerCode($this->insurerCode);
        $this->employer->assertValid();
        if ($this->changes === []) {
            throw new HealthNotificationException(
                'zp_bulk_notification_empty',
                'Hromadné oznámení musí obsahovat alespoň jednu větu.',
            );
        }
        foreach ($this->changes as $change) {
            $change->assertValid($codes);
        }
        if ($this->internalReference !== null
            && preg_match('/^[0-9A-Za-z._:-]{1,60}$/', $this->internalReference) !== 1
        ) {
            throw new HealthNotificationException(
                'zp_internal_reference_invalid',
                'Interní identifikace podání smí obsahovat jen bezpečné znaky.',
            );
        }
    }
}
