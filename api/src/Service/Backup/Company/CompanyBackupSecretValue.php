<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Jedna plaintext hodnota secretu s jednoznačným zdrojovým řádkem. */
final readonly class CompanyBackupSecretValue
{
    public const MAX_VALUE_BYTES = 16_777_216;

    /** @var array<string,int|string> */
    public array $primaryKey;

    /** @param array<string,int|string> $primaryKey */
    private function __construct(
        public string $registryKey,
        public CompanyBackupSecretScope $scope,
        public string $name,
        array $primaryKey,
        private string $value,
    ) {
        $this->primaryKey = $primaryKey;
    }

    /** @param array<mixed> $primaryKey */
    public static function fromPlaintext(
        string $registryKey,
        CompanyBackupSecretScope $scope,
        string $name,
        array $primaryKey,
        #[\SensitiveParameter] string $value,
    ): self {
        if (!TenantDataDefinition::isValidKey($registryKey)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1
            || $primaryKey === []
            || array_is_list($primaryKey)
            || count($primaryKey) > 16
            || $value === ''
            || strlen($value) > self::MAX_VALUE_BYTES
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

        return new self(
            $registryKey,
            $scope,
            $name,
            $normalizedKey,
            $value,
        );
    }

    /** @param list<string> $columns */
    public function assertPrimaryKeyColumns(array $columns): void
    {
        $expected = $columns;
        sort($expected, SORT_STRING);
        if ($expected !== array_keys($this->primaryKey)) {
            throw self::invalid();
        }
    }

    public function declarationSignature(): string
    {
        return $this->registryKey . ':' . $this->scope->value . ':' . $this->name;
    }

    public function primaryKeySignature(): string
    {
        return CanonicalJson::encode($this->primaryKey);
    }

    public function plaintext(): string
    {
        return $this->value;
    }

    /** @return array{primary_key:array<string,int|string>,value_base64:string} */
    public function toArray(): array
    {
        return [
            'primary_key' => $this->primaryKey,
            'value_base64' => base64_encode($this->value),
        ];
    }

    /** @param list<string> $primaryKeyColumns */
    public static function fromArray(
        mixed $value,
        string $registryKey,
        CompanyBackupSecretScope $scope,
        string $name,
        array $primaryKeyColumns,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid();
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $encoded = $value['value_base64'] ?? null;
        $primaryKey = $value['primary_key'] ?? null;
        if ($keys !== ['primary_key', 'value_base64']
            || !is_string($encoded)
            || !is_array($primaryKey)
            || array_is_list($primaryKey)
        ) {
            throw self::invalid();
        }
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded)
            || !hash_equals(base64_encode($decoded), $encoded)
        ) {
            throw self::invalid();
        }
        $result = self::fromPlaintext(
            $registryKey,
            $scope,
            $name,
            $primaryKey,
            $decoded,
        );
        $result->assertPrimaryKeyColumns($primaryKeyColumns);
        return $result;
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

    private static function invalid(): CompanyBackupSecretPayloadException
    {
        return new CompanyBackupSecretPayloadException('secret_payload_invalid');
    }
}
