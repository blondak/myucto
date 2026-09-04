<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Číselný rozsah jednoho runtime AUTO_INCREMENT primárního klíče. */
final readonly class CompanyBackupAutoIncrementColumn
{
    public function __construct(
        public string $column,
        public int $maximumValue,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
            || $maximumValue < 1
        ) {
            throw new \InvalidArgumentException(
                'AUTO_INCREMENT metadata nejsou platná.',
            );
        }
    }

    public static function fromDatabaseMetadata(
        string $column,
        string $dataType,
        string $columnType,
    ): self {
        $dataType = strtolower($dataType);
        $columnType = strtolower($columnType);
        if (!in_array($dataType, [
            'tinyint',
            'smallint',
            'mediumint',
            'int',
            'bigint',
        ], true)
            || preg_match(
                '/^' . preg_quote($dataType, '/')
                    . '(?:\([0-9]+\))?(?: unsigned)?(?: zerofill)?$/D',
                $columnType,
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'AUTO_INCREMENT typ není podporovaný.',
            );
        }

        $unsigned = str_contains($columnType, ' unsigned')
            || str_ends_with($columnType, ' zerofill');
        $maximum = match ([$dataType, $unsigned]) {
            ['tinyint', false] => 127,
            ['tinyint', true] => 255,
            ['smallint', false] => 32_767,
            ['smallint', true] => 65_535,
            ['mediumint', false] => 8_388_607,
            ['mediumint', true] => 16_777_215,
            ['int', false] => 2_147_483_647,
            ['int', true] => 4_294_967_295,
            ['bigint', false], ['bigint', true] => PHP_INT_MAX,
        };
        return new self($column, $maximum);
    }
}
