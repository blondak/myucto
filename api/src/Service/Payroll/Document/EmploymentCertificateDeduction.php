<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class EmploymentCertificateDeduction
{
    public function __construct(
        public string $beneficiary,
        public int $claimAmountMinorUnits,
        public int $withheldAmountMinorUnits,
        public string $priorityDate,
        public string $orderingAuthority,
        public string $decisionReference,
    ) {
        foreach ([
            'oprávněný' => $beneficiary,
            'orgán' => $orderingAuthority,
            'rozhodnutí' => $decisionReference,
        ] as $label => $value) {
            if (trim($value) === ''
                || trim($value) !== $value
                || mb_strlen($value) > 255
                || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
            ) {
                throw new \InvalidArgumentException(
                    "Údaj {$label} pokračující srážky není platný.",
                );
            }
        }
        if ($claimAmountMinorUnits <= 0
            || $withheldAmountMinorUnits < 0
            || $withheldAmountMinorUnits > $claimAmountMinorUnits
        ) {
            throw new \InvalidArgumentException(
                'Částky pokračující srážky nejsou platné.',
            );
        }
        self::date($priorityDate, 'pořadí srážky');
    }

    /**
     * @return array{
     *   beneficiary:string,
     *   claim_amount_minor_units:int,
     *   withheld_amount_minor_units:int,
     *   remaining_amount_minor_units:int,
     *   priority_date:string,
     *   ordering_authority:string,
     *   decision_reference:string
     * }
     */
    public function toArray(): array
    {
        return [
            'beneficiary' => $this->beneficiary,
            'claim_amount_minor_units' => $this->claimAmountMinorUnits,
            'withheld_amount_minor_units' => $this->withheldAmountMinorUnits,
            'remaining_amount_minor_units' =>
                $this->claimAmountMinorUnits - $this->withheldAmountMinorUnits,
            'priority_date' => $this->priorityDate,
            'ordering_authority' => $this->orderingAuthority,
            'decision_reference' => $this->decisionReference,
        ];
    }

    private static function date(string $value, string $label): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                "Datum {$label} není platné.",
            );
        }
    }
}
