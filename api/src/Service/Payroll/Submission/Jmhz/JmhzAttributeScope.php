<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Jedna část podání se svými hodnotami atributů. Části jsou čtyři —
 * metadatová hlavička, souhrnná část, pojistná část a součást
 * individualizované části (jeden zaměstnanec × jedno zaměstnání).
 */
final class JmhzAttributeScope
{
    /** @var array<string, list<JmhzAttributeOccurrence>> */
    private array $occurrences = [];

    public function __construct(
        public readonly string $part,
        public readonly int $ordinal = 0,
    ) {}

    public function add(JmhzAttributeOccurrence $occurrence): void
    {
        $this->occurrences[$occurrence->attributeId][] = $occurrence;
    }

    public function has(string $attributeId): bool
    {
        return isset($this->occurrences[$attributeId]);
    }

    /** @return list<JmhzAttributeOccurrence> */
    public function all(string $attributeId): array
    {
        return $this->occurrences[$attributeId] ?? [];
    }

    /**
     * Jediný výskyt atributu. Vícenásobný výskyt tam, kde se čeká jeden,
     * je chyba modelu, ne důvod vzít první hodnotu.
     */
    public function value(string $attributeId): ?string
    {
        $found = $this->occurrences[$attributeId] ?? [];
        if ($found === []) {
            return null;
        }
        if (count($found) > 1) {
            throw new \UnexpectedValueException(
                "Atribut {$attributeId} má v části {$this->part} více výskytů.",
            );
        }

        return $found[0]->value;
    }

    public function integer(string $attributeId): ?int
    {
        $value = $this->value($attributeId);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^-?\d+$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Atribut {$attributeId} není celé číslo: {$value}.",
            );
        }

        return (int) $value;
    }

    /**
     * Škálované desetinné číslo jako celé číslo v setinách či tisícinách.
     * Vrací dvojici hodnota/měřítko, aby porovnání dvou atributů s různým
     * měřítkem nespadlo tiše na float.
     *
     * @return array{0:int,1:int}|null
     */
    public function scaled(string $attributeId): ?array
    {
        $value = $this->value($attributeId);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/D', $value, $match) !== 1) {
            throw new \UnexpectedValueException(
                "Atribut {$attributeId} není desetinné číslo: {$value}.",
            );
        }
        $fraction = $match[2] ?? '';

        return [(int) ($match[1] . $fraction), strlen($fraction)];
    }

    public function boolean(string $attributeId): ?bool
    {
        $value = $this->value($attributeId);
        if ($value === null) {
            return null;
        }

        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new \UnexpectedValueException(
                "Atribut {$attributeId} není příznak: {$value}.",
            ),
        };
    }

    /**
     * Hodnoty seskupené podle opakovaného rodiče — klíčem je `groupKey`,
     * hodnotou mapa atribut → hodnota. Používá se u ELDP sekcí, kde se
     * jeden blok opakuje N× za měsíc.
     *
     * @param list<string> $attributeIds
     * @return list<array<string, string>>
     */
    public function groupedBy(array $attributeIds): array
    {
        $groups = [];
        foreach ($attributeIds as $attributeId) {
            foreach ($this->all($attributeId) as $occurrence) {
                $groups[$occurrence->groupKey][$attributeId] = $occurrence->value;
            }
        }
        ksort($groups);

        return array_values($groups);
    }

    /** @return list<string> */
    public function attributeIds(): array
    {
        $ids = array_keys($this->occurrences);
        sort($ids);

        return $ids;
    }
}
