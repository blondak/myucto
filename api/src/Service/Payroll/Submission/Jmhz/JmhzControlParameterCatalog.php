<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Parametrické konstanty katalogu kontrol JMHZ — sazby pojistného, prahy
 * a meze, které kontroly používají ve svých vzorcích.
 *
 * Hodnoty jsou účinné k datu a ČSSZ je mění: sazba pojistného zaměstnavatele
 * u zdravotnických záchranářů byla v roce 2025 0,288 a od roku 2026 je 0,298.
 * Sazba se proto nikdy nesmí objevit v kódu jako literál — vždy se resolvuje
 * k prvnímu dni vykazovaného období.
 *
 * Aritmetika je celočíselná. `canonical_value` je desetinný zápis, který se
 * rozkládá na čitatele a mocninu deseti, aby se výsledek dal spočítat bez
 * plovoucí čárky — 0,1 + 0,2 se v pojistném nesmí projevit ani o haléř.
 */
final class JmhzControlParameterCatalog
{
    /** @var array<string, list<array{effective_from:string,canonical_value:string}>> */
    private array $values = [];

    /** @var array<int, list<string>> */
    private array $keysByControl = [];

    /** @param list<array<string, mixed>> $parameters */
    public function __construct(array $parameters)
    {
        foreach ($parameters as $parameter) {
            $key = $parameter['parameter_key'] ?? null;
            if (!is_string($key) || $key === '') {
                throw new \UnexpectedValueException('Parametr katalogu kontrol JMHZ nemá klíč.');
            }
            $rows = [];
            foreach ($parameter['values'] ?? [] as $value) {
                if (!is_array($value)
                    || !is_string($value['effective_from'] ?? null)
                    || !is_string($value['canonical_value'] ?? null)
                ) {
                    throw new \UnexpectedValueException("Parametr {$key} má neplatnou hodnotu.");
                }
                $rows[] = [
                    'effective_from' => $value['effective_from'],
                    'canonical_value' => $value['canonical_value'],
                ];
            }
            usort(
                $rows,
                static fn (array $a, array $b): int => strcmp($a['effective_from'], $b['effective_from']),
            );
            $this->values[$key] = $rows;
            foreach ($parameter['control_refs'] ?? [] as $ref) {
                if (!is_array($ref) || !is_int($ref['control_id'] ?? null)) {
                    continue;
                }
                $this->keysByControl[$ref['control_id']][] = $key;
            }
        }
        foreach ($this->keysByControl as &$keys) {
            sort($keys);
        }
    }

    /**
     * Hodnota parametru účinná k datu. Fail-closed: parametr bez hodnoty
     * účinné k danému dni je chyba, ne nula ani nejbližší pozdější sazba.
     */
    public function value(string $parameterKey, string $onDate): string
    {
        $rows = $this->values[$parameterKey] ?? null;
        if ($rows === null) {
            throw new \OutOfBoundsException(
                "Parametr kontrol JMHZ {$parameterKey} není v katalogu.",
            );
        }
        $found = null;
        foreach ($rows as $row) {
            if (strcmp($row['effective_from'], $onDate) <= 0) {
                $found = $row['canonical_value'];
            }
        }
        if ($found === null) {
            throw new \OutOfBoundsException(
                "Parametr kontrol JMHZ {$parameterKey} nemá hodnotu účinnou k {$onDate}.",
            );
        }

        return $found;
    }

    /**
     * Kontroly, ke kterým katalog parametr přiřazuje. Slouží guardu, který
     * hlídá, že implementovaná kontrola používá právě ty parametry, které jí
     * ČSSZ přiřadila — přesun sazby mezi kontrolami se tak nedá přehlédnout.
     *
     * @return list<string>
     */
    public function keysForControl(int $controlId): array
    {
        return $this->keysByControl[$controlId] ?? [];
    }

    /**
     * Součin celého čísla a desetinného parametru, zaokrouhlený nahoru na
     * celé koruny — přesně to, co katalog předepisuje u sazeb pojistného.
     */
    public function multiplyCeil(int $amount, string $parameterKey, string $onDate): int
    {
        [$numerator, $scale] = self::decompose($this->value($parameterKey, $onDate));
        $product = $amount * $numerator;
        $divisor = 10 ** $scale;

        return intdiv($product + $divisor - 1, $divisor);
    }

    /**
     * Součin celého čísla a desetinného parametru bez zaokrouhlení, vrácený
     * jako dvojice čitatel/jmenovatel — pro kontroly, které porovnávají meze
     * a nesmí přitom samy zaokrouhlovat.
     *
     * @return array{0:int,1:int}
     */
    public function multiplyExact(int $amount, string $parameterKey, string $onDate): array
    {
        [$numerator, $scale] = self::decompose($this->value($parameterKey, $onDate));

        return [$amount * $numerator, 10 ** $scale];
    }

    public function integerValue(string $parameterKey, string $onDate): int
    {
        $value = $this->value($parameterKey, $onDate);
        if (preg_match('/^-?\d+$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Parametr kontrol JMHZ {$parameterKey} není celé číslo.",
            );
        }

        return (int) $value;
    }

    /** @return array{0:int,1:int} čitatel a počet desetinných míst */
    private static function decompose(string $canonical): array
    {
        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/D', $canonical, $match) !== 1) {
            throw new \UnexpectedValueException(
                "Parametr kontrol JMHZ nemá desetinný tvar: {$canonical}.",
            );
        }
        $fraction = $match[2] ?? '';

        return [(int) ($match[1] . $fraction), strlen($fraction)];
    }
}
