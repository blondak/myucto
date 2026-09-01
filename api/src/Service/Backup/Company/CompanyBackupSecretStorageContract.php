<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jediný parser at-rest formátu přenositelného sloupcového secretu. */
final readonly class CompanyBackupSecretStorageContract
{
    private function __construct(
        public CompanyBackupSecretStorage $storage,
        public ?string $context,
        public ?CompanyBackupSecretContextTemplate $contextTemplate,
    ) {}

    public static function fromMetadata(
        mixed $metadata,
        string $registryKey,
        string $column,
    ): self {
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw self::error('secret_source_storage_missing', $registryKey, $column);
        }
        $storageValue = $metadata['storage'] ?? null;
        $storage = is_string($storageValue)
            ? CompanyBackupSecretStorage::tryFrom($storageValue)
            : null;
        if ($storage === null) {
            throw self::error('secret_source_storage_missing', $registryKey, $column);
        }

        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        $context = $metadata['context'] ?? null;
        $contextTemplate = null;
        if ($storage === CompanyBackupSecretStorage::ApplicationEncryptedContext) {
            if ($keys !== ['context', 'policy', 'storage']
                || !is_string($context)
            ) {
                throw self::error('secret_source_storage_invalid', $registryKey, $column);
            }
            $contextTemplate = CompanyBackupSecretContextTemplate::fromString(
                $context,
                $registryKey,
                $column,
            );
        } elseif ($keys !== ['policy', 'storage'] || $context !== null) {
            throw self::error('secret_source_storage_invalid', $registryKey, $column);
        }
        return new self(
            $storage,
            is_string($context) ? $context : null,
            $contextTemplate,
        );
    }

    private static function error(
        string $code,
        string $registryKey,
        string $column,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException($code, $registryKey, $column);
    }
}
