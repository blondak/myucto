<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Pojišťovna, jejíž přehled o platbě pojistného nešlo sestavit.
 *
 * Přehledy jsou samostatná podání po pojišťovnách, takže vada jedné (součty
 * osob proti závazku, osoba bez závazku) nesmí zastavit výpis ostatních.
 * Vada celého výsledku (otisky, kořenové součty, schéma) izolovat nejde
 * a dál se hlásí výjimkou.
 */
final readonly class HealthPaymentOverviewFailure
{
    public function __construct(
        public string $insurerCode,
        public string $code,
        public string $message,
        public \Throwable $cause,
    ) {}

    /** @return array{insurer_code:string,code:string,message:string} */
    public function toArray(): array
    {
        return [
            'insurer_code' => $this->insurerCode,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
