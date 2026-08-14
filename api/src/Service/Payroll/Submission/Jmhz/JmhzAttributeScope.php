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

    /** @var list<string> */
    private array $bodies = [];

    private int $reads = 0;

    public function __construct(
        public readonly string $part,
        public readonly int $ordinal = 0,
    ) {}

    /**
     * Zvolený typ formuláře součásti. `xs:choice` z osmi typů dovoluje právě
     * jeden; víc jich znamená vadnou strukturu, žádný prázdnou součást.
     */
    public function noteBody(string $localName): void
    {
        $this->bodies[] = $localName;
    }

    /** @return list<string> */
    public function bodies(): array
    {
        return $this->bodies;
    }

    public function add(JmhzAttributeOccurrence $occurrence): void
    {
        $this->occurrences[$occurrence->attributeId][] = $occurrence;
    }

    public function has(string $attributeId): bool
    {
        return isset($this->occurrences[$attributeId]);
    }

    /**
     * Kolikrát si volající vyžádal atribut, který v podání OPRAVDU je.
     *
     * Slouží k rozlišení „kontrola proběhla a prošla" od „kontrola neměla co
     * číst". Bez toho by se za splněnou vydala i kontrola, jejíž první podmínka
     * se opírá o nevykázaný atribut a která proto skončí dřív, než se k něčemu
     * dostane. Dotaz na nepřítomný atribut se nepočítá — právě ten je rozdíl.
     */
    public function readCount(): int
    {
        return $this->reads;
    }

    public function resetReadCount(): void
    {
        $this->reads = 0;
    }

    /** @return list<JmhzAttributeOccurrence> */
    public function all(string $attributeId): array
    {
        $found = $this->occurrences[$attributeId] ?? [];
        if ($found !== []) {
            ++$this->reads;
        }

        return $found;
    }

    /**
     * Jediný výskyt atributu. Vícenásobný výskyt tam, kde se čeká jeden,
     * je chyba modelu, ne důvod vzít první hodnotu.
     */
    public function value(string $attributeId): ?string
    {
        $found = $this->all($attributeId);
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
     * Hodnoty seskupené podle opakovaného bloku — hodnotou je mapa
     * atribut → hodnota. Používá se u ELDP sekcí, kde se jeden blok opakuje
     * N× za měsíc.
     *
     * `$ancestorDepth` říká, na jaké úrovni blok začíná. Bez něj by se
     * seskupovalo podle PŘÍMÉHO rodiče, což u ELDP rozpadne jednu sekci na
     * několik skupin: kód a počet dnů leží přímo v `eldp`, kdežto vyloučené
     * a odečítané doby o úroveň hlouběji. Kontrola porovnávající počet dnů
     * s odečítanými dobami by pak nikdy neměla obě hodnoty pohromadě
     * a tiše by prošla.
     *
     * @param list<string> $attributeIds
     * @return list<array<string, string>>
     */
    public function groupedBy(array $attributeIds, ?int $ancestorDepth = null): array
    {
        $groups = [];
        foreach ($attributeIds as $attributeId) {
            foreach ($this->all($attributeId) as $occurrence) {
                $key = self::truncateGroupKey($occurrence->groupKey, $ancestorDepth);
                $groups[$key][$attributeId] = $occurrence->value;
            }
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * Ořízne klíč skupiny na zadaný počet úrovní. Výskyt mělčí, než je zadaná
     * úroveň, patří do vlastní skupiny — sloučit ho s bloky by spojilo hodnotu
     * platnou pro celý formulář s hodnotou platnou pro jednu sekci.
     */
    private static function truncateGroupKey(string $groupKey, ?int $depth): string
    {
        if ($depth === null || $groupKey === '') {
            return $groupKey;
        }
        $segments = explode('.', $groupKey);
        if (count($segments) <= $depth) {
            return $groupKey;
        }

        return implode('.', array_slice($segments, 0, $depth));
    }

    /** @return list<string> */
    public function attributeIds(): array
    {
        $ids = array_keys($this->occurrences);
        sort($ids);

        return $ids;
    }
}
