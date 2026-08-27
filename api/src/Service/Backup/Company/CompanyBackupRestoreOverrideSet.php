<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Volatelná politika bezpečných hodnot aplikovaných před zápisem obnoveného řádku. */
final readonly class CompanyBackupRestoreOverrideSet
{
    /** @var array<string,CompanyBackupRestoreOverride> */
    public array $overrides;

    /** @param array<string,CompanyBackupRestoreOverride> $overrides */
    private function __construct(
        public string $registryKey,
        array $overrides,
    ) {
        $this->overrides = $overrides;
    }

    /**
     * @param list<string> $dataColumns
     * @param list<string> $primaryKey
     */
    public static function fromArray(
        mixed $metadata,
        string $registryKey,
        array $dataColumns,
        array $primaryKey,
        CompanyBackupReferenceSet $references,
    ): self {
        if (!is_array($metadata) || array_is_list($metadata) && $metadata !== []) {
            throw new CompanyBackupDataSourceException(
                'data_restore_override_metadata_invalid',
                $registryKey,
            );
        }
        $exported = array_fill_keys($dataColumns, true);
        $protected = array_fill_keys($primaryKey, true);
        foreach ($references->references as $reference) {
            foreach ($reference->columns as $column) {
                $protected[$column] = true;
            }
        }

        $overrides = [];
        foreach ($metadata as $column => $overrideMetadata) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || !isset($exported[$column])
                || isset($protected[$column])
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_restore_override_column_invalid',
                    $registryKey,
                    is_string($column) ? $column : null,
                );
            }
            $overrides[$column] = CompanyBackupRestoreOverride::fromArray(
                $overrideMetadata,
                $registryKey,
                $column,
            );
        }
        ksort($overrides, SORT_STRING);
        return new self($registryKey, $overrides);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function apply(array $row): array
    {
        foreach ($this->overrides as $column => $override) {
            if (!array_key_exists($column, $row)) {
                throw new CompanyBackupDataSourceException(
                    'data_restore_override_column_missing',
                    $this->registryKey,
                    $column,
                );
            }
            $row[$column] = $override->value;
        }
        return $row;
    }
}
