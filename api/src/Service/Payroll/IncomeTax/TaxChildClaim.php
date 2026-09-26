<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use InvalidArgumentException;

/**
 * Nárok na dítě ve společně hospodařící domácnosti.
 *
 * `order` je pořadí dítěte V DOMÁCNOSTI (§ 35c odst. 1). `creditClaimed = false`
 * je dítě s pořadím „N" (JMHZ 10440): drží své pořadí, ale zvýhodnění na ně
 * uplatňuje jiná osoba (§ 35c odst. 9), takže u tohoto poplatníka nevzniká
 * žádná částka. Díky tomu dostane druhé dítě sazbu druhého dítěte, i když
 * první uplatňuje partner.
 */
final readonly class TaxChildClaim
{
    public function __construct(
        public string $childReference,
        public int $order,
        public bool $ztpP,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public TaxEvidenceStatus $evidenceStatus,
        public bool $sharedHouseholdConfirmed,
        public bool $otherClaimantExcluded,
        public ?string $evidenceReference = null,
        public bool $creditClaimed = true,
    ) {
        if (trim($childReference) === '') {
            throw new InvalidArgumentException('Tax child reference must not be empty.');
        }
        if ($order < 1) {
            throw new InvalidArgumentException('Tax child order must be positive.');
        }
        EvidenceInterval::assertValid(
            $effectiveFrom,
            $effectiveTo,
            $evidenceStatus,
            $evidenceReference,
        );
    }

    /**
     * Měsíc patří do nároku, když interval obsahuje jeho první den. Výjimky
     * § 35c odst. 10 (měsíc narození, osvojení, převzetí do péče, zahájení
     * studia) a celý měsíc konce vyživování už nese samotný interval — zapisuje
     * se po celých měsících a proti období vyživování ho staví
     * {@see ChildCreditClaimWindow}. Roční zúčtování měří totéž.
     */
    public function isEffective(string $calculationDate): bool
    {
        return EvidenceInterval::includesMonthStart(
            $this->effectiveFrom,
            $this->effectiveTo,
            $calculationDate,
        );
    }
}
