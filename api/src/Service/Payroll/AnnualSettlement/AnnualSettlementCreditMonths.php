<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;

/**
 * Kolik měsíců roku byly splněny podmínky pro jednu slevu podle § 35ba.
 *
 * Počet měsíců se NEODVOZUJE z toho, kolik slevy se měsíčně skutečně uplatnilo:
 * měsíční sleva je podle § 35d odst. 3 omezená výší zálohy, takže z uplatněné
 * částky nárok zpětně vyčíst nejde. Bere se z evidence nároků, kde je
 * `effective_from`/`effective_to` — a testuje se, jak říká § 35ba odst. 3,
 * k POČÁTKU kalendářního měsíce.
 */
final readonly class AnnualSettlementCreditMonths
{
    public function __construct(
        public TaxCreditKind $kind,
        public int $months,
    ) {
        if ($months < 1 || $months > AnnualTaxRates::MONTHS_IN_YEAR) {
            throw new \InvalidArgumentException(
                'Počet měsíců nároku na slevu musí být 1 až 12.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'months' => $this->months];
    }
}
