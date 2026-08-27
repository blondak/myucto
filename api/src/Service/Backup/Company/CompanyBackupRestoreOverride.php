<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jedna manifestem svázaná bezpečná hodnota vynucená při obnově. */
final readonly class CompanyBackupRestoreOverride
{
    private function __construct(
        public string|int|bool|null $value,
        public string $reason,
    ) {}

    public static function fromArray(
        mixed $metadata,
        string $registryKey,
        string $column,
    ): self {
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw self::invalid($registryKey, $column);
        }
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        if ($keys !== ['reason', 'value']) {
            throw self::invalid($registryKey, $column);
        }
        $value = $metadata['value'];
        $reason = $metadata['reason'];
        if ((!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null)
            || !is_string($reason)
            || preg_match('/^[a-z][a-z0-9._-]{2,127}$/D', $reason) !== 1
        ) {
            throw self::invalid($registryKey, $column);
        }
        return new self($value, $reason);
    }

    private static function invalid(
        string $registryKey,
        string $column,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_restore_override_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
