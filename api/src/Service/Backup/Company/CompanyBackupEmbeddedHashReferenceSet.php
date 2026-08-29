<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Úplná sada mezřádkových hashových referencí uvnitř JSON sloupců. */
final readonly class CompanyBackupEmbeddedHashReferenceSet
{
    /** @var list<CompanyBackupEmbeddedHashReference> */
    public array $references;

    /** @param list<CompanyBackupEmbeddedHashReference> $references */
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
            throw self::metadataError($registryKey);
        }
        $exported = array_fill_keys($dataColumns, true);
        $references = [];
        $claims = [];
        foreach ($metadata as $value) {
            $reference = CompanyBackupEmbeddedHashReference::fromArray(
                $value,
                $registryKey,
            );
            if (!isset($exported[$reference->column])) {
                throw new CompanyBackupDataSourceException(
                    'data_embedded_hash_reference_source_not_exported',
                    $registryKey,
                    $reference->column,
                );
            }
            $claim = $reference->column . ':' . implode('.', $reference->path);
            if (isset($claims[$claim])) {
                throw new CompanyBackupDataSourceException(
                    'data_embedded_hash_reference_duplicate',
                    $registryKey,
                    $reference->column,
                );
            }
            $claims[$claim] = true;
            $references[] = $reference;
        }
        $ordered = $references;
        usort(
            $ordered,
            static fn (
                CompanyBackupEmbeddedHashReference $left,
                CompanyBackupEmbeddedHashReference $right,
            ): int => strcmp($left->signature(), $right->signature()),
        );
        if ($ordered !== $references) {
            throw self::metadataError($registryKey);
        }
        return new self($registryKey, $references);
    }

    public function assertRegistryTargets(TenantDataRegistry $registry): void
    {
        foreach ($this->references as $reference) {
            $target = $registry->definition($reference->target);
            if ($target === null
                || !$target->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
                || !in_array(
                    $target->policy,
                    [
                        TenantDataPolicy::TenantRoot,
                        TenantDataPolicy::TenantOwned,
                        TenantDataPolicy::TenantOwnedIndirect,
                    ],
                    true,
                )
            ) {
                throw $this->targetError($reference);
            }
            try {
                $projection = CompanyBackupTableProjection::fromDefinition($target);
            } catch (CompanyBackupDataSourceException $e) {
                throw $this->targetError($reference, $e);
            }
            $declared = false;
            foreach ($projection->derivedHashes->hashes as $hash) {
                if ($hash->hashColumn === $reference->targetHashColumn) {
                    $declared = true;
                    break;
                }
            }
            if (!$declared) {
                throw $this->targetError($reference);
            }
        }
    }

    /** @param array<string,mixed> $row */
    public function assertSourceRow(array $row): void
    {
        $this->process($row, null);
    }

    /**
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupEmbeddedHashReference,string):mixed $mapper
     * @return array<string,mixed>
     */
    public function remap(array $row, callable $mapper): array
    {
        return $this->process($row, $mapper);
    }

    /**
     * @param array<string,mixed> $row
     * @param null|callable(CompanyBackupEmbeddedHashReference,string):mixed $mapper
     * @return array<string,mixed>
     */
    private function process(array $row, ?callable $mapper): array
    {
        /** @var array<string,list<CompanyBackupEmbeddedHashReference>> $byColumn */
        $byColumn = [];
        foreach ($this->references as $reference) {
            $byColumn[$reference->column][] = $reference;
        }
        foreach ($byColumn as $column => $references) {
            if (!array_key_exists($column, $row)) {
                throw $this->valueError($column);
            }
            $raw = $row[$column];
            if ($raw === null) {
                foreach ($references as $reference) {
                    if (!$reference->nullable) {
                        throw $this->valueError($column);
                    }
                }
                continue;
            }
            $encoded = is_string($raw);
            if ($encoded) {
                try {
                    $document = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($document)
                        || !hash_equals(CanonicalJson::encode($document), $raw)
                    ) {
                        throw new \UnexpectedValueException(
                            'Sloupec s hashovou referencí musí být kanonický JSON.',
                        );
                    }
                } catch (\Throwable $e) {
                    throw $this->valueError($column, $e);
                }
            } else {
                $document = $raw;
            }
            if (!is_array($document)) {
                throw $this->valueError($column);
            }
            foreach ($references as $reference) {
                $this->processPath(
                    $document,
                    $reference->path,
                    0,
                    $reference,
                    $mapper,
                );
            }
            $row[$column] = $encoded
                ? CanonicalJson::encode($document)
                : $document;
        }
        return $row;
    }

    /**
     * @param list<string> $path
     * @param null|callable(CompanyBackupEmbeddedHashReference,string):mixed $mapper
     */
    private function processPath(
        mixed &$value,
        array $path,
        int $index,
        CompanyBackupEmbeddedHashReference $reference,
        ?callable $mapper,
    ): void {
        if ($value === null) {
            if (!$reference->nullable) {
                throw $this->valueError($reference->column);
            }
            return;
        }
        if ($index === count($path)) {
            if (!self::validHash($value)) {
                throw $this->valueError($reference->column);
            }
            if ($mapper === null) {
                return;
            }
            $mapped = $mapper($reference, $value);
            if (!self::validHash($mapped)) {
                throw $this->valueError($reference->column);
            }
            $value = $mapped;
            return;
        }
        if (!is_array($value)) {
            throw $this->valueError($reference->column);
        }

        $segment = $path[$index];
        if ($segment === '*') {
            foreach ($value as &$item) {
                $this->processPath(
                    $item,
                    $path,
                    $index + 1,
                    $reference,
                    $mapper,
                );
            }
            unset($item);
            return;
        }
        if (!array_key_exists($segment, $value)) {
            return;
        }
        $this->processPath(
            $value[$segment],
            $path,
            $index + 1,
            $reference,
            $mapper,
        );
    }

    private static function validHash(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    private function targetError(
        CompanyBackupEmbeddedHashReference $reference,
        ?\Throwable $previous = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_embedded_hash_reference_target_invalid',
            $this->registryKey,
            $reference->column,
            $previous,
        );
    }

    private function valueError(
        string $column,
        ?\Throwable $previous = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_embedded_hash_reference_value_invalid',
            $this->registryKey,
            $column,
            $previous,
        );
    }

    private static function metadataError(
        string $registryKey,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_embedded_hash_reference_metadata_invalid',
            $registryKey,
        );
    }
}
