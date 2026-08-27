<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Fyzická FK hrana načtená z aktuálního databázového schématu. */
final readonly class CompanyBackupForeignKey
{
    /** @var list<string> */
    public array $columns;

    /** @var list<string> */
    public array $targetColumns;

    /**
     * @param array<mixed> $columns
     * @param array<mixed> $targetColumns
     */
    public function __construct(
        array $columns,
        public string $targetTable,
        array $targetColumns,
    ) {
        $this->columns = self::identifierList($columns);
        $this->targetColumns = self::identifierList($targetColumns);
        self::assertIdentifier($targetTable);
        if ($this->columns === []
            || count($this->columns) !== count($this->targetColumns)
        ) {
            throw new \InvalidArgumentException('Databázová reference má neplatný tvar.');
        }
    }

    public function signature(): string
    {
        return implode(',', $this->columns)
            . '->'
            . $this->targetTable
            . ':'
            . implode(',', $this->targetColumns);
    }

    /**
     * @param array<mixed> $columns
     * @return list<string>
     */
    private static function identifierList(array $columns): array
    {
        if (!array_is_list($columns)) {
            throw new \InvalidArgumentException('Sloupce databázové reference musí být seznam.');
        }
        $result = [];
        $seen = [];
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new \InvalidArgumentException(
                    'Sloupec databázové reference nemá bezpečný identifikátor.',
                );
            }
            self::assertIdentifier($column);
            if (isset($seen[$column])) {
                throw new \InvalidArgumentException(
                    'Databázová reference obsahuje duplicitní sloupec.',
                );
            }
            $seen[$column] = true;
            $result[] = $column;
        }
        return $result;
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $identifier) !== 1) {
            throw new \InvalidArgumentException(
                'Databázová reference nemá bezpečný identifikátor.',
            );
        }
    }
}
