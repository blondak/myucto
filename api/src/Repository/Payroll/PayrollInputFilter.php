<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Filtr seznamu mzdových vstupů jednoho měsíce.
 *
 * Tentýž filtr čte seznam, jeho souhrn i hromadné schválení a zrušení. Kdyby
 * si každá cesta skládala podmínky sama, „Schválit 480 odpovídajících filtru"
 * by schválilo jinou množinu, než jakou uživatel právě vidí — a právě tohle
 * se nesmí rozejít ani o řádek.
 *
 * Pole se přijímají jako seznam i jako text oddělený čárkami: prohlížeč je
 * posílá v adrese (`status=draft,approved`), JSON tělo hromadné akce jako pole.
 */
final class PayrollInputFilter
{
    /** Stavy, podle kterých jde filtrovat. Zrušený vstup seznam nevypisuje nikdy. */
    public const STATUSES = ['draft', 'approved', 'locked'];
    /** Totožné s ENUM `payroll_inputs.source_kind` (migrace 1210 + 1308). */
    public const SOURCE_KINDS = [
        'manual',
        'recurring',
        'time',
        'absence',
        'import',
        'correction',
        'travel',
    ];
    public const GROUP_BY = ['employee', 'component'];
    private const Q_MAX_LENGTH = 100;
    private const LIST_MAX_ITEMS = 200;

    /**
     * @param list<int> $componentIds
     * @param list<string> $componentCodes
     * @param list<string> $sourceKinds
     * @param list<string> $statuses prázdné = všechny kromě zrušených
     */
    public function __construct(
        public readonly string $periodStart,
        public readonly ?int $employmentId = null,
        public readonly ?int $employeeId = null,
        public readonly ?string $q = null,
        public readonly array $componentIds = [],
        public readonly array $componentCodes = [],
        public readonly array $sourceKinds = [],
        public readonly array $statuses = [],
        public readonly ?int $importId = null,
    ) {
        if ($employmentId !== null && $employmentId <= 0) {
            throw new \InvalidArgumentException('Vztah musí být kladné číslo.');
        }
        if ($employeeId !== null && $employeeId <= 0) {
            throw new \InvalidArgumentException('Zaměstnanec musí být kladné číslo.');
        }
        if ($importId !== null && $importId <= 0) {
            throw new \InvalidArgumentException('Importní dávka musí být kladné číslo.');
        }
    }

    /**
     * @param array<string,mixed> $params dotaz nebo tělo požadavku
     */
    public static function fromArray(string $periodStart, array $params): self
    {
        $q = $params['q'] ?? null;
        if ($q !== null && !is_string($q)) {
            throw new \InvalidArgumentException('q musí být text.');
        }
        $q = $q === null ? null : trim($q);
        if ($q !== null && mb_strlen($q) > self::Q_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Hledaný text může mít nejvýše %d znaků.',
                self::Q_MAX_LENGTH,
            ));
        }

        $statuses = self::stringList($params['status'] ?? null, 'status');
        foreach ($statuses as $status) {
            if (!in_array($status, self::STATUSES, true)) {
                throw new \InvalidArgumentException(
                    'status smí obsahovat jen draft, approved nebo locked.',
                );
            }
        }
        $sourceKinds = self::stringList($params['source_kind'] ?? null, 'source_kind');
        foreach ($sourceKinds as $sourceKind) {
            if (!in_array($sourceKind, self::SOURCE_KINDS, true)) {
                throw new \InvalidArgumentException('Zdroj mzdového vstupu není podporovaný.');
            }
        }
        $codes = self::stringList($params['component_code'] ?? null, 'component_code');
        foreach ($codes as $code) {
            if (mb_strlen($code) > 64) {
                throw new \InvalidArgumentException('Kód mzdové složky je příliš dlouhý.');
            }
        }

        return new self(
            $periodStart,
            self::optionalId($params['employment_id'] ?? null, 'employment_id'),
            self::optionalId($params['employee_id'] ?? null, 'employee_id'),
            $q === '' ? null : $q,
            self::idList($params['component_id'] ?? null, 'component_id'),
            $codes,
            $sourceKinds,
            $statuses,
            self::optionalId($params['import_id'] ?? null, 'import_id'),
        );
    }

    /**
     * Zúžení jen na koncepty — hromadné schválení i zrušení se jiného stavu
     * netýká. Když filtr koncepty výslovně vylučuje, nezbude nic.
     */
    public function draftsOnly(): ?self
    {
        if ($this->statuses !== [] && !in_array('draft', $this->statuses, true)) {
            return null;
        }

        return new self(
            $this->periodStart,
            $this->employmentId,
            $this->employeeId,
            $this->q,
            $this->componentIds,
            $this->componentCodes,
            $this->sourceKinds,
            ['draft'],
            $this->importId,
        );
    }

    /**
     * WHERE nad aliasy `input`, `employee`, `employment`, `component` — tytéž,
     * které používá výpis.
     *
     * @return array{sql:string,params:list<int|string>}
     */
    public function where(int $supplierId): array
    {
        $sql = 'input.supplier_id = ? AND input.period_start = ?';
        $params = [$supplierId, $this->periodStart];
        if ($this->statuses === []) {
            $sql .= ' AND input.status <> "cancelled"';
        } else {
            $sql .= ' AND input.status IN (' . self::placeholders($this->statuses) . ')';
            array_push($params, ...$this->statuses);
        }
        if ($this->employmentId !== null) {
            $sql .= ' AND input.employment_id = ?';
            $params[] = $this->employmentId;
        }
        if ($this->employeeId !== null) {
            $sql .= ' AND input.employee_id = ?';
            $params[] = $this->employeeId;
        }
        if ($this->componentIds !== []) {
            $sql .= ' AND input.component_id IN (' . self::placeholders($this->componentIds) . ')';
            array_push($params, ...$this->componentIds);
        }
        if ($this->componentCodes !== []) {
            $sql .= ' AND component.code IN (' . self::placeholders($this->componentCodes) . ')';
            array_push($params, ...$this->componentCodes);
        }
        if ($this->sourceKinds !== []) {
            $sql .= ' AND input.source_kind IN (' . self::placeholders($this->sourceKinds) . ')';
            array_push($params, ...$this->sourceKinds);
        }
        if ($this->importId !== null) {
            $sql .= ' AND input.import_id = ?';
            $params[] = $this->importId;
        }
        if ($this->q !== null) {
            // Osobní číslo je kód vztahu; hledá se v něm i ve jméně.
            $like = '%' . addcslashes($this->q, '\\%_') . '%';
            $sql .= ' AND (employee.full_name LIKE ? OR employment.code LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /** @return array<string,mixed> filtr tak, jak se vrací v odpovědi */
    public function toArray(): array
    {
        return [
            'period' => substr($this->periodStart, 0, 7),
            'employment_id' => $this->employmentId,
            'employee_id' => $this->employeeId,
            'q' => $this->q,
            'component_id' => $this->componentIds,
            'component_code' => $this->componentCodes,
            'source_kind' => $this->sourceKinds,
            'status' => $this->statuses,
            'import_id' => $this->importId,
        ];
    }

    /** @param list<int|string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $name): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$name} musí být seznam hodnot.");
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException("{$name} musí obsahovat text.");
            }
            $item = trim($item);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        if (count($result) > self::LIST_MAX_ITEMS) {
            throw new \InvalidArgumentException("{$name} obsahuje příliš mnoho hodnot.");
        }

        return $result;
    }

    /** @return list<int> */
    private static function idList(mixed $value, string $name): array
    {
        if (is_int($value)) {
            $value = [$value];
        }
        if (is_array($value)) {
            $value = array_map(
                static fn (mixed $item): string => is_int($item) ? (string) $item : (is_string($item) ? $item : '?'),
                $value,
            );
        }
        $ids = [];
        foreach (self::stringList($value, $name) as $item) {
            $id = filter_var($item, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new \InvalidArgumentException("{$name} musí obsahovat jen kladná celá čísla.");
            }
            $ids[] = (int) $id;
        }

        return $ids;
    }

    private static function optionalId(mixed $value, string $name): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new \InvalidArgumentException("{$name} musí být kladné celé číslo.");
        }

        return (int) $id;
    }
}
