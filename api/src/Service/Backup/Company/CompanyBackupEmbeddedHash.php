<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jedna fingerprintovaná pečeť uložená uvnitř strukturovaného sloupce. */
final readonly class CompanyBackupEmbeddedHash
{
    /** @var list<string> */
    public array $dependencies;

    /** @var list<string> */
    public array $hashPath;

    /** @var list<list<string>> */
    public array $omitPaths;

    /** @var list<CompanyBackupEmbeddedHashProjectionField> */
    public array $projection;

    /** @var list<string> */
    public array $sourcePath;

    /**
     * @param list<string> $dependencies
     * @param list<string> $hashPath
     * @param list<list<string>> $omitPaths
     * @param list<CompanyBackupEmbeddedHashProjectionField> $projection
     * @param list<string> $sourcePath
     */
    private function __construct(
        public CompanyBackupEmbeddedHashAlgorithm $algorithm,
        public string $column,
        array $dependencies,
        array $hashPath,
        public string $name,
        public bool $nullable,
        array $omitPaths,
        array $projection,
        array $sourcePath,
    ) {
        $this->dependencies = $dependencies;
        $this->hashPath = $hashPath;
        $this->omitPaths = $omitPaths;
        $this->projection = $projection;
        $this->sourcePath = $sourcePath;
    }

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $baseKeys = [
            'algorithm',
            'column',
            'dependencies',
            'hash_path',
            'name',
            'nullable',
            'omit_paths',
            'source_path',
        ];
        $projectionKeys = [
            'algorithm',
            'column',
            'dependencies',
            'hash_path',
            'name',
            'nullable',
            'omit_paths',
            'projection',
            'source_path',
        ];
        if ($keys !== $baseKeys && $keys !== $projectionKeys) {
            throw self::invalid($registryKey);
        }

        $algorithmValue = $value['algorithm'];
        $algorithm = is_string($algorithmValue)
            ? CompanyBackupEmbeddedHashAlgorithm::tryFrom($algorithmValue)
            : null;
        $column = $value['column'];
        $name = $value['name'];
        $nullable = $value['nullable'];
        if ($algorithm === null
            || !is_string($column)
            || !self::isIdentifier($column)
            || !is_string($name)
            || !self::isIdentifier($name)
            || !is_bool($nullable)
        ) {
            throw self::invalid(
                $registryKey,
                is_string($column) ? $column : null,
            );
        }

        $dependencies = self::identifierList(
            $value['dependencies'],
            $registryKey,
            $column,
        );
        $hashPath = self::path($value['hash_path'], $registryKey, $column, true);
        $sourcePath = self::path(
            $value['source_path'],
            $registryKey,
            $column,
            true,
        );
        $omitPaths = self::omitPaths(
            $value['omit_paths'],
            $registryKey,
            $column,
        );
        $hasProjection = array_key_exists('projection', $value);
        $projection = self::projection(
            $value['projection'] ?? [],
            $registryKey,
            $column,
        );
        $isProjection = $algorithm
            === CompanyBackupEmbeddedHashAlgorithm::Sha256CanonicalProjection;
        if ($hashPath === $sourcePath
            || self::wildcardCount($hashPath) !== self::wildcardCount($sourcePath)
            || self::wildcardContexts($hashPath)
                !== self::wildcardContexts($sourcePath)
            || ($algorithm === CompanyBackupEmbeddedHashAlgorithm::Sha256ExactString
                && $omitPaths !== [])
            || $isProjection !== $hasProjection
            || ($isProjection && ($projection === [] || $omitPaths !== []))
            || (!$isProjection
                && !self::omitsNestedHash($sourcePath, $hashPath, $omitPaths))
        ) {
            throw self::invalid($registryKey, $column);
        }
        $sourceWildcardContexts = self::wildcardContexts($sourcePath);
        foreach ($projection as $field) {
            if ($field->hasLiteral) {
                continue;
            }
            $fieldContexts = self::wildcardContexts($field->path);
            if (array_slice(
                $sourceWildcardContexts,
                0,
                count($fieldContexts),
            ) !== $fieldContexts
                || self::isPathPrefix($field->path, $hashPath)
            ) {
                throw self::invalid($registryKey, $column);
            }
        }

        return new self(
            $algorithm,
            $column,
            $dependencies,
            $hashPath,
            $name,
            $nullable,
            $omitPaths,
            $projection,
            $sourcePath,
        );
    }

    public function signature(): string
    {
        $signature = $this->name
            . '='
            . $this->algorithm->value
            . ':'
            . $this->column
            . ':'
            . implode('.', $this->sourcePath)
            . '->'
            . implode('.', $this->hashPath)
            . ($this->nullable ? '?' : '!');
        if ($this->dependencies !== []) {
            $signature .= '[' . implode(',', $this->dependencies) . ']';
        }
        if ($this->omitPaths !== []) {
            $signature .= '-{' . implode(',', array_map(
                static fn (array $path): string => implode('.', $path),
                $this->omitPaths,
            )) . '}';
        }
        if ($this->projection !== []) {
            $signature .= '<{' . implode(',', array_map(
                static fn (
                    CompanyBackupEmbeddedHashProjectionField $field,
                ): string => $field->signature(),
                $this->projection,
            )) . '}';
        }
        return $signature;
    }

    /** @return list<CompanyBackupEmbeddedHashProjectionField> */
    private static function projection(
        mixed $value,
        string $registryKey,
        string $column,
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($registryKey, $column);
        }
        $result = [];
        $keys = [];
        foreach ($value as $item) {
            $field = CompanyBackupEmbeddedHashProjectionField::fromArray(
                $item,
                $registryKey,
                $column,
            );
            if (isset($keys[$field->key])) {
                throw self::invalid($registryKey, $column);
            }
            $keys[$field->key] = true;
            $result[] = $field;
        }
        $ordered = array_keys($keys);
        sort($ordered, SORT_STRING);
        if ($ordered !== array_keys($keys)) {
            throw self::invalid($registryKey, $column);
        }
        return $result;
    }

    /** @return list<string> */
    private static function identifierList(
        mixed $value,
        string $registryKey,
        string $column,
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($registryKey, $column);
        }
        $result = [];
        $seen = [];
        foreach ($value as $identifier) {
            if (!is_string($identifier)
                || !self::isIdentifier($identifier)
                || isset($seen[$identifier])
            ) {
                throw self::invalid($registryKey, $column);
            }
            $seen[$identifier] = true;
            $result[] = $identifier;
        }
        $ordered = $result;
        sort($ordered, SORT_STRING);
        if ($ordered !== $result) {
            throw self::invalid($registryKey, $column);
        }
        return $result;
    }

    /** @return list<string> */
    private static function path(
        mixed $value,
        string $registryKey,
        string $column,
        bool $allowWildcard,
    ): array {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw self::invalid($registryKey, $column);
        }
        $result = [];
        foreach ($value as $segment) {
            if (!is_string($segment)
                || ($segment === '*' && !$allowWildcard)
                || ($segment !== '*' && !self::isIdentifier($segment))
            ) {
                throw self::invalid($registryKey, $column);
            }
            $result[] = $segment;
        }
        return $result;
    }

    /** @return list<list<string>> */
    private static function omitPaths(
        mixed $value,
        string $registryKey,
        string $column,
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($registryKey, $column);
        }
        $result = [];
        $signatures = [];
        foreach ($value as $item) {
            $path = self::path($item, $registryKey, $column, false);
            $signature = implode('.', $path);
            foreach (array_keys($signatures) as $claimed) {
                if (str_starts_with($signature . '.', $claimed . '.')
                    || str_starts_with($claimed . '.', $signature . '.')
                ) {
                    throw self::invalid($registryKey, $column);
                }
            }
            $signatures[$signature] = true;
            $result[] = $path;
        }
        $ordered = array_keys($signatures);
        sort($ordered, SORT_STRING);
        if ($ordered !== array_keys($signatures)) {
            throw self::invalid($registryKey, $column);
        }
        return $result;
    }

    /**
     * Hash uvnitř svého vlastního zdroje musí být z výpočtu výslovně vynechán.
     *
     * @param list<string> $sourcePath
     * @param list<string> $hashPath
     * @param list<list<string>> $omitPaths
     */
    private static function omitsNestedHash(
        array $sourcePath,
        array $hashPath,
        array $omitPaths,
    ): bool {
        if (array_slice($hashPath, 0, count($sourcePath)) !== $sourcePath) {
            return true;
        }
        $relative = array_slice($hashPath, count($sourcePath));
        return $relative !== [] && in_array($relative, $omitPaths, true);
    }

    /** @param list<string> $path */
    private static function wildcardCount(array $path): int
    {
        return count(array_filter(
            $path,
            static fn (string $segment): bool => $segment === '*',
        ));
    }

    /**
     * @param list<string> $path
     * @return list<string>
     */
    private static function wildcardContexts(array $path): array
    {
        $contexts = [];
        $prefix = [];
        foreach ($path as $segment) {
            if ($segment === '*') {
                $contexts[] = implode('.', $prefix);
            }
            $prefix[] = $segment;
        }
        return $contexts;
    }

    /**
     * @param list<string> $prefix
     * @param list<string> $path
     */
    private static function isPathPrefix(array $prefix, array $path): bool
    {
        return count($prefix) <= count($path)
            && array_slice($path, 0, count($prefix)) === $prefix;
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
            'data_embedded_hash_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
