<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;

/**
 * Brána ostrých mzdových cest: platební příkazy, transporty na ČSSZ,
 * zdravotní pojišťovny a finanční správu a odchozí doručení zaměstnancům.
 *
 * Pustí je jen firmě s dokončeným základním nastavením mezd (stav `active`).
 * Testovací prostředí podání se nekontroluje.
 */
final class PayrollProductionGate
{
    public function __construct(
        private readonly PayrollModuleStateRepository $states,
    ) {}

    public function assertActive(int $supplierId): void
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma produkčního mzdového provozu musí být kladné číslo.',
            );
        }
        if ($this->states->get($supplierId)['status'] !== 'active') {
            throw new PayrollProductionGateException();
        }
    }

    public function assertEnvironmentActive(
        int $supplierId,
        string $environment,
    ): void {
        if (!in_array($environment, ['test', 'production'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí musí být test nebo production.',
            );
        }
        if ($environment === 'production') {
            $this->assertActive($supplierId);
        }
    }
}
