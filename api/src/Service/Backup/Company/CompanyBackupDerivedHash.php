<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jedna fingerprintovaná vazba JSON sloupce na jeho odvozený hash. */
final readonly class CompanyBackupDerivedHash
{
    /** @var list<CompanyBackupDerivedHashDependency> */
    public array $dependencies;

    /** @var list<CompanyBackupDerivedHashProjectionField> */
    public array $projection;

    /**
     * @param list<CompanyBackupDerivedHashDependency> $dependencies
     * @param list<CompanyBackupDerivedHashProjectionField> $projection
     */
    private function __construct(
        public CompanyBackupDerivedHashAlgorithm $algorithm,
        array $dependencies,
        public string $hashColumn,
        public bool $nullable,
        public ?string $sourceColumn,
        array $projection,
    ) {
        $this->dependencies = $dependencies;
        $this->projection = $projection;
    }

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $baseKeys = ['algorithm', 'hash_column', 'nullable', 'source_column'];
        $dependencyKeys = [
            'algorithm',
            'dependencies',
            'hash_column',
            'nullable',
            'source_column',
        ];
        $projectionKeys = [
            'algorithm',
            'hash_column',
            'nullable',
            'projection',
        ];
        if ($keys !== $baseKeys
            && $keys !== $dependencyKeys
            && $keys !== $projectionKeys
        ) {
            throw self::invalid($registryKey);
        }

        $algorithmValue = $value['algorithm'];
        $algorithm = is_string($algorithmValue)
            ? CompanyBackupDerivedHashAlgorithm::tryFrom($algorithmValue)
            : null;
        $hashColumn = $value['hash_column'];
        $nullable = $value['nullable'];
        $sourceColumn = $value['source_column'] ?? null;
        if ($algorithm === null
            || !is_string($hashColumn)
            || !self::isIdentifier($hashColumn)
            || !is_bool($nullable)
        ) {
            throw self::invalid(
                $registryKey,
                is_string($hashColumn) ? $hashColumn : null,
            );
        }

        if ($algorithm === CompanyBackupDerivedHashAlgorithm::Sha256CanonicalJson
            && (!is_string($sourceColumn)
                || !self::isIdentifier($sourceColumn)
                || $sourceColumn === $hashColumn
                || array_key_exists('projection', $value))
        ) {
            throw self::invalid($registryKey, $hashColumn);
        }
        if ($algorithm
                === CompanyBackupDerivedHashAlgorithm::Sha256CanonicalProjection
            && ($sourceColumn !== null
                || $nullable
                || !array_key_exists('projection', $value)
                || array_key_exists('dependencies', $value))
        ) {
            throw self::invalid($registryKey, $hashColumn);
        }

        $dependencies = self::dependencies(
            $value['dependencies'] ?? [],
            $registryKey,
            array_key_exists('dependencies', $value),
        );
        $projection = self::projection(
            $value['projection'] ?? [],
            $registryKey,
            array_key_exists('projection', $value),
        );

        return new self(
            $algorithm,
            $dependencies,
            $hashColumn,
            $nullable,
            $sourceColumn,
            $projection,
        );
    }

    public function signature(): string
    {
        $source = $this->sourceColumn !== null
            ? $this->sourceColumn
            : '{' . implode(',', array_map(
                static fn (
                    CompanyBackupDerivedHashProjectionField $field,
                ): string => $field->signature(),
                $this->projection,
            )) . '}';
        $signature = $this->hashColumn
            . '<-'
            . $this->algorithm->value
            . ':'
            . $source
            . ($this->nullable ? '?' : '!');
        if ($this->dependencies !== []) {
            $signature .= '[' . implode(',', array_map(
                static fn (CompanyBackupDerivedHashDependency $dependency): string =>
                    $dependency->signature(),
                $this->dependencies,
            )) . ']';
        }
        return $signature;
    }

    /** @return list<string> */
    public function sourceColumns(): array
    {
        if ($this->sourceColumn !== null) {
            return [$this->sourceColumn];
        }
        $columns = [];
        foreach ($this->projection as $field) {
            $column = $field->sourceColumn();
            if ($column !== null) {
                $columns[] = $column;
            }
        }
        return $columns;
    }

    /** @return list<CompanyBackupDerivedHashDependency> */
    private static function dependencies(
        mixed $value,
        string $registryKey,
        bool $declared,
    ): array {
        if (!is_array($value)
            || !array_is_list($value)
            || ($declared && $value === [])
        ) {
            throw self::invalid($registryKey);
        }
        $dependencies = [];
        $paths = [];
        foreach ($value as $item) {
            $dependency = CompanyBackupDerivedHashDependency::fromArray(
                $item,
                $registryKey,
            );
            $path = implode('.', $dependency->path);
            if (isset($paths[$path])) {
                throw self::invalid($registryKey);
            }
            $paths[$path] = true;
            $dependencies[] = $dependency;
        }
        $ordered = $dependencies;
        usort(
            $ordered,
            static fn (
                CompanyBackupDerivedHashDependency $left,
                CompanyBackupDerivedHashDependency $right,
            ): int => strcmp($left->signature(), $right->signature()),
        );
        if ($ordered !== $dependencies) {
            throw self::invalid($registryKey);
        }
        return $dependencies;
    }

    /** @return list<CompanyBackupDerivedHashProjectionField> */
    private static function projection(
        mixed $value,
        string $registryKey,
        bool $declared,
    ): array {
        if (!is_array($value)
            || !array_is_list($value)
            || ($declared && $value === [])
            || (!$declared && $value !== [])
        ) {
            throw self::invalid($registryKey);
        }
        $fields = [];
        $keys = [];
        $columns = [];
        foreach ($value as $item) {
            $field = CompanyBackupDerivedHashProjectionField::fromArray(
                $item,
                $registryKey,
            );
            $column = $field->sourceColumn();
            if (isset($keys[$field->key])
                || ($column !== null && isset($columns[$column]))
            ) {
                throw self::invalid($registryKey, $column);
            }
            $keys[$field->key] = true;
            if ($column !== null) {
                $columns[$column] = true;
            }
            $fields[] = $field;
        }
        $ordered = $fields;
        usort(
            $ordered,
            static fn (
                CompanyBackupDerivedHashProjectionField $left,
                CompanyBackupDerivedHashProjectionField $right,
            ): int => strcmp($left->signature(), $right->signature()),
        );
        if ($ordered !== $fields) {
            throw self::invalid($registryKey);
        }
        return $fields;
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1;
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
