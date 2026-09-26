<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Pracovní vztahy, jejichž FORMULÁŘ se do sestavovaného hlášení nepodává.
 *
 * Dva důvody se stejnou mechanikou:
 *
 *  - `deferral` - účetní vztah výslovně odložila z řádného hlášení
 *    (payroll_jmhz_deferrals), protože u něj chybí data; formulář se doplní
 *    opravným hlášením,
 *  - `correction_scope` - obsahová oprava nese jen vybrané formuláře, takže
 *    nález na jiném vztahu ji nesmí zastavit.
 *
 * V obou případech zůstávají pojistná část a souhrn za VŠECHNY zaměstnance.
 * ČSSZ to u řádného hlášení s částí formulářů výslovně dovoluje a pojistné
 * i sleva zaměstnavatele se musí uplatnit do dne splatnosti - dodatečně už
 * slevu navýšit nelze (§ 7c odst. 2 zák. 589/1992 Sb.). Nesoulad pojistné
 * části se součtem podaných formulářů hlídají propustné kontroly (1, 7, 12,
 * 207, 213, 297 …), takže podání projde a nesoulad je očekávaný.
 */
final readonly class JmhzFormExclusion
{
    public const PURPOSE_DEFERRAL = 'deferral';
    public const PURPOSE_CORRECTION_SCOPE = 'correction_scope';

    /**
     * @param list<int> $employmentIds
     * @param list<int> $deferralIds odložení, ze kterých výjimka vznikla
     */
    private function __construct(
        public string $purpose,
        public array $employmentIds,
        public array $deferralIds,
    ) {}

    /**
     * @param array<array-key,mixed> $employmentIds
     * @param array<array-key,mixed> $deferralIds
     */
    public static function deferral(array $employmentIds, array $deferralIds): self
    {
        return new self(
            self::PURPOSE_DEFERRAL,
            self::ids($employmentIds),
            self::ids($deferralIds),
        );
    }

    /** @param array<array-key,mixed> $employmentIds */
    public static function correctionScope(array $employmentIds): self
    {
        return new self(self::PURPOSE_CORRECTION_SCOPE, self::ids($employmentIds), []);
    }

    public function isEmpty(): bool
    {
        return $this->employmentIds === [];
    }

    public function excludes(int $employmentId): bool
    {
        return in_array($employmentId, $this->employmentIds, true);
    }

    /**
     * @param array<array-key,mixed> $values
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value <= 0) {
                throw new \InvalidArgumentException(
                    'Vynechaný pracovní vztah musí mít kladné celé číslo.',
                );
            }
            $ids[$value] = true;
        }
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
