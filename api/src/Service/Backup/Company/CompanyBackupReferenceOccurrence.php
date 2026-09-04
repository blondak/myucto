<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jedna normalizovaná zdrojová reference nalezená v ověřeném JSONL řádku. */
final readonly class CompanyBackupReferenceOccurrence
{
    public const KIND_COLUMN = 'column';
    public const KIND_ENCODED = 'encoded';
    public const KIND_EMBEDDED = 'embedded';
    public const KIND_POLYMORPHIC = 'polymorphic';

    /** @var array<string,int|string> */
    public array $sourceKey;

    /** @var list<string> */
    public array $fallbacks;

    /**
     * @param array<string,int|string> $sourceKey
     * @param list<string> $fallbacks
     */
    private function __construct(
        public string $sourceRegistryKey,
        public string $sourceColumn,
        public string $sourceKind,
        public string $signature,
        public CompanyBackupReferenceMapping $mapping,
        public string $targetRegistryKey,
        array $sourceKey,
        array $fallbacks,
    ) {
        $this->sourceKey = $sourceKey;
        $this->fallbacks = $fallbacks;
    }

    /** @param list<int|string> $values */
    public static function column(
        string $sourceRegistryKey,
        CompanyBackupReference $reference,
        array $values,
    ): self {
        return new self(
            $sourceRegistryKey,
            $reference->firstColumn(),
            self::KIND_COLUMN,
            $reference->signature(),
            $reference->mapping,
            $reference->target,
            self::sourceKey($reference->targetColumns, $values),
            $reference->fallbacks,
        );
    }

    public static function encoded(
        string $sourceRegistryKey,
        CompanyBackupEncodedReference $reference,
        int $value,
    ): self {
        return new self(
            $sourceRegistryKey,
            $reference->column,
            self::KIND_ENCODED,
            $reference->signature(),
            $reference->mapping,
            $reference->target,
            self::sourceKey($reference->targetColumns, [$value]),
            [],
        );
    }

    public static function embedded(
        string $sourceRegistryKey,
        CompanyBackupEmbeddedReference $reference,
        int|string $value,
    ): self {
        return new self(
            $sourceRegistryKey,
            $reference->column,
            self::KIND_EMBEDDED,
            $reference->signature(),
            $reference->mapping,
            $reference->target,
            self::sourceKey($reference->targetColumns, [$value]),
            $reference->fallbacks,
        );
    }

    public static function polymorphic(
        string $sourceRegistryKey,
        CompanyBackupPolymorphicReference $reference,
        CompanyBackupPolymorphicReferenceCase $case,
        int $value,
    ): self {
        if ($case->target === null) {
            throw new \LogicException(
                'Preserve varianta nevytváří mapovatelnou referenci.',
            );
        }
        return new self(
            $sourceRegistryKey,
            $reference->column,
            self::KIND_POLYMORPHIC,
            $reference->signature() . '/' . $case->signature(),
            CompanyBackupReferenceMapping::TenantId,
            $case->target,
            self::sourceKey($case->targetColumns, [$value]),
            [],
        );
    }

    public function withSourceKey(CompanyBackupSourceKey $sourceKey): self
    {
        if ($sourceKey->registryKey !== $this->targetRegistryKey) {
            throw new \InvalidArgumentException(
                'Vyřešený zdrojový klíč patří jinému cílovému objektu.',
            );
        }
        return new self(
            $this->sourceRegistryKey,
            $this->sourceColumn,
            $this->sourceKind,
            $this->signature,
            $this->mapping,
            $this->targetRegistryKey,
            $sourceKey->values,
            $this->fallbacks,
        );
    }

    /**
     * @param list<string> $columns
     * @param list<int|string> $values
     * @return array<string,int|string>
     */
    private static function sourceKey(array $columns, array $values): array
    {
        if (count($columns) !== count($values)) {
            throw new \LogicException('Zdrojová reference nemá úplný klíč.');
        }
        $key = [];
        foreach ($columns as $index => $column) {
            $key[$column] = $values[$index];
        }
        return $key;
    }
}
