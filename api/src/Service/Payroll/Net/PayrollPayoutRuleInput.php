<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

/**
 * Zadání jednoho výplatního pravidla — tvarová část validace.
 *
 * Drží PŘESNĚ ta pravidla, která později vynutí PayoutAllocationRequest nad
 * zmrazeným snapshotem, jen posunutá na začátek: co projde sem, projde i tam.
 * Kdyby se to rozešlo, chyba by se objevila až při materializaci závazků, tedy
 * nad revizí, se kterou už nejde nic dělat.
 *
 * Referenční kontroly (existence účtu, způsobilost ke zápočtu, právě jeden
 * zbytek) sem NEPATŘÍ — sahají do databáze a řeší je PayrollPayoutRuleValidator.
 */
final readonly class PayrollPayoutRuleInput
{
    public const DESTINATION_KINDS = [
        'bank',
        'cash',
        PayrollPartnerSettlement::KIND,
    ];

    public const ALLOCATION_KINDS = ['fixed', 'percentage', 'remainder'];

    /** Tvar reference bankovního cíle, který jediný umí zpracovat materializer. */
    public const BANK_REFERENCE_PATTERN = '/^account:([1-9][0-9]*)$/D';

    private function __construct(
        public string $destinationKind,
        public ?string $destinationReference,
        public string $allocationKind,
        public ?int $amountMinor,
        public ?int $basisPoints,
        public int $priorityNo,
        public bool $isActive,
    ) {}

    /**
     * @param array<string,mixed> $body
     */
    public static function fromRequest(array $body): self
    {
        $destinationKind = self::enum(
            $body['destination_kind'] ?? null,
            self::DESTINATION_KINDS,
            'destination_kind',
        );
        $allocationKind = self::enum(
            $body['allocation_kind'] ?? null,
            self::ALLOCATION_KINDS,
            'allocation_kind',
        );
        $destinationReference = self::destinationReference(
            $body['destination_reference'] ?? null,
            $destinationKind,
        );
        [$amountMinor, $basisPoints] = self::amounts($body, $allocationKind);

        return new self(
            $destinationKind,
            $destinationReference,
            $allocationKind,
            $amountMinor,
            $basisPoints,
            self::integer($body['priority_no'] ?? 100, 'priority_no', 0, 4294967295),
            self::boolean($body['is_active'] ?? true, 'is_active'),
        );
    }

    public static function remainder(
        string $destinationKind,
        ?string $destinationReference,
        int $priorityNo = 100,
    ): self {
        return self::fromRequest([
            'destination_kind' => $destinationKind,
            'destination_reference' => $destinationReference,
            'allocation_kind' => 'remainder',
            'priority_no' => $priorityNo,
        ]);
    }

    /**
     * Id výplatního účtu zaměstnance, na který pravidlo míří — jen u banky.
     *
     * Tvar `account:<id>` není kosmetika: PayrollNetWageLiabilityMaterializer
     * jinou bankovní referenci odmítne, protože příjemce platby musí být
     * dohledatelný ve zmrazených ověřených účtech revize. Volný text (číslo účtu,
     * IBAN) by znamenal neauditovatelný fallback na živá data.
     */
    public function bankAccountId(): ?int
    {
        if ($this->destinationKind !== 'bank'
            || $this->destinationReference === null
            || preg_match(
                self::BANK_REFERENCE_PATTERN,
                $this->destinationReference,
                $match,
            ) !== 1
        ) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * Reference pravidla se generuje, nezadává: je to stabilní identita vůči
     * zmrazeným alokacím a logickému odkazu závazku (hash reference vstupuje do
     * PayrollNetWageLiabilityMaterializer::logicalReference()). Kdyby ji měnil
     * uživatel, rozpadla by se návaznost opravných revizí na dřívější platby.
     */
    public function generateReference(): string
    {
        return "payout-{$this->destinationKind}-" . bin2hex(random_bytes(6));
    }

    private static function destinationReference(
        mixed $value,
        string $destinationKind,
    ): ?string {
        $text = $value === null || $value === '' ? null : self::text(
            $value,
            'destination_reference',
            190,
        );

        if ($destinationKind === 'cash') {
            if ($text !== null) {
                throw new \InvalidArgumentException(
                    'Hotovostní výplata nesmí mít cílový účet.',
                );
            }

            return null;
        }
        if ($text === null) {
            throw new \InvalidArgumentException(
                $destinationKind === 'bank'
                    ? 'Bankovní výplata vyžaduje výplatní účet zaměstnance.'
                    : 'Zápočet na účet společníka vyžaduje kód účtu z osnovy.',
            );
        }
        if ($destinationKind === PayrollPartnerSettlement::KIND) {
            return PayrollPartnerSettlement::accountCode(
                $text,
                'destination_reference',
            );
        }
        if (preg_match(self::BANK_REFERENCE_PATTERN, $text) !== 1) {
            throw new \InvalidArgumentException(
                'Bankovní cíl musí odkazovat na výplatní účet zaměstnance '
                . 've tvaru account:<id>.',
            );
        }

        return $text;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{?int,?int}
     */
    private static function amounts(array $body, string $allocationKind): array
    {
        $amount = $body['amount_minor'] ?? null;
        $basis = $body['basis_points'] ?? null;
        $amountPresent = $amount !== null && $amount !== '';
        $basisPresent = $basis !== null && $basis !== '';

        return match ($allocationKind) {
            'fixed' => $basisPresent
                ? throw new \InvalidArgumentException(
                    'Pevná částka nesmí mít procentní sazbu.',
                )
                : [
                    self::integer(
                        $amountPresent ? $amount : null,
                        'amount_minor',
                        0,
                        PHP_INT_MAX,
                    ),
                    null,
                ],
            'percentage' => $amountPresent
                ? throw new \InvalidArgumentException(
                    'Procentní alokace nesmí mít pevnou částku.',
                )
                : [
                    null,
                    self::integer(
                        $basisPresent ? $basis : null,
                        'basis_points',
                        0,
                        10000,
                    ),
                ],
            default => $amountPresent || $basisPresent
                ? throw new \InvalidArgumentException(
                    'Zbytkové pravidlo nesmí mít částku ani procentní sazbu.',
                )
                : [null, null],
        };
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Pole {$field} má nepodporovanou hodnotu.",
            );
        }

        return $value;
    }

    private static function integer(
        mixed $value,
        string $field,
        int $minimum,
        int $maximum,
    ): int {
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);
        if (!is_int($result)) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být celé číslo {$minimum}–{$maximum}.",
            );
        }

        return $result;
    }

    private static function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být boolean.");
        }

        return $value;
    }

    private static function text(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být řetězec.");
        }
        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > $maxLength) {
            throw new \InvalidArgumentException(
                "Pole {$field} je prázdné nebo delší než {$maxLength} znaků.",
            );
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $normalized) === 1) {
            throw new \InvalidArgumentException(
                "Pole {$field} obsahuje řídicí znak.",
            );
        }

        return $normalized;
    }
}
