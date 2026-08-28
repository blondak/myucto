<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Fingerprintovaný, reverzibilní převod hodnoty DB sloupce pro JSONL. */
enum CompanyBackupColumnCodec: string
{
    case BinaryHex = 'binary_hex';

    public function encode(mixed $value, string $registryKey, string $column): mixed
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new CompanyBackupDataSourceException(
                'data_column_codec_value_invalid',
                $registryKey,
                $column,
            );
        }
        return match ($this) {
            self::BinaryHex => bin2hex($value),
        };
    }

    public function decode(mixed $value, string $registryKey, string $column): mixed
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || preg_match('/^(?:[0-9a-f]{2})*$/D', $value) !== 1
        ) {
            throw new CompanyBackupDataSourceException(
                'data_column_codec_payload_invalid',
                $registryKey,
                $column,
            );
        }
        $decoded = match ($this) {
            self::BinaryHex => hex2bin($value),
        };
        if (!is_string($decoded)) {
            throw new CompanyBackupDataSourceException(
                'data_column_codec_payload_invalid',
                $registryKey,
                $column,
            );
        }
        return $decoded;
    }
}
