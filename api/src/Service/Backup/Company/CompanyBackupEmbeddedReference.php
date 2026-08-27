<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Jedna remapovatelná reference uložená uvnitř strukturovaného sloupce. */
final readonly class CompanyBackupEmbeddedReference
{
    private const ACTOR_FALLBACKS = ['null', 'restore_actor'];

    /** @var list<string> */
    public array $path;

    /** @var list<string> */
    public array $targetColumns;

    /** @var list<string> */
    public array $fallbacks;

    /**
     * @param list<string> $path
     * @param list<string> $targetColumns
     * @param list<string> $fallbacks
     */
    private function __construct(
        public string $column,
        array $path,
        public string $target,
        array $targetColumns,
        public CompanyBackupReferenceMapping $mapping,
        public bool $nullable,
        array $fallbacks,
    ) {
        $this->path = $path;
        $this->targetColumns = $targetColumns;
        $this->fallbacks = $fallbacks;
    }

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'column',
            'fallbacks',
            'mapping',
            'nullable',
            'path',
            'target',
            'target_columns',
        ]) {
            throw self::invalid($registryKey);
        }

        $column = $value['column'];
        $path = self::path($value['path'], $registryKey);
        $target = $value['target'];
        $targetColumns = self::identifierList($value['target_columns'], $registryKey);
        $mappingValue = $value['mapping'];
        $mapping = is_string($mappingValue)
            ? CompanyBackupReferenceMapping::tryFrom($mappingValue)
            : null;
        $nullable = $value['nullable'];
        $fallbacks = $value['fallbacks'];
        if (!is_string($column)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
            || !is_string($target)
            || !str_starts_with($target, 'table:')
            || !TenantDataDefinition::isValidKey($target)
            || count($targetColumns) !== 1
            || $mapping === null
            || !is_bool($nullable)
            || !is_array($fallbacks)
            || !array_is_list($fallbacks)
        ) {
            throw self::invalid($registryKey, is_string($column) ? $column : null);
        }

        $validatedFallbacks = [];
        $seen = [];
        foreach ($fallbacks as $fallback) {
            if (!is_string($fallback)
                || !in_array($fallback, self::ACTOR_FALLBACKS, true)
                || isset($seen[$fallback])
            ) {
                throw self::invalid($registryKey, $column);
            }
            $seen[$fallback] = true;
            $validatedFallbacks[] = $fallback;
        }
        $sortedFallbacks = $validatedFallbacks;
        sort($sortedFallbacks, SORT_STRING);
        if ($validatedFallbacks !== $sortedFallbacks
            || ($mapping !== CompanyBackupReferenceMapping::Actor
                && $validatedFallbacks !== [])
            || (in_array('null', $validatedFallbacks, true) && !$nullable)
            || ($mapping === CompanyBackupReferenceMapping::Actor
                && ($target !== 'table:users' || $targetColumns !== ['id']))
        ) {
            throw self::invalid($registryKey, $column);
        }

        return new self(
            $column,
            $path,
            $target,
            $targetColumns,
            $mapping,
            $nullable,
            $validatedFallbacks,
        );
    }

    public function targetTable(): string
    {
        return substr($this->target, strlen('table:'));
    }

    public function signature(): string
    {
        return $this->column
            . ':'
            . implode('.', $this->path)
            . '->'
            . $this->targetTable()
            . ':'
            . implode(',', $this->targetColumns);
    }

    /** @return list<string> */
    private static function path(mixed $value, string $registryKey): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw self::invalid($registryKey);
        }
        $result = [];
        foreach ($value as $segment) {
            if (!is_string($segment)
                || ($segment !== '*'
                    && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $segment) !== 1)
            ) {
                throw self::invalid($registryKey);
            }
            $result[] = $segment;
        }
        return $result;
    }

    /** @return list<string> */
    private static function identifierList(mixed $value, string $registryKey): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw self::invalid($registryKey);
        }
        $result = [];
        $seen = [];
        foreach ($value as $identifier) {
            if (!is_string($identifier)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $identifier) !== 1
                || isset($seen[$identifier])
            ) {
                throw self::invalid($registryKey);
            }
            $seen[$identifier] = true;
            $result[] = $identifier;
        }
        return $result;
    }

    private static function invalid(
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_embedded_reference_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
