<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Pokračující srážka v potvrzení o zaměstnání (§ 313 odst. 1 písm. e) ZP).
 *
 * `sourceKind` rozlišuje, odkud srážka pochází: exekuce (`enforcement_claim`),
 * dohoda o srážkách ze mzdy (`deduction_agreement`) nebo insolvence
 * (`insolvency`). Výše pohledávky může chybět (`null`) — u dohody bez
 * celkového limitu a u oddlužení se srážka neurčuje částkou dluhu. Den pořadí
 * chybí u doručené, dosud neověřené exekuce a u insolvence.
 */
final readonly class EmploymentCertificateDeduction
{
    public const SOURCE_KINDS = ['enforcement_claim', 'deduction_agreement', 'insolvency'];

    public function __construct(
        public string $beneficiary,
        public ?int $claimAmountMinorUnits,
        public int $withheldAmountMinorUnits,
        public ?string $priorityDate,
        public string $orderingAuthority,
        public string $decisionReference,
        public string $sourceKind = 'enforcement_claim',
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
        if (!in_array($sourceKind, self::SOURCE_KINDS, true)) {
            throw new \InvalidArgumentException('Druh pokračující srážky není platný.');
        }
        if ($withheldAmountMinorUnits < 0
            || ($claimAmountMinorUnits !== null
                && ($claimAmountMinorUnits <= 0 || $withheldAmountMinorUnits > $claimAmountMinorUnits))
        ) {
            throw new \InvalidArgumentException(
                'Částky pokračující srážky nejsou platné.',
            );
        }
        if ($priorityDate !== null) {
            self::date($priorityDate, 'pořadí srážky');
        }
    }

    /**
     * @return array{
     *   beneficiary:string,
     *   claim_amount_minor_units:?int,
     *   withheld_amount_minor_units:int,
     *   remaining_amount_minor_units:?int,
     *   priority_date:?string,
     *   ordering_authority:string,
     *   decision_reference:string,
     *   source_kind?:string
     * }
     */
    public function toArray(): array
    {
        return [
            'beneficiary' => $this->beneficiary,
            'claim_amount_minor_units' => $this->claimAmountMinorUnits,
            'withheld_amount_minor_units' => $this->withheldAmountMinorUnits,
            'remaining_amount_minor_units' => $this->claimAmountMinorUnits === null
                ? null
                : $this->claimAmountMinorUnits - $this->withheldAmountMinorUnits,
            'priority_date' => $this->priorityDate,
            'ordering_authority' => $this->orderingAuthority,
            'decision_reference' => $this->decisionReference,
            // Exekuce zůstává bez klíče, aby snímky pořízené dřív zůstaly
            // bajtově stejné.
            ...($this->sourceKind === 'enforcement_claim' ? [] : ['source_kind' => $this->sourceKind]),
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
