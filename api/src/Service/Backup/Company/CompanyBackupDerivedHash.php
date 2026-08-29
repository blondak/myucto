<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jedna fingerprintovaná vazba JSON sloupce na jeho odvozený hash. */
final readonly class CompanyBackupDerivedHash
{
    /** @var list<CompanyBackupDerivedHashDependency> */
    public array $dependencies;

    /** @param list<CompanyBackupDerivedHashDependency> $dependencies */
    private function __construct(
        public CompanyBackupDerivedHashAlgorithm $algorithm,
        array $dependencies,
        public string $hashColumn,
        public bool $nullable,
        public string $sourceColumn,
    ) {
        $this->dependencies = $dependencies;
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
        if ($keys !== $baseKeys && $keys !== $dependencyKeys) {
            throw self::invalid($registryKey);
        }

        $algorithmValue = $value['algorithm'];
        $algorithm = is_string($algorithmValue)
            ? CompanyBackupDerivedHashAlgorithm::tryFrom($algorithmValue)
            : null;
        $hashColumn = $value['hash_column'];
        $nullable = $value['nullable'];
        $sourceColumn = $value['source_column'];
        if ($algorithm === null
            || !is_string($hashColumn)
            || !self::isIdentifier($hashColumn)
            || !is_bool($nullable)
            || !is_string($sourceColumn)
            || !self::isIdentifier($sourceColumn)
            || $sourceColumn === $hashColumn
        ) {
            throw self::invalid(
                $registryKey,
                is_string($hashColumn) ? $hashColumn : null,
            );
        }

        $dependencies = self::dependencies(
            $value['dependencies'] ?? [],
            $registryKey,
            array_key_exists('dependencies', $value),
        );

        return new self(
            $algorithm,
            $dependencies,
            $hashColumn,
            $nullable,
            $sourceColumn,
        );
    }

    public function signature(): string
    {
        $signature = $this->hashColumn
            . '<-'
            . $this->algorithm->value
            . ':'
            . $this->sourceColumn
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
