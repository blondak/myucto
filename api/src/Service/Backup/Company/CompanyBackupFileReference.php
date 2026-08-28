<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Jeden databázový vlastník cesty relativní k registrované souborové oblasti. */
final readonly class CompanyBackupFileReference
{
    public string $sourcePath;

    /** @var array<string,int|string> */
    public array $primaryKey;

    /** @var list<string> */
    public array $path;

    /**
     * @param array<mixed> $primaryKey
     * @param array<mixed> $path
     */
    public function __construct(
        string $sourcePath,
        public string $registryKey,
        array $primaryKey,
        public string $column,
        array $path = [],
    ) {
        if (!TenantDataDefinition::isValidKey($registryKey)
            || (!str_starts_with($registryKey, 'table:')
                && !str_starts_with($registryKey, 'logical:'))
            || !self::identifier($column)
            || $primaryKey === []
            || array_is_list($primaryKey)
            || count($primaryKey) > 16
            || !array_is_list($path)
            || count($path) > 32
        ) {
            throw new \InvalidArgumentException('Odkaz na soubor nemá platnou obálku.');
        }

        $normalizedKey = [];
        foreach ($primaryKey as $key => $value) {
            if (!is_string($key)
                || !self::identifier($key)
                || !self::keyValue($value)
            ) {
                throw new \InvalidArgumentException('Odkaz na soubor nemá platný primární klíč.');
            }
            $normalizedKey[$key] = $value;
        }
        ksort($normalizedKey, SORT_STRING);

        $normalizedPath = [];
        foreach ($path as $segment) {
            if (!is_string($segment) || !self::identifier($segment)) {
                throw new \InvalidArgumentException('Odkaz na soubor nemá platnou JSON cestu.');
            }
            $normalizedPath[] = $segment;
        }

        $this->sourcePath = CompanyBackupFileEntry::normalizeSourcePath($sourcePath);
        $this->primaryKey = $normalizedKey;
        $this->path = $normalizedPath;
    }

    /**
     * @return array{
     *   registry_key:string,
     *   primary_key:array<string,int|string>,
     *   column:string,
     *   path:list<string>
     * }
     */
    public function owner(): array
    {
        return [
            'registry_key' => $this->registryKey,
            'primary_key' => $this->primaryKey,
            'column' => $this->column,
            'path' => $this->path,
        ];
    }

    public function ownerSignature(): string
    {
        return $this->registryKey . ':' . CanonicalJson::encode($this->primaryKey)
            . ':' . $this->column . ':' . CanonicalJson::encode($this->path);
    }

    private static function identifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1;
    }

    private static function keyValue(mixed $value): bool
    {
        return is_int($value) && $value >= 0
            || is_string($value)
                && $value !== ''
                && strlen($value) <= 255
                && preg_match('//u', $value) === 1
                && !str_contains($value, "\0");
    }
}
