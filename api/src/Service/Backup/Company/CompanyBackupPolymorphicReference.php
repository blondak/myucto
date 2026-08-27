<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplný diskriminovaný kontrakt jednoho polymorfního ID sloupce. */
final readonly class CompanyBackupPolymorphicReference
{
    /** @var list<CompanyBackupPolymorphicReferenceCase> */
    public array $cases;

    /** @param list<CompanyBackupPolymorphicReferenceCase> $cases */
    private function __construct(
        public string $column,
        public string $discriminatorColumn,
        public bool $nullable,
        array $cases,
    ) {
        $this->cases = $cases;
    }

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['cases', 'column', 'discriminator_column', 'nullable']) {
            throw self::invalid($registryKey);
        }

        $column = $value['column'];
        $discriminator = $value['discriminator_column'];
        $nullable = $value['nullable'];
        $caseValues = $value['cases'];
        if (!is_string($column)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
            || !is_string($discriminator)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $discriminator) !== 1
            || $column === $discriminator
            || !is_bool($nullable)
            || !is_array($caseValues)
            || !array_is_list($caseValues)
            || $caseValues === []
        ) {
            throw self::invalid($registryKey, is_string($column) ? $column : null);
        }

        $cases = [];
        $seen = [];
        foreach ($caseValues as $caseValue) {
            $case = CompanyBackupPolymorphicReferenceCase::fromArray(
                $caseValue,
                $registryKey,
                $column,
            );
            if (isset($seen[$case->equals])) {
                throw self::invalid($registryKey, $column);
            }
            $seen[$case->equals] = true;
            $cases[] = $case;
        }
        $ordered = $cases;
        usort(
            $ordered,
            static fn (
                CompanyBackupPolymorphicReferenceCase $left,
                CompanyBackupPolymorphicReferenceCase $right,
            ): int => strcmp($left->equals, $right->equals),
        );
        if ($ordered !== $cases) {
            throw self::invalid($registryKey, $column);
        }

        return new self($column, $discriminator, $nullable, $cases);
    }

    public function signature(): string
    {
        return $this->column
            . '?'
            . $this->discriminatorColumn
            . '{'
            . implode(',', array_map(
                static fn (CompanyBackupPolymorphicReferenceCase $case): string =>
                    $case->signature(),
                $this->cases,
            ))
            . '}';
    }

    public function caseFor(string $value): ?CompanyBackupPolymorphicReferenceCase
    {
        foreach ($this->cases as $case) {
            if ($case->equals === $value) {
                return $case;
            }
        }
        return null;
    }

    private static function invalid(
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_polymorphic_reference_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
