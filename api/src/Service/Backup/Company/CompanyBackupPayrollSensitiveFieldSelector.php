<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/** Pevná nebo hodnotou DB sloupce diskriminovaná volba mzdového secret pole. */
final readonly class CompanyBackupPayrollSensitiveFieldSelector
{
    private const MAX_CASES = 32;

    /** @var array<string,PayrollSensitiveField> */
    private array $fieldsByValue;

    /** @param array<string,PayrollSensitiveField> $fieldsByValue */
    private function __construct(
        public ?PayrollSensitiveField $fixedField,
        public ?string $discriminatorColumn,
        array $fieldsByValue,
    ) {
        $this->fieldsByValue = $fieldsByValue;
    }

    public static function fromMetadata(mixed $metadata): self
    {
        if (is_string($metadata)) {
            $field = PayrollSensitiveField::tryFrom($metadata);
            if ($field === null) {
                throw new \InvalidArgumentException('Neznámé mzdové secret pole.');
            }
            return new self($field, null, []);
        }
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new \InvalidArgumentException('Neplatný selektor mzdového secret pole.');
        }
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        if ($keys !== ['cases', 'discriminator_column']) {
            throw new \InvalidArgumentException('Neplatný selektor mzdového secret pole.');
        }

        $discriminatorColumn = $metadata['discriminator_column'];
        $cases = $metadata['cases'];
        if (!is_string($discriminatorColumn)
            || !self::isIdentifier($discriminatorColumn)
            || !is_array($cases)
            || !array_is_list($cases)
            || $cases === []
            || count($cases) > self::MAX_CASES
        ) {
            throw new \InvalidArgumentException('Neplatný selektor mzdového secret pole.');
        }

        $fieldsByValue = [];
        $previousValue = null;
        foreach ($cases as $case) {
            if (!is_array($case) || array_is_list($case)) {
                throw new \InvalidArgumentException('Neplatný případ mzdového secret pole.');
            }
            $caseKeys = array_keys($case);
            sort($caseKeys, SORT_STRING);
            $value = $case['equals'] ?? null;
            $fieldValue = $case['field'] ?? null;
            $field = is_string($fieldValue)
                ? PayrollSensitiveField::tryFrom($fieldValue)
                : null;
            if ($caseKeys !== ['equals', 'field']
                || !is_string($value)
                || !self::isIdentifier($value)
                || $field === null
                || isset($fieldsByValue[$value])
                || $previousValue !== null
                    && strcmp($previousValue, $value) >= 0
            ) {
                throw new \InvalidArgumentException('Neplatný případ mzdového secret pole.');
            }
            $fieldsByValue[$value] = $field;
            $previousValue = $value;
        }

        return new self(null, $discriminatorColumn, $fieldsByValue);
    }

    /** @param array<string,mixed> $row */
    public function fieldFor(array $row): ?PayrollSensitiveField
    {
        if ($this->fixedField !== null) {
            return $this->fixedField;
        }
        $column = $this->discriminatorColumn;
        if ($column === null) {
            return null;
        }
        $value = $row[$column] ?? null;

        return is_string($value) ? ($this->fieldsByValue[$value] ?? null) : null;
    }

    public function signature(): string
    {
        if ($this->fixedField !== null) {
            return $this->fixedField->value;
        }
        $discriminatorColumn = $this->discriminatorColumn;
        if ($discriminatorColumn === null) {
            throw new \LogicException('Diskriminovaný selektor nemá sloupec.');
        }

        return '?'
            . $discriminatorColumn
            . '{'
            . implode(',', array_map(
                static fn (string $value, PayrollSensitiveField $field): string =>
                    $value . '=' . $field->value,
                array_keys($this->fieldsByValue),
                array_values($this->fieldsByValue),
            ))
            . '}';
    }

    public function equals(self $other): bool
    {
        return $this->signature() === $other->signature();
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1;
    }
}
