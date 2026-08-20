<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Fingerprint;

/** Bezpečná inventura názvů tabulky/view a jejích sloupců bez obsahu dat. */
final readonly class TenantSchemaTableInventory
{
    /** @var list<string> */
    public array $columns;

    /** @var list<string> */
    public array $primaryKey;

    /** @var list<TenantSchemaForeignKeyInventory> */
    public array $foreignKeys;

    /**
     * @param array<mixed> $columns
     * @param array<mixed> $primaryKey
     * @param array<mixed> $foreignKeys
     */
    public function __construct(
        public string $name,
        public string $type,
        array $columns,
        array $primaryKey,
        array $foreignKeys,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Inventura obsahuje neplatný název tabulky.');
        }
        if ($type === '' || strlen($type) > 32 || preg_match('/^[A-Z][A-Z ]*$/D', $type) !== 1) {
            throw new \InvalidArgumentException('Inventura obsahuje neplatný typ tabulky.');
        }
        if (!array_is_list($columns) || $columns === []) {
            throw new \InvalidArgumentException('Inventura tabulky musí obsahovat sloupce.');
        }

        $seen = [];
        $validated = [];
        foreach ($columns as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
            ) {
                throw new \InvalidArgumentException('Inventura obsahuje neplatný název sloupce.');
            }
            $folded = strtolower($column);
            if (isset($seen[$folded])) {
                throw new \InvalidArgumentException('Inventura obsahuje duplicitní sloupec.');
            }
            $seen[$folded] = true;
            $validated[] = $column;
        }
        $this->columns = $validated;

        if (!array_is_list($primaryKey)) {
            throw new \InvalidArgumentException('Primární klíč inventury musí být seznam.');
        }
        $seenPrimary = [];
        $validatedPrimary = [];
        foreach ($primaryKey as $column) {
            if (!is_string($column) || !in_array($column, $validated, true)) {
                throw new \InvalidArgumentException('Primární klíč inventury odkazuje na neznámý sloupec.');
            }
            if (isset($seenPrimary[$column])) {
                throw new \InvalidArgumentException('Primární klíč inventury obsahuje duplicitní sloupec.');
            }
            $seenPrimary[$column] = true;
            $validatedPrimary[] = $column;
        }
        $this->primaryKey = $validatedPrimary;

        if (!array_is_list($foreignKeys)) {
            throw new \InvalidArgumentException('Cizí klíče inventury musí být seznam.');
        }
        $seenForeign = [];
        $validatedForeign = [];
        foreach ($foreignKeys as $foreignKey) {
            if (!$foreignKey instanceof TenantSchemaForeignKeyInventory
                || !in_array($foreignKey->column, $validated, true)
            ) {
                throw new \InvalidArgumentException('Inventura obsahuje neplatný cizí klíč.');
            }
            $key = $foreignKey->column . "\0"
                . $foreignKey->referencedTable . "\0"
                . $foreignKey->referencedColumn;
            if (isset($seenForeign[$key])) {
                throw new \InvalidArgumentException('Inventura obsahuje duplicitní cizí klíč.');
            }
            $seenForeign[$key] = true;
            $validatedForeign[] = $foreignKey;
        }
        $this->foreignKeys = $validatedForeign;
    }
}
