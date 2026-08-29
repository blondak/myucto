<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Úplná sada remap politik pro reference uvnitř JSON a obdobných sloupců. */
final readonly class CompanyBackupEmbeddedReferenceSet
{
    /** @var list<CompanyBackupEmbeddedReference> */
    public array $references;

    /** @param list<CompanyBackupEmbeddedReference> $references */
    private function __construct(
        public string $registryKey,
        array $references,
    ) {
        $this->references = $references;
    }

    /** @param list<string> $dataColumns */
    public static function fromArray(
        mixed $metadata,
        string $registryKey,
        array $dataColumns,
    ): self {
        if (!is_array($metadata) || !array_is_list($metadata)) {
            throw new CompanyBackupDataSourceException(
                'data_embedded_reference_metadata_invalid',
                $registryKey,
            );
        }
        $exported = array_fill_keys($dataColumns, true);
        $references = [];
        $signatures = [];
        /** @var array<string,list<CompanyBackupEmbeddedReference>> $paths */
        $paths = [];
        /** @var array<string,bool> $documents */
        $documents = [];
        foreach ($metadata as $item) {
            $reference = CompanyBackupEmbeddedReference::fromArray($item, $registryKey);
            if (!isset($exported[$reference->column])) {
                throw new CompanyBackupDataSourceException(
                    'data_embedded_reference_source_not_exported',
                    $registryKey,
                    $reference->column,
                );
            }
            $signature = $reference->signature();
            $document = $reference->column
                . ':'
                . implode('.', $reference->documentPath);
            if (isset($documents[$document])
                && $documents[$document] !== $reference->documentNullable
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_embedded_reference_metadata_invalid',
                    $registryKey,
                    $reference->column,
                );
            }
            $documents[$document] = $reference->documentNullable;
            $path = $document . '::' . implode('.', $reference->path);
            if (isset($signatures[$signature])) {
                throw new CompanyBackupDataSourceException(
                    'data_embedded_reference_duplicate',
                    $registryKey,
                    $reference->column,
                );
            }
            $signatures[$signature] = true;
            $paths[$path][] = $reference;
            $references[] = $reference;
        }
        foreach ($paths as $claims) {
            if (count($claims) < 2) {
                continue;
            }
            $conditions = [];
            foreach ($claims as $claim) {
                if ($claim->condition === null
                    || isset($conditions[$claim->condition->signature()])
                ) {
                    throw new CompanyBackupDataSourceException(
                        'data_embedded_reference_duplicate',
                        $registryKey,
                        $claim->column,
                    );
                }
                $conditions[$claim->condition->signature()] = true;
            }
        }
        $ordered = $references;
        usort(
            $ordered,
            static fn (
                CompanyBackupEmbeddedReference $left,
                CompanyBackupEmbeddedReference $right,
            ): int => strcmp($left->signature(), $right->signature()),
        );
        if ($ordered !== $references) {
            throw new CompanyBackupDataSourceException(
                'data_embedded_reference_metadata_invalid',
                $registryKey,
            );
        }

        return new self($registryKey, $references);
    }

    public function assertRegistryTargets(TenantDataRegistry $registry): void
    {
        foreach ($this->references as $reference) {
            $target = $registry->definition($reference->target);
            if ($target === null
                || !$target->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
                || $target->policy === TenantDataPolicy::Unsupported
            ) {
                throw $this->targetError($reference);
            }
            $primaryKey = $this->targetKey($target, 'primary_key', $reference);
            $naturalKey = $this->targetKey(
                $target,
                'natural_key',
                $reference,
                required: false,
            );
            $targetsExpectedKey = match ($reference->mapping) {
                CompanyBackupReferenceMapping::TenantNaturalKey,
                CompanyBackupReferenceMapping::GlobalNaturalKey =>
                    $naturalKey !== null && $reference->targetColumns === $naturalKey,
                CompanyBackupReferenceMapping::TenantId,
                CompanyBackupReferenceMapping::TenantIdOrZero,
                CompanyBackupReferenceMapping::Actor,
                CompanyBackupReferenceMapping::CredentialDecision =>
                    $reference->targetColumns === $primaryKey,
                CompanyBackupReferenceMapping::TenantReferenceKey => false,
            };
            $validPolicy = match ($reference->mapping) {
                CompanyBackupReferenceMapping::TenantId,
                CompanyBackupReferenceMapping::TenantIdOrZero,
                CompanyBackupReferenceMapping::TenantNaturalKey => in_array(
                    $target->policy,
                    [
                        TenantDataPolicy::TenantRoot,
                        TenantDataPolicy::TenantOwned,
                        TenantDataPolicy::TenantOwnedIndirect,
                    ],
                    true,
                ),
                CompanyBackupReferenceMapping::Actor =>
                    $target->policy === TenantDataPolicy::InstanceOwned,
                CompanyBackupReferenceMapping::GlobalNaturalKey =>
                    $target->policy === TenantDataPolicy::GlobalReference,
                CompanyBackupReferenceMapping::CredentialDecision,
                CompanyBackupReferenceMapping::TenantReferenceKey => false,
            };
            if (!$targetsExpectedKey || !$validPolicy) {
                throw $this->targetError($reference);
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupEmbeddedReference,int|string):(int|string|null) $mapper
     * @return array<string,mixed>
     */
    public function remap(array $row, callable $mapper): array
    {
        /**
         * @var array<string,array<string,array{
         *   path:list<string>,
         *   nullable:bool,
         *   groups:array<string,list<CompanyBackupEmbeddedReference>>
         * }>> $byColumn
         */
        $byColumn = [];
        foreach ($this->references as $reference) {
            $document = implode('.', $reference->documentPath);
            $path = implode('.', $reference->path);
            $byColumn[$reference->column][$document] ??= [
                'path' => $reference->documentPath,
                'nullable' => $reference->documentNullable,
                'groups' => [],
            ];
            $byColumn[$reference->column][$document]['groups'][$path][] = $reference;
        }
        foreach ($byColumn as $column => $documents) {
            if (!array_key_exists($column, $row)) {
                throw $this->valueError($column);
            }
            $raw = $row[$column];
            if ($raw === null) {
                continue;
            }
            $encoded = is_string($raw);
            if ($encoded) {
                try {
                    $value = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw $this->valueError($column, $e);
                }
            } else {
                $value = $raw;
            }
            if (!is_array($value)) {
                throw $this->valueError($column);
            }
            foreach ($documents as $document) {
                if ($document['path'] === []) {
                    $this->remapGroups($value, $document['groups'], $mapper);
                    continue;
                }
                $this->remapDocumentPath(
                    $value,
                    $document['path'],
                    0,
                    $document['nullable'],
                    $document['groups'],
                    $column,
                    $mapper,
                );
            }
            if ($encoded) {
                try {
                    $row[$column] = CanonicalJson::encode($value);
                } catch (\Throwable $e) {
                    throw $this->valueError($column, $e);
                }
            } else {
                $row[$column] = $value;
            }
        }

        return $row;
    }

    /**
     * @param array<mixed> $value
     * @param array<string,list<CompanyBackupEmbeddedReference>> $groups
     * @param callable(CompanyBackupEmbeddedReference,int|string):(int|string|null) $mapper
     */
    private function remapGroups(
        array &$value,
        array $groups,
        callable $mapper,
    ): void {
        $root =& $value;
        foreach ($groups as $references) {
            $this->remapPath($value, $root, $references, 0, [], $mapper);
        }
    }

    /**
     * @param list<string> $documentPath
     * @param array<string,list<CompanyBackupEmbeddedReference>> $groups
     * @param callable(CompanyBackupEmbeddedReference,int|string):(int|string|null) $mapper
     */
    private function remapDocumentPath(
        mixed &$value,
        array $documentPath,
        int $index,
        bool $nullable,
        array $groups,
        string $column,
        callable $mapper,
    ): void {
        if ($value === null && $nullable) {
            return;
        }
        if ($index === count($documentPath)) {
            if (!is_string($value)) {
                throw $this->valueError($column);
            }
            try {
                $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new \UnexpectedValueException(
                        'Vnořený JSON dokument musí být objekt nebo seznam.',
                    );
                }
                $canonical = CanonicalJson::encode($decoded);
                if (!hash_equals($canonical, $value)) {
                    throw new \UnexpectedValueException(
                        'Vnořený JSON dokument není kanonický.',
                    );
                }
                $this->remapGroups($decoded, $groups, $mapper);
                $value = CanonicalJson::encode($decoded);
                return;
            } catch (CompanyBackupDataSourceException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw $this->valueError($column, $e);
            }
        }
        if (!is_array($value)) {
            throw $this->valueError($column);
        }

        $segment = $documentPath[$index];
        if ($segment === '*') {
            foreach ($value as &$item) {
                $this->remapDocumentPath(
                    $item,
                    $documentPath,
                    $index + 1,
                    $nullable,
                    $groups,
                    $column,
                    $mapper,
                );
            }
            unset($item);
            return;
        }
        if (!array_key_exists($segment, $value)) {
            return;
        }
        $this->remapDocumentPath(
            $value[$segment],
            $documentPath,
            $index + 1,
            $nullable,
            $groups,
            $column,
            $mapper,
        );
    }

    /**
     * @param array<mixed> $root
     * @param list<CompanyBackupEmbeddedReference> $references
     * @param list<int|string> $wildcardBindings
     * @param callable(CompanyBackupEmbeddedReference,int|string):(int|string|null) $mapper
     */
    private function remapPath(
        mixed &$value,
        array &$root,
        array $references,
        int $index,
        array $wildcardBindings,
        callable $mapper,
    ): void {
        $prototype = $references[0];
        if ($index === count($prototype->path)) {
            if ($value === null) {
                foreach ($references as $reference) {
                    if (!$reference->nullable) {
                        throw $this->valueError($reference->column);
                    }
                }
                return;
            }
            $matches = array_values(array_filter(
                $references,
                fn (CompanyBackupEmbeddedReference $reference): bool =>
                    $reference->condition === null
                    || $this->conditionMatches(
                        $root,
                        $reference->condition,
                        $wildcardBindings,
                    ),
            ));
            if (count($matches) !== 1) {
                throw $this->valueError($prototype->column);
            }
            $reference = $matches[0];
            $source = $this->sourceValue($value, $reference);
            if ($source === null) {
                throw $this->valueError($reference->column);
            }
            if ($reference->mapping === CompanyBackupReferenceMapping::TenantIdOrZero
                && $source['identifier'] === 0
            ) {
                return;
            }
            $mapped = $mapper($reference, $source['identifier']);
            if (!$this->validMappedValue($mapped, $reference)) {
                throw $this->valueError($reference->column);
            }
            $value = $mapped === null || $reference->valuePrefix === null
                ? $mapped
                : $reference->valuePrefix . $mapped . $source['suffix'];
            return;
        }
        if (!is_array($value)) {
            throw $this->valueError($prototype->column);
        }

        $segment = $prototype->path[$index];
        if ($segment === '*') {
            foreach ($value as $key => &$item) {
                $bindings = [...$wildcardBindings, $key];
                $this->remapPath(
                    $item,
                    $root,
                    $references,
                    $index + 1,
                    $bindings,
                    $mapper,
                );
            }
            unset($item);
            return;
        }
        if (!array_key_exists($segment, $value)) {
            return;
        }
        $this->remapPath(
            $value[$segment],
            $root,
            $references,
            $index + 1,
            $wildcardBindings,
            $mapper,
        );
    }

    /**
     * @param array<mixed> $root
     * @param list<int|string> $wildcardBindings
     */
    private function conditionMatches(
        array $root,
        CompanyBackupEmbeddedReferenceCondition $condition,
        array $wildcardBindings,
    ): bool {
        $value = $root;
        $wildcard = 0;
        foreach ($condition->path as $segment) {
            $key = $segment;
            if ($segment === '*') {
                if (!array_key_exists($wildcard, $wildcardBindings)) {
                    return false;
                }
                $key = $wildcardBindings[$wildcard];
                $wildcard++;
            }
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }
        return $value === $condition->equals;
    }

    /** @return array{identifier:int|string,suffix:string}|null */
    private function sourceValue(
        mixed $value,
        CompanyBackupEmbeddedReference $reference,
    ): ?array {
        if ($reference->valuePrefix !== null) {
            if (!is_string($value)) {
                return null;
            }
            $digits = $reference->mapping
                === CompanyBackupReferenceMapping::TenantIdOrZero
                ? '(?:0|[1-9][0-9]*)'
                : '[1-9][0-9]*';
            $suffix = $reference->valueSuffixSeparator === null
                ? ''
                : '('
                    . preg_quote($reference->valueSuffixSeparator, '/')
                    . '[a-z0-9][a-z0-9_.-]{0,127})';
            if (preg_match(
                '/^' . preg_quote($reference->valuePrefix, '/')
                    . '(' . $digits . ')' . $suffix . '$/D',
                $value,
                $matches,
            ) !== 1) {
                return null;
            }
            $minimum = $reference->mapping
                === CompanyBackupReferenceMapping::TenantIdOrZero
                ? 0
                : 1;
            $identifier = filter_var(
                $matches[1],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => $minimum]],
            );
            return is_int($identifier)
                ? [
                    'identifier' => $identifier,
                    'suffix' => $matches[2] ?? '',
                ]
                : null;
        }
        return $this->validIdentifierValue($value, $reference)
            ? ['identifier' => $value, 'suffix' => '']
            : null;
    }

    private function validIdentifierValue(
        mixed $value,
        CompanyBackupEmbeddedReference $reference,
    ): bool {
        if (!is_int($value) && !is_string($value)) {
            return false;
        }
        if ($reference->mapping === CompanyBackupReferenceMapping::TenantId
            || $reference->mapping === CompanyBackupReferenceMapping::Actor
        ) {
            return is_int($value) && $value > 0;
        }
        if ($reference->mapping === CompanyBackupReferenceMapping::TenantIdOrZero) {
            return is_int($value) && $value >= 0;
        }
        return $value !== '';
    }

    private function validMappedValue(
        mixed $value,
        CompanyBackupEmbeddedReference $reference,
    ): bool {
        if ($value === null) {
            return in_array('null', $reference->fallbacks, true);
        }
        return $this->validIdentifierValue($value, $reference);
    }

    private function targetError(
        CompanyBackupEmbeddedReference $reference,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_embedded_reference_target_invalid',
            $this->registryKey,
            $reference->column,
        );
    }

    /** @return list<string>|null */
    private function targetKey(
        TenantDataDefinition $target,
        string $key,
        CompanyBackupEmbeddedReference $reference,
        bool $required = true,
    ): ?array {
        $value = $target->details[$key] ?? null;
        if (!$required && $value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw $this->targetError($reference);
        }
        $result = [];
        $seen = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($seen[$column])
            ) {
                throw $this->targetError($reference);
            }
            $seen[$column] = true;
            $result[] = $column;
        }
        return $result;
    }

    private function valueError(
        string $column,
        ?\Throwable $previous = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_embedded_reference_value_invalid',
            $this->registryKey,
            $column,
            $previous,
        );
    }
}
