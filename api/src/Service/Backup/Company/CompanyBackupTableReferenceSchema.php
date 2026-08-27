<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Runtime nullability a fyzické FK jedné exportované tabulky. */
final readonly class CompanyBackupTableReferenceSchema
{
    /** @var list<string> */
    public array $nullableColumns;

    /** @var list<CompanyBackupForeignKey> */
    public array $foreignKeys;

    /**
     * @param array<mixed> $nullableColumns
     * @param array<mixed> $foreignKeys
     */
    public function __construct(array $nullableColumns, array $foreignKeys)
    {
        if (!array_is_list($nullableColumns) || !array_is_list($foreignKeys)) {
            throw new \InvalidArgumentException('Referenční schéma musí obsahovat seznamy.');
        }
        $nullable = [];
        $seenNullable = [];
        foreach ($nullableColumns as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($seenNullable[$column])
            ) {
                throw new \InvalidArgumentException(
                    'Referenční schéma obsahuje neplatný nullable sloupec.',
                );
            }
            $seenNullable[$column] = true;
            $nullable[] = $column;
        }

        $keys = [];
        foreach ($foreignKeys as $foreignKey) {
            if (!$foreignKey instanceof CompanyBackupForeignKey) {
                throw new \InvalidArgumentException(
                    'Referenční schéma obsahuje neplatný cizí klíč.',
                );
            }
            $signature = $foreignKey->signature();
            if (isset($keys[$signature])) {
                throw new \InvalidArgumentException(
                    'Referenční schéma obsahuje duplicitní cizí klíč.',
                );
            }
            $keys[$signature] = $foreignKey;
        }
        ksort($keys, SORT_STRING);

        $this->nullableColumns = $nullable;
        $this->foreignKeys = array_values($keys);
    }
}
