<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Zjistí, jestli otevřená revize počítá se zastaralými podklady.
 *
 * Aktuální stav čte {@see PayrollRunSnapshotBuilder::currentSources()} —
 * tytéž dotazy a táž normalizace jako při stavbě snímku, takže „změněno"
 * znamená přesně „nový snímek by vypadal jinak", ne vlastní výklad.
 */
final class PayrollRunSourceDriftDetector
{
    public function __construct(
        private readonly PayrollRunSnapshotBuilder $builder,
    ) {}

    /** @param array<string,mixed> $inputSnapshot */
    public function detect(int $supplierId, array $inputSnapshot): PayrollRunSourceDrift
    {
        $periodStart = $inputSnapshot['period_start'] ?? null;
        $paymentDate = $inputSnapshot['payment_date'] ?? null;
        $officeId = $inputSnapshot['office_id'] ?? null;
        if (!is_string($periodStart) || !is_string($paymentDate)
            || ($officeId !== null && !is_int($officeId))
        ) {
            // Snímek bez období nebo data výplaty (vznikl ve starší verzi) nejde
            // porovnat s aktuálním stavem. Detekce je jen pojistka navíc — neumí-li
            // snímek porovnat, nesmí zablokovat schválení ani seznam běhů.
            return new PayrollRunSourceDrift();
        }

        $employmentIds = [];
        $employeeIds = [];
        $people = is_array($inputSnapshot['people'] ?? null) ? $inputSnapshot['people'] : [];
        foreach ($people as $person) {
            if (!is_array($person) || !is_array($person['employee'] ?? null)) {
                continue;
            }
            $employeeIds[] = (int) ($person['employee']['id'] ?? 0);
            $employments = is_array($person['employments'] ?? null) ? $person['employments'] : [];
            foreach ($employments as $employment) {
                if (is_array($employment) && is_array($employment['employment'] ?? null)) {
                    $employmentIds[] = (int) ($employment['employment']['id'] ?? 0);
                }
            }
        }

        return PayrollRunSourceDrift::between(
            $inputSnapshot,
            $this->builder->currentSources(
                $supplierId,
                $periodStart,
                $paymentDate,
                $officeId,
                $employmentIds,
                $employeeIds,
            ),
        );
    }
}
