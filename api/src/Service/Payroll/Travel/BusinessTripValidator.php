<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

/**
 * Validace pracovní cesty z požadavku. Vstupy chodí v uživatelských jednotkách
 * (koruny, kilometry, litry na 100 km) a překládají se na interní celočíselné
 * jednotky (haléře, metry, mililitry na 100 km).
 */
final class BusinessTripValidator
{
    private const MAX_ITEMS = 100;

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function validate(array $input): array
    {
        $data = [
            'employee_id' => $this->positiveInt($input['employee_id'] ?? null, 'employee_id'),
            'employment_id' => $this->positiveInt(
                $input['employment_id'] ?? null,
                'employment_id',
            ),
            'country_code' => $this->countryCode($input['country_code'] ?? 'CZ'),
            'departure_at' => $this->moment($input['departure_at'] ?? null, 'departure_at'),
            'arrival_at' => $this->moment($input['arrival_at'] ?? null, 'arrival_at'),
            'origin_place' => $this->text($input['origin_place'] ?? null, 'origin_place', 190),
            'destination_place' => $this->text(
                $input['destination_place'] ?? null,
                'destination_place',
                190,
            ),
            'purpose' => $this->text($input['purpose'] ?? null, 'purpose', 255),
            'transport_mode' => $this->enum(
                $input['transport_mode'] ?? 'public_transport',
                TravelTransportMode::class,
                'transport_mode',
            ),
            'meal_rate_band_1_minor' => $this->optionalMoney(
                $input['meal_rate_band_1'] ?? null,
                'meal_rate_band_1',
            ),
            'meal_rate_band_2_minor' => $this->optionalMoney(
                $input['meal_rate_band_2'] ?? null,
                'meal_rate_band_2',
            ),
            'meal_rate_band_3_minor' => $this->optionalMoney(
                $input['meal_rate_band_3'] ?? null,
                'meal_rate_band_3',
            ),
            'advance_minor' => $this->optionalMoney($input['advance'] ?? null, 'advance') ?? 0,
            'settlement_period_start' => $this->month(
                $input['settlement_period'] ?? null,
                'settlement_period',
            ),
            'items' => $this->items($input['items'] ?? []),
            'free_meals' => $this->freeMeals($input['free_meals'] ?? []),
        ];

        if ($data['arrival_at'] <= $data['departure_at']) {
            throw new \InvalidArgumentException('Návrat musí být později než odjezd.');
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @param list<array<string,mixed>> $items
     * @param array<string,int> $freeMeals
     */
    public static function toDomain(array $data, array $items, array $freeMeals): BusinessTrip
    {
        return new BusinessTrip(
            (string) $data['departure_at'],
            (string) $data['arrival_at'],
            (string) $data['country_code'],
            TravelTransportMode::from((string) $data['transport_mode']),
            self::nullableInt($data['meal_rate_band_1_minor'] ?? null),
            self::nullableInt($data['meal_rate_band_2_minor'] ?? null),
            self::nullableInt($data['meal_rate_band_3_minor'] ?? null),
            (int) ($data['advance_minor'] ?? 0),
            array_map(self::toDomainItem(...), $items),
            $freeMeals,
        );
    }

    /** @param array<string,mixed> $row */
    public static function toDomainItem(array $row): TravelExpenseItem
    {
        return new TravelExpenseItem(
            TravelExpenseItemKind::from((string) $row['item_kind']),
            substr((string) $row['spent_on'], 0, 10),
            (string) $row['description'],
            self::nullableInt($row['amount_minor'] ?? null),
            (bool) ($row['is_documented'] ?? true),
            ($row['document_reference'] ?? null) === null
                ? null
                : (string) $row['document_reference'],
            ($row['vehicle_kind'] ?? null) === null
                ? null
                : TravelVehicleKind::from((string) $row['vehicle_kind']),
            self::nullableInt($row['distance_m'] ?? null),
            self::nullableInt($row['consumption_ml_per_100km'] ?? null),
            ($row['fuel_kind'] ?? null) === null
                ? null
                : TravelFuelKind::from((string) $row['fuel_kind']),
            self::nullableInt($row['documented_fuel_price_minor'] ?? null),
            self::nullableInt($row['id'] ?? null),
        );
    }

    /**
     * @param mixed $value
     * @return list<array<string,mixed>>
     */
    private function items(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Pole items musí být seznam položek.');
        }
        if (count($value) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(
                'Vyúčtování může mít nejvýše ' . self::MAX_ITEMS . ' položek.',
            );
        }
        $items = [];
        $order = 0;
        foreach ($value as $raw) {
            if (!is_array($raw)) {
                throw new \InvalidArgumentException('Položka vyúčtování musí být objekt.');
            }
            $kind = $this->enum(
                $raw['item_kind'] ?? null,
                TravelExpenseItemKind::class,
                'item_kind',
            );
            $item = [
                'item_kind' => $kind,
                'spent_on' => $this->date($raw['spent_on'] ?? null, 'spent_on'),
                'description' => $this->text($raw['description'] ?? null, 'description', 190),
                'is_documented' => (bool) ($raw['is_documented'] ?? true),
                'document_reference' => $this->optionalText(
                    $raw['document_reference'] ?? null,
                    'document_reference',
                    190,
                ),
                'amount_minor' => null,
                'vehicle_kind' => null,
                'distance_m' => null,
                'consumption_ml_per_100km' => null,
                'fuel_kind' => null,
                'documented_fuel_price_minor' => null,
                'sort_order' => $order++,
            ];
            if ($kind === TravelExpenseItemKind::PRIVATE_VEHICLE->value) {
                $item['vehicle_kind'] = $this->enum(
                    $raw['vehicle_kind'] ?? null,
                    TravelVehicleKind::class,
                    'vehicle_kind',
                );
                $item['fuel_kind'] = $this->enum(
                    $raw['fuel_kind'] ?? null,
                    TravelFuelKind::class,
                    'fuel_kind',
                );
                $item['distance_m'] = $this->scaled(
                    $raw['distance_km'] ?? null,
                    'distance_km',
                    1_000,
                    true,
                );
                $item['consumption_ml_per_100km'] = $this->scaled(
                    $raw['consumption_per_100km'] ?? null,
                    'consumption_per_100km',
                    1_000,
                    true,
                );
                $item['documented_fuel_price_minor'] = $this->optionalMoney(
                    $raw['documented_fuel_price'] ?? null,
                    'documented_fuel_price',
                );
            } else {
                $amount = $this->optionalMoney($raw['amount'] ?? null, 'amount');
                if ($amount === null) {
                    throw new \InvalidArgumentException('Doložený výdaj musí mít částku.');
                }
                $item['amount_minor'] = $amount;
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return array<string,int> */
    private function freeMeals(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Pole free_meals musí být seznam.');
        }
        $meals = [];
        foreach ($value as $raw) {
            if (!is_array($raw)) {
                throw new \InvalidArgumentException('Bezplatné jídlo musí být objekt.');
            }
            $date = $this->date($raw['meal_date'] ?? null, 'meal_date');
            $count = $this->positiveInt($raw['meal_count'] ?? null, 'meal_count');
            if ($count > 3) {
                throw new \InvalidArgumentException('Počet bezplatných jídel je nejvýše 3 za den.');
            }
            if (isset($meals[$date])) {
                throw new \InvalidArgumentException('Bezplatná jídla jsou zadaná dvakrát pro týž den.');
            }
            $meals[$date] = $count;
        }
        ksort($meals, SORT_STRING);

        return $meals;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new \InvalidArgumentException("Pole {$field} musí být kladné celé číslo.");
        }
        return (int) $result;
    }

    private function optionalMoney(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->scaled($value, $field, 100, false);
    }

    /**
     * Převod uživatelské desetinné hodnoty na celočíselnou vnitřní jednotku.
     * Přepočet běží nad řetězcem číslic, ne nad plovoucí čárkou, takže se do
     * haléřů ani metrů nemůže propsat zaokrouhlovací chyba binárního formátu.
     */
    private function scaled(mixed $value, string $field, int $scale, bool $positive): int
    {
        $text = $this->numericText($value, $field);
        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]*))?$/D', $text, $matches) !== 1) {
            throw new \InvalidArgumentException("Pole {$field} musí být číslo.");
        }
        $fraction = $matches[3] ?? '';
        if (strlen(rtrim($fraction, '0')) > strlen((string) $scale) - 1) {
            throw new \InvalidArgumentException("Pole {$field} má příliš mnoho desetinných míst.");
        }
        $digits = $matches[2] . str_pad(substr($fraction, 0, strlen((string) $scale) - 1), strlen((string) $scale) - 1, '0');
        $result = (int) $digits * ($matches[1] === '-' ? -1 : 1);
        if ($result < 0 || ($positive && $result <= 0)) {
            throw new \InvalidArgumentException("Pole {$field} musí být kladné.");
        }
        return $result;
    }

    private function numericText(mixed $value, string $field): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));
        }
        if (is_float($value)) {
            $formatted = number_format($value, 6, '.', '');
            return str_contains($formatted, '.')
                ? rtrim(rtrim($formatted, '0'), '.')
                : $formatted;
        }
        throw new \InvalidArgumentException("Pole {$field} musí být číslo.");
    }

    private function countryCode(mixed $value): string
    {
        $code = strtoupper($this->text($value, 'country_code', 2));
        if (preg_match('/^[A-Z]{2}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Pole country_code musí být dvoupísmenný ISO kód.');
        }
        return $code;
    }

    /** @param class-string<\BackedEnum> $enum */
    private function enum(mixed $value, string $enum, string $field): string
    {
        if (!is_string($value) || $enum::tryFrom($value) === null) {
            throw new \InvalidArgumentException("Pole {$field} má nepodporovanou hodnotu.");
        }
        return $value;
    }

    private function moment(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být datum a čas.");
        }
        $normalized = str_replace('T', ' ', trim($value));
        $normalized = substr($normalized, 0, 16);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $normalized);
        if ($parsed === false || $parsed->format('Y-m-d H:i') !== $normalized) {
            throw new \InvalidArgumentException("Pole {$field} musí být ve tvaru YYYY-MM-DD HH:MM.");
        }
        return $normalized . ':00';
    }

    private function date(mixed $value, string $field): string
    {
        $normalized = substr($this->text($value, $field, 10), 0, 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);
        if ($parsed === false || $parsed->format('Y-m-d') !== $normalized) {
            throw new \InvalidArgumentException("Pole {$field} musí být datum YYYY-MM-DD.");
        }
        return $normalized;
    }

    private function month(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být měsíc YYYY-MM.");
        }
        $normalized = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m', $normalized);
        if ($parsed === false || $parsed->format('Y-m') !== $normalized) {
            throw new \InvalidArgumentException("Pole {$field} musí být měsíc YYYY-MM.");
        }
        return $normalized . '-01';
    }

    private function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být text.");
        }
        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized) > $max) {
            throw new \InvalidArgumentException("Pole {$field} není platné.");
        }
        return $normalized;
    }

    private function optionalText(mixed $value, string $field, int $max): ?string
    {
        return $value === null || $value === '' ? null : $this->text($value, $field, $max);
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
