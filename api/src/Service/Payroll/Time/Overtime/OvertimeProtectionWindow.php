<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Období, po které se na zaměstnance vztahuje zákaz práce přesčas podle
 * § 240 odst. 3 zákoníku práce. `validTo === null` znamená dosud neukončenou
 * skutečnost.
 *
 * Mladistvost tady není — ta se nezapisuje, plyne z data narození
 * (§ 350 odst. 2) a vyhodnocuje se v {@see OvertimeLimitEvaluator}.
 */
final readonly class OvertimeProtectionWindow
{
    /** § 240 odst. 3 věta první — absolutní zákaz. */
    public const PREGNANCY = 'pregnancy';

    /** § 240 odst. 3 věta druhá — zákaz jen pro NAŘÍZENÝ přesčas. */
    public const CHILD_UNDER_ONE = 'child_under_one';

    public const KINDS = [self::PREGNANCY, self::CHILD_UNDER_ONE];

    public function __construct(
        public string $kind,
        public string $validFrom,
        public ?string $validTo = null,
        public ?int $id = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Neznámý důvod ochrany před prací přesčas.');
        }
        self::assertDate($validFrom, 'valid_from');
        if ($validTo !== null) {
            self::assertDate($validTo, 'valid_to');
            if ($validTo < $validFrom) {
                throw new \InvalidArgumentException(
                    'Konec ochrany nesmí předcházet jejímu začátku.',
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
