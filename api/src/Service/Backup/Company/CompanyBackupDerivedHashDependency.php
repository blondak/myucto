<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Odkaz z jednoho JSON payloadu na hash jiného exportovaného payloadu. */
final readonly class CompanyBackupDerivedHashDependency
{
    /** @var list<string> */
    public array $path;

    /** @param list<string> $path */
    private function __construct(
        array $path,
        public string $sourceHashColumn,
    ) {
        $this->path = $path;
    }

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['path', 'source_hash_column']) {
            throw self::invalid($registryKey);
        }

        $path = self::path($value['path'], $registryKey);
        $sourceHashColumn = $value['source_hash_column'];
        if (!is_string($sourceHashColumn)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $sourceHashColumn) !== 1
        ) {
            throw self::invalid(
                $registryKey,
                is_string($sourceHashColumn) ? $sourceHashColumn : null,
            );
        }

        return new self($path, $sourceHashColumn);
    }

    public function signature(): string
    {
        return implode('.', $this->path) . '<-' . $this->sourceHashColumn;
    }

    /** @return list<string> */
    private static function path(mixed $value, string $registryKey): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw self::invalid($registryKey);
        }
        $path = [];
        foreach ($value as $segment) {
            if (!is_string($segment)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $segment) !== 1
            ) {
                throw self::invalid($registryKey);
            }
            $path[] = $segment;
        }
        return $path;
    }

    private static function invalid(
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_derived_hash_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
