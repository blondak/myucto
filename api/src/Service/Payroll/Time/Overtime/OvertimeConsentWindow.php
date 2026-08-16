<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Období, na které zaměstnanec dal souhlas s prací přesčas nad nařízený rozsah
 * (§ 93 odst. 3 zákoníku práce). `validTo === null` znamená dobu neurčitou.
 */
final readonly class OvertimeConsentWindow
{
    public function __construct(
        public string $validFrom,
        public ?string $validTo = null,
        public ?int $id = null,
    ) {
        self::assertDate($validFrom, 'valid_from');
        if ($validTo !== null) {
            self::assertDate($validTo, 'valid_to');
            if ($validTo < $validFrom) {
                throw new \InvalidArgumentException(
                    'Konec platnosti souhlasu nesmí předcházet jeho začátku.',
                );
            }
        }
    }

    public function covers(string $date): bool
    {
        return $date >= $this->validFrom
            && ($this->validTo === null || $date <= $this->validTo);
    }

    private static function assertDate(string $value, string $field): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("{$field} musí být datum YYYY-MM-DD.");
        }
    }
}
