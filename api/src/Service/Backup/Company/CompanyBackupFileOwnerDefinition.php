<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Registrované umístění jedné souborové reference v řádku vlastníka. */
final readonly class CompanyBackupFileOwnerDefinition
{
    /** @var list<string> */
    public array $path;

    /** @param list<string> $path */
    private function __construct(
        public string $registryKey,
        public string $column,
        array $path,
        public string $storedPrefix,
    ) {
        $this->path = $path;
    }

    public static function fromArray(mixed $value, string $areaRegistryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($areaRegistryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['column', 'path', 'registry_key', 'stored_prefix']) {
            throw self::invalid($areaRegistryKey);
        }

        $registryKey = $value['registry_key'];
        $column = $value['column'];
        $path = self::path($value['path'], $areaRegistryKey);
        $storedPrefix = $value['stored_prefix'];
        if (!is_string($registryKey)
            || !TenantDataDefinition::isValidKey($registryKey)
            || (!str_starts_with($registryKey, 'table:')
                && !str_starts_with($registryKey, 'logical:'))
            || !is_string($column)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
            || !is_string($storedPrefix)
            || !self::validStoredPrefix($storedPrefix)
        ) {
            throw self::invalid($areaRegistryKey);
        }
        return new self($registryKey, $column, $path, $storedPrefix);
    }

    public function signature(): string
    {
        return self::signatureFor($this->registryKey, $this->column, $this->path);
    }

    /** @param list<string> $path */
    public static function signatureFor(
        string $registryKey,
        string $column,
        array $path,
    ): string {
        return $registryKey . ':' . $column . ':' . CanonicalJson::encode($path);
    }

    public function relativeSourcePath(mixed $storedPath): string
    {
        if (!is_string($storedPath)
            || !str_starts_with($storedPath, $this->storedPrefix)
        ) {
            throw new \InvalidArgumentException('Uložená cesta nemá registrovaný prefix.');
        }
        return CompanyBackupFileEntry::normalizeSourcePath(
            substr($storedPath, strlen($this->storedPrefix)),
        );
    }

    /** @return list<string> */
    private static function path(mixed $value, string $areaRegistryKey): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 32) {
            throw self::invalid($areaRegistryKey);
        }
        $path = [];
        foreach ($value as $segment) {
            if (!is_string($segment)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $segment) !== 1
            ) {
                throw self::invalid($areaRegistryKey);
            }
            $path[] = $segment;
        }
        return $path;
    }

    private static function validStoredPrefix(string $value): bool
    {
        if ($value === ''
            || strlen($value) > 1_024
            || !str_ends_with($value, '/')
            || str_starts_with($value, '/')
            || str_contains($value, '\\')
            || str_contains($value, "\0")
            || preg_match('/\A[A-Za-z]:/', $value) === 1
        ) {
            return false;
        }
        foreach (explode('/', substr($value, 0, -1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    private static function invalid(string $areaRegistryKey): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            'Souborová oblast ' . $areaRegistryKey . ' má neplatného vlastníka.',
        );
    }
}
