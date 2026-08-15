<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

final readonly class PayoutAllocationRequest
{
    private function __construct(
        public string $allocationReference,
        public string $destinationKind,
        public ?string $destinationReference,
        public string $allocationKind,
        public ?int $amountMinorUnits,
        public ?int $basisPoints,
        public int $priority,
    ) {
        if ($allocationReference === '' || $priority < 0) {
            throw new \InvalidArgumentException('Alokace vyžaduje identifikátor, cíl a nezáporné pořadí.');
        }
        if (!in_array(
                $destinationKind,
                ['bank', 'cash', PayrollPartnerSettlement::KIND],
                true,
            )
            || ($destinationKind === 'bank' && ($destinationReference ?? '') === '')
            || ($destinationKind === 'cash' && $destinationReference !== null)
            || ($destinationKind === PayrollPartnerSettlement::KIND
                && ($destinationReference ?? '') === '')
        ) {
            throw new \InvalidArgumentException(
                'Bankovní výplata i zápočet na účet společníka vyžadují referenci cíle, hotovost ji nesmí mít.'
            );
        }
        // Zápočet na účet společníka není výplata, ale přeúčtování závazku, takže
        // referencí cíle není platební účet, ale KÓD účtu z osnovy (např. 365.100).
        // Kontrolujeme ho hned tady, aby se neplatný kód nedostal do zmrazené
        // revize a nespadl až u účetního zápisu.
        if ($destinationKind === PayrollPartnerSettlement::KIND) {
            PayrollPartnerSettlement::accountCode(
                $destinationReference,
                'Cíl zápočtu na účet společníka',
            );
        }
    }

    public static function fixed(
        string $reference,
        string $destinationKind,
        ?string $destinationReference,
        int $amountMinorUnits,
        int $priority,
    ): self {
        if ($amountMinorUnits < 0) {
            throw new \InvalidArgumentException('Pevná alokace nesmí být záporná.');
        }
        return new self(
            $reference,
            $destinationKind,
            $destinationReference,
            'fixed',
            $amountMinorUnits,
            null,
            $priority,
        );
    }

    public static function percentage(
        string $reference,
        string $destinationKind,
        ?string $destinationReference,
        int $basisPoints,
        int $priority,
    ): self {
        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw new \InvalidArgumentException('Procentní alokace musí být mezi 0 a 10000 bp.');
        }
        return new self(
            $reference,
            $destinationKind,
            $destinationReference,
            'percentage',
            null,
            $basisPoints,
            $priority,
        );
    }

    public static function remainder(
        string $reference,
        string $destinationKind,
        ?string $destinationReference,
        int $priority,
    ): self {
        return new self(
            $reference,
            $destinationKind,
            $destinationReference,
            'remainder',
            null,
            null,
            $priority,
        );
    }
}
