<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Jedno explicitní pole kanonické projekce pro vnořenou pečeť. */
final readonly class CompanyBackupEmbeddedHashProjectionField
{
    /** @var list<string> */
    public array $path;

    /** @param list<string> $path */
    private function __construct(
        public string $key,
        public bool $hasLiteral,
        public mixed $literal,
        array $path,
    ) {
        $this->path = $path;
    }

    public static function fromArray(
        mixed $value,
        string $registryKey,
        string $column,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey, $column);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['key', 'literal'] && $keys !== ['key', 'path']) {
            throw self::invalid($registryKey, $column);
        }
        $key = $value['key'];
        if (!is_string($key)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
        ) {
            throw self::invalid($registryKey, $column);
        }
        $hasLiteral = array_key_exists('literal', $value);
        if ($hasLiteral) {
            $literal = $value['literal'];
            if (!is_string($literal)
                && !is_int($literal)
                && !is_bool($literal)
                && $literal !== null
            ) {
                throw self::invalid($registryKey, $column);
            }
            return new self($key, true, $literal, []);
        }

        $pathValue = $value['path'];
        if (!is_array($pathValue)
            || !array_is_list($pathValue)
            || $pathValue === []
        ) {
            throw self::invalid($registryKey, $column);
        }
        $path = [];
        foreach ($pathValue as $segment) {
            if (!is_string($segment)
                || ($segment !== '*'
                    && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $segment) !== 1)
            ) {
                throw self::invalid($registryKey, $column);
            }
            $path[] = $segment;
        }
        return new self($key, false, null, $path);
    }

    public function signature(): string
    {
        return $this->hasLiteral
            ? $this->key . '=literal:' . CanonicalJson::encode($this->literal)
            : $this->key . '=path:' . implode('.', $this->path);
    }

    private static function invalid(
        string $registryKey,
        string $column,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_embedded_hash_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
