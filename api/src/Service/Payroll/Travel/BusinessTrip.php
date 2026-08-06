<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

use InvalidArgumentException;

/**
 * Vstup výpočtu cestovních náhrad. Časy jsou lokální (Europe/Prague) a bez
 * časové zóny — pracovní cesta se posuzuje podle kalendářních dnů zaměstnavatele.
 */
final readonly class BusinessTrip
{
    public \DateTimeImmutable $departureAt;
    public \DateTimeImmutable $arrivalAt;

    /**
     * @param list<TravelExpenseItem> $items
     * @param array<string,int> $freeMeals počet bezplatných jídel podle data YYYY-MM-DD
     */
    public function __construct(
        string $departureAt,
        string $arrivalAt,
        public string $countryCode = 'CZ',
        public TravelTransportMode $transportMode = TravelTransportMode::PUBLIC_TRANSPORT,
        public ?int $mealRateBand1Minor = null,
        public ?int $mealRateBand2Minor = null,
        public ?int $mealRateBand3Minor = null,
        public int $advanceMinor = 0,
        public array $items = [],
        public array $freeMeals = [],
    ) {
        $this->departureAt = self::moment($departureAt, 'departure_at');
        $this->arrivalAt = self::moment($arrivalAt, 'arrival_at');
        if ($this->arrivalAt <= $this->departureAt) {
            throw new InvalidArgumentException('Návrat musí být později než odjezd.');
        }
        if (preg_match('/^[A-Z]{2}$/D', $countryCode) !== 1) {
            throw new InvalidArgumentException('Kód země musí být dvoupísmenný ISO kód.');
        }
        if ($advanceMinor < 0) {
            throw new InvalidArgumentException('Záloha na cestu nemůže být záporná.');
        }
        foreach ([$mealRateBand1Minor, $mealRateBand2Minor, $mealRateBand3Minor] as $rate) {
            if ($rate !== null && $rate <= 0) {
                throw new InvalidArgumentException('Sazba stravného musí být kladná.');
            }
        }
        foreach ($freeMeals as $date => $count) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== (string) $date) {
                throw new InvalidArgumentException('Datum bezplatného jídla musí být YYYY-MM-DD.');
            }
            if ($count < 1 || $count > 3) {
                throw new InvalidArgumentException('Počet bezplatných jídel musí být 1 až 3.');
            }
        }
        foreach ($items as $item) {
            if (!$item instanceof TravelExpenseItem) {
                throw new InvalidArgumentException('Položka vyúčtování má neplatný typ.');
            }
        }
    }

    public function isDomestic(): bool
    {
        return $this->countryCode === 'CZ';
    }

    /** Sazba stravného zvolená zaměstnavatelem pro dané pásmo, nebo null pro zákonné minimum. */
    public function mealRateMinor(int $band): ?int
    {
        return match ($band) {
            1 => $this->mealRateBand1Minor,
            2 => $this->mealRateBand2Minor,
            3 => $this->mealRateBand3Minor,
            default => throw new InvalidArgumentException('Pásmo stravného musí být 1 až 3.'),
        };
    }

    public function freeMealCount(string $date): int
    {
        return $this->freeMeals[$date] ?? 0;
    }

    private static function moment(string $value, string $field): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            substr($value, 0, 16),
            new \DateTimeZone('UTC'),
        );
        if ($parsed === false || $parsed->format('Y-m-d H:i') !== substr($value, 0, 16)) {
            throw new InvalidArgumentException("Pole {$field} musí být ve tvaru YYYY-MM-DD HH:MM.");
        }
        return $parsed;
    }
}
