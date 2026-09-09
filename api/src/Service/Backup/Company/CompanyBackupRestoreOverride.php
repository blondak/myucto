<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jedna manifestem svázaná bezpečná hodnota vynucená při obnově. */
final readonly class CompanyBackupRestoreOverride
{
    private function __construct(
        public string|int|bool|null $value,
        public string $reason,
        public ?string $whenColumn = null,
        /** @var list<string|int|bool|null> */
        public array $whenValues = [],
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
        if ($keys !== ['reason', 'value'] && $keys !== ['reason', 'value', 'when']) {
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
        $whenColumn = null;
        $whenValues = [];
        if (array_key_exists('when', $metadata)) {
            $when = $metadata['when'];
            if (!is_array($when) || array_is_list($when)) {
                throw self::invalid($registryKey, $column);
            }
            $whenKeys = array_keys($when);
            sort($whenKeys, SORT_STRING);
            if ($whenKeys !== ['column', 'values']
                || !is_string($when['column'])
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $when['column']) !== 1
                || !is_array($when['values']) || !array_is_list($when['values'])
                || $when['values'] === [] || count($when['values']) > 32) {
                throw self::invalid($registryKey, $column);
            }
            foreach ($when['values'] as $candidate) {
                if ((!is_string($candidate) && !is_int($candidate) && !is_bool($candidate) && $candidate !== null)
                    || in_array($candidate, $whenValues, true)) {
                    throw self::invalid($registryKey, $column);
                }
                $whenValues[] = $candidate;
            }
            $whenColumn = $when['column'];
        }
        return new self($value, $reason, $whenColumn, $whenValues);
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
