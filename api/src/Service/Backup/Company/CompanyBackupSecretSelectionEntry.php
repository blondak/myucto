<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Jedna registry deklarace a zdrojový řádek výslovně vybraného credentialu. */
final readonly class CompanyBackupSecretSelectionEntry
{
    /** @var array<string,int|string> */
    public array $primaryKey;

    /** @param array<string,int|string> $primaryKey */
    private function __construct(
        public string $registryKey,
        public CompanyBackupSecretScope $scope,
        public string $name,
        public TenantSecretPolicy $policy,
        array $primaryKey,
    ) {
        $this->primaryKey = $primaryKey;
    }

    /**
     * @param list<string> $primaryKeyColumns
     */
    public static function fromArray(
        mixed $value,
        TenantSecretPolicy $policy,
        array $primaryKeyColumns,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid();
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $registryKey = $value['registry_key'] ?? null;
        $scopeValue = $value['scope'] ?? null;
        $name = $value['name'] ?? null;
        $primaryKey = $value['primary_key'] ?? null;
        $scope = is_string($scopeValue)
            ? CompanyBackupSecretScope::tryFrom($scopeValue)
            : null;
        if ($keys !== ['name', 'primary_key', 'registry_key', 'scope']
            || !is_string($registryKey)
            || !TenantDataDefinition::isValidKey($registryKey)
            || $scope === null
            || !is_string($name)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1
            || !is_array($primaryKey)
            || array_is_list($primaryKey)
            || count($primaryKey) > 16
        ) {
            throw self::invalid();
        }

        $normalizedKey = [];
        foreach ($primaryKey as $column => $item) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || !self::validKeyValue($item)
            ) {
                throw self::invalid();
            }
            $normalizedKey[$column] = $item;
        }
        ksort($normalizedKey, SORT_STRING);
        $expectedColumns = $primaryKeyColumns;
        sort($expectedColumns, SORT_STRING);
        if (array_keys($normalizedKey) !== $expectedColumns) {
            throw self::invalid();
        }

        return new self(
            $registryKey,
            $scope,
            $name,
            $policy,
            $normalizedKey,
        );
    }

    public function declarationSignature(): string
    {
        return $this->registryKey . ':' . $this->scope->value . ':' . $this->name;
    }

    public function valueSignature(): string
    {
        return $this->declarationSignature() . ':'
            . CanonicalJson::encode($this->primaryKey);
    }

    /** @return array{registry_key:string,scope:string,name:string,primary_key:array<string,int|string>} */
    public function toArray(): array
    {
        return [
            'registry_key' => $this->registryKey,
            'scope' => $this->scope->value,
            'name' => $this->name,
            'primary_key' => $this->primaryKey,
        ];
    }

    private static function validKeyValue(mixed $value): bool
    {
        return is_int($value) && $value >= 0
            || is_string($value)
                && $value !== ''
                && strlen($value) <= 255
                && preg_match('//u', $value) === 1
                && !str_contains($value, "\0");
    }

    private static function invalid(): CompanyBackupSecretSelectionException
    {
        return new CompanyBackupSecretSelectionException(
            'secret_selection_primary_key_invalid',
        );
    }
}
