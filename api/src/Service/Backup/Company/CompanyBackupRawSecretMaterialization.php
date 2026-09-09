<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Přesný bajtový přenos doménového klíče, který aplikace ukládá jako raw. */
final readonly class CompanyBackupRawSecretMaterialization
{
    /** @var array{value:string} */
    public array $targetColumns;

    private function __construct(
        public string $registryKey,
        public string $secretColumn,
        public string $tenantIdColumn,
        public bool $nullable,
        public int $bytes,
    ) {
        $this->targetColumns = ['value' => $secretColumn];
    }

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['bytes', 'materializer', 'nullable', 'secret_column', 'tenant_id_column']
            || $value['materializer'] !== 'raw_bytes_v1'
            || !is_bool($value['nullable'])
            || !is_int($value['bytes']) || $value['bytes'] < 1 || $value['bytes'] > 4096
        ) {
            throw self::invalid($registryKey);
        }
        foreach (['secret_column', 'tenant_id_column'] as $key) {
            if (!is_string($value[$key]) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value[$key]) !== 1) {
                throw self::invalid($registryKey);
            }
        }
        if ($value['secret_column'] === $value['tenant_id_column']) {
            throw self::invalid($registryKey);
        }
        return new self($registryKey, $value['secret_column'], $value['tenant_id_column'],
            $value['nullable'], $value['bytes']);
    }

    public function signature(): string
    {
        return $this->secretColumn . ($this->nullable ? '?' : '')
            . '<-raw_bytes_v1:' . $this->bytes . '@' . $this->tenantIdColumn;
    }

    /** @param array<string,mixed> $targetRow
     *  @return array<string,string|null>
     */
    public function materialize(?CompanyBackupSecretValue $value, array $targetRow): array
    {
        if ($value !== null) {
            $this->assertValue($value);
        }
        $tenant = $targetRow[$this->tenantIdColumn] ?? null;
        if (!is_int($tenant) || $tenant < 1
            || array_key_exists($this->secretColumn, $targetRow)
            || ($value === null && !$this->nullable)
        ) {
            throw new CompanyBackupDataSourceException('secret_restore_value_invalid',
                $this->registryKey, $this->secretColumn);
        }
        return [$this->secretColumn => $value?->plaintext()];
    }

    public function assertValue(CompanyBackupSecretValue $value): void
    {
        if ($value->registryKey !== $this->registryKey || $value->name !== $this->secretColumn
            || $value->scope !== CompanyBackupSecretScope::Column
            || strlen($value->plaintext()) !== $this->bytes) {
            throw new CompanyBackupDataSourceException('secret_restore_value_invalid',
                $this->registryKey, $this->secretColumn);
        }
    }

    private static function invalid(string $registryKey): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException('data_protected_materialization_invalid', $registryKey);
    }
}
