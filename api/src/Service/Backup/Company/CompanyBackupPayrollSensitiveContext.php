<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** AAD mzdového secretu včetně diskriminované volby doménového pole. */
final readonly class CompanyBackupPayrollSensitiveContext
{
    private function __construct(
        public string $tenantIdColumn,
        public string $entityIdColumn,
        public CompanyBackupPayrollSensitiveFieldSelector $fieldSelector,
    ) {}

    public static function fromMetadata(mixed $metadata): self
    {
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new \InvalidArgumentException('Neplatný payroll AAD kontrakt.');
        }
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'entity_id_column',
            'field',
            'scheme',
            'tenant_id_column',
        ]) {
            throw new \InvalidArgumentException('Neplatný payroll AAD kontrakt.');
        }

        $tenantIdColumn = $metadata['tenant_id_column'];
        $entityIdColumn = $metadata['entity_id_column'];
        $scheme = $metadata['scheme'];
        if (!is_string($tenantIdColumn)
            || !self::isIdentifier($tenantIdColumn)
            || !is_string($entityIdColumn)
            || !self::isIdentifier($entityIdColumn)
            || $tenantIdColumn === $entityIdColumn
            || $scheme !== CompanyBackupProtectedSecretMaterializer::PayrollSensitiveV1->value
        ) {
            throw new \InvalidArgumentException('Neplatný payroll AAD kontrakt.');
        }
        $fieldSelector = CompanyBackupPayrollSensitiveFieldSelector::fromMetadata(
            $metadata['field'],
        );
        if (in_array(
            $fieldSelector->discriminatorColumn,
            [$tenantIdColumn, $entityIdColumn],
            true,
        )) {
            throw new \InvalidArgumentException('Neplatný payroll AAD kontrakt.');
        }

        return new self($tenantIdColumn, $entityIdColumn, $fieldSelector);
    }

    /**
     * @param list<string> $primaryKey
     * @param list<string> $dataColumns
     */
    public function hasValidCoordinates(
        array $primaryKey,
        string $ownershipColumn,
        array $dataColumns,
    ): bool {
        if ($this->tenantIdColumn !== $ownershipColumn
            || !in_array($this->entityIdColumn, $primaryKey, true)
        ) {
            return false;
        }
        foreach ($this->columns() as $column) {
            if (!in_array($column, $dataColumns, true)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    public function columns(): array
    {
        $columns = [
            $this->tenantIdColumn,
            $this->entityIdColumn,
        ];
        $discriminatorColumn = $this->fieldSelector->discriminatorColumn;
        if ($discriminatorColumn !== null) {
            $columns[] = $discriminatorColumn;
        }
        return array_values(array_unique($columns));
    }

    /** @param array<string,mixed> $row */
    public function resolve(array $row): string
    {
        $tenantId = $row[$this->tenantIdColumn] ?? null;
        $entityId = $row[$this->entityIdColumn] ?? null;
        $field = $this->fieldSelector->fieldFor($row);
        if (!is_int($tenantId) || $tenantId < 1
            || !is_int($entityId) || $entityId < 1
            || $field === null
        ) {
            throw new \InvalidArgumentException('Payroll AAD nelze odvodit z řádku.');
        }

        return 'payroll:'
            . $tenantId
            . ':'
            . $entityId
            . ':'
            . $field->value;
    }

    public function matches(
        string $tenantIdColumn,
        string $entityIdColumn,
        CompanyBackupPayrollSensitiveFieldSelector $fieldSelector,
    ): bool {
        return $this->tenantIdColumn === $tenantIdColumn
            && $this->entityIdColumn === $entityIdColumn
            && $this->fieldSelector->equals($fieldSelector);
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1;
    }
}
