<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;

/**
 * Sazby náhrady mzdy (procento průměrného výdělku) pro měsíční součty hodin
 * z importu docházky.
 *
 * Dovolená (§ 222 odst. 1 ZP) a lékař (§ 199 odst. 1 ZP, NV č. 590/2006 Sb.)
 * mají náhradu ve výši průměrného výdělku ze zákona, jiná sazba se proto
 * odmítne. Překážka na straně zaměstnavatele se z podkladů nedá rozlišit:
 * § 208 dává 100 %, § 207 písm. a) nejméně 80 %, § 207 písm. b) a § 209
 * nejméně 60 %. Sazbu proto určuje volající; výchozích 80 % odpovídá sloupci
 * „doma za 80 %".
 */
final readonly class ImportAbsenceCompensationRates
{
    private const DEFAULTS = [
        'vacation_hours' => 100,
        'doctor_hours' => 100,
        'obstacle_employer_hours' => 80,
    ];

    /** Význam => [nejnižší, nejvyšší] procento, které zákon připouští. */
    private const BOUNDS = [
        'vacation_hours' => [100, 100],
        'doctor_hours' => [100, 100],
        'obstacle_employer_hours' => [60, 100],
    ];

    /** @param array<string,int> $percentByMeaning */
    private function __construct(private array $percentByMeaning) {}

    public static function defaults(): self
    {
        return new self(self::DEFAULTS);
    }

    /** @param array<array-key,mixed> $overrides význam => procento průměru */
    public static function fromMap(array $overrides): self
    {
        $rates = self::DEFAULTS;
        foreach ($overrides as $meaning => $percent) {
            $meaning = (string) $meaning;
            if (!array_key_exists($meaning, self::BOUNDS)) {
                throw new InvalidArgumentException(
                    "Pro hodiny „{$meaning}“ se náhrada mzdy z importu nepočítá.",
                );
            }
            if (!is_int($percent)) {
                throw new InvalidArgumentException('Sazba náhrady mzdy musí být celé procento.');
            }
            [$min, $max] = self::BOUNDS[$meaning];
            if ($percent < $min || $percent > $max) {
                throw new InvalidArgumentException(
                    $min === $max
                        ? "Náhrada za hodiny „{$meaning}“ je ze zákona {$min} % průměrného výdělku."
                        : "Sazba náhrady za hodiny „{$meaning}“ musí být {$min} až {$max} % průměrného výdělku.",
                );
            }
            $rates[$meaning] = $percent;
        }

        return new self($rates);
    }

    /** @return list<string> */
    public function meanings(): array
    {
        return array_keys($this->percentByMeaning);
    }

    public function percentFor(string $meaning): int
    {
        return $this->percentByMeaning[$meaning]
            ?? throw new InvalidArgumentException("Pro hodiny „{$meaning}“ se náhrada mzdy z importu nepočítá.");
    }

    /** @return array<string,int> */
    public function toArray(): array
    {
        return $this->percentByMeaning;
    }
}
