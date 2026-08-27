<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Jedna úplná varianta polymorfní sloupcové reference. */
final readonly class CompanyBackupPolymorphicReferenceCase
{
    /** @var list<string> */
    public array $targetColumns;

    /** @var list<int> */
    public array $slots;

    /**
     * @param list<string> $targetColumns
     * @param list<int> $slots
     */
    private function __construct(
        public string $equals,
        public CompanyBackupPolymorphicReferenceMapping $mapping,
        public ?string $target,
        array $targetColumns,
        public CompanyBackupPolymorphicReferenceTransform $transform,
        public int $base,
        public int $multiplier,
        array $slots,
    ) {
        $this->targetColumns = $targetColumns;
        $this->slots = $slots;
    }

    public static function fromArray(mixed $value, string $registryKey, string $column): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey, $column);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'base',
            'equals',
            'mapping',
            'multiplier',
            'slots',
            'target',
            'target_columns',
            'transform',
        ]) {
            throw self::invalid($registryKey, $column);
        }

        $equals = $value['equals'];
        $mappingValue = $value['mapping'];
        $mapping = is_string($mappingValue)
            ? CompanyBackupPolymorphicReferenceMapping::tryFrom($mappingValue)
            : null;
        $target = $value['target'];
        $targetColumns = self::identifierList($value['target_columns'], $registryKey, $column);
        $transformValue = $value['transform'];
        $transform = is_string($transformValue)
            ? CompanyBackupPolymorphicReferenceTransform::tryFrom($transformValue)
            : null;
        $base = $value['base'];
        $multiplier = $value['multiplier'];
        $slots = self::slots($value['slots'], $registryKey, $column);
        if (!is_string($equals)
            || preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $equals) !== 1
            || $mapping === null
            || $transform === null
            || !is_int($base)
            || !is_int($multiplier)
        ) {
            throw self::invalid($registryKey, $column);
        }

        $targetValid = match ($mapping) {
            CompanyBackupPolymorphicReferenceMapping::TenantId =>
                is_string($target)
                && str_starts_with($target, 'table:')
                && TenantDataDefinition::isValidKey($target)
                && $targetColumns !== [],
            CompanyBackupPolymorphicReferenceMapping::Preserve =>
                $target === null
                && $targetColumns === []
                && $transform === CompanyBackupPolymorphicReferenceTransform::Identity,
        };
        $transformValid = match ($transform) {
            CompanyBackupPolymorphicReferenceTransform::Identity =>
                $base === 0 && $multiplier === 1 && $slots === [],
            CompanyBackupPolymorphicReferenceTransform::DecimalSlot =>
                $base >= 0 && $multiplier >= 2 && $slots !== [],
            CompanyBackupPolymorphicReferenceTransform::IdentityOrDecimalSlot =>
                $base > 0 && $multiplier >= 2 && $slots !== [],
            CompanyBackupPolymorphicReferenceTransform::IdentityOrOffset =>
                $base > 0 && $multiplier === 1 && $slots === [],
        };
        foreach ($slots as $slot) {
            if ($slot < 0 || $slot >= $multiplier) {
                $transformValid = false;
            }
        }
        if (!$targetValid || !$transformValid) {
            throw self::invalid($registryKey, $column);
        }

        return new self(
            $equals,
            $mapping,
            is_string($target) ? $target : null,
            $targetColumns,
            $transform,
            $base,
            $multiplier,
            $slots,
        );
    }

    public function signature(): string
    {
        $target = $this->target === null
            ? 'preserve'
            : substr($this->target, strlen('table:')) . ':' . implode(',', $this->targetColumns);
        $parameters = $this->transform === CompanyBackupPolymorphicReferenceTransform::Identity
            ? ''
            : ':' . $this->base . ':' . $this->multiplier . ':' . implode(',', $this->slots);
        return $this->equals . '->' . $target . '#' . $this->transform->value . $parameters;
    }

    /**
     * @param callable(self,int):(int|null) $mapper
     */
    public function remap(int $value, callable $mapper): ?int
    {
        if ($this->mapping === CompanyBackupPolymorphicReferenceMapping::Preserve) {
            return $value;
        }
        $decoded = $this->decode($value);
        if ($decoded === null) {
            return null;
        }
        $mapped = $mapper($this, $decoded['id']);
        if (!is_int($mapped) || $mapped <= 0) {
            return null;
        }
        return $this->encode($mapped, $decoded['mode'], $decoded['slot']);
    }

    /** @return array{id:int,mode:'identity'|'decimal'|'offset',slot:int}|null */
    private function decode(int $value): ?array
    {
        if ($value <= 0) {
            return null;
        }
        if ($this->transform === CompanyBackupPolymorphicReferenceTransform::Identity) {
            return ['id' => $value, 'mode' => 'identity', 'slot' => 0];
        }
        if ($this->transform === CompanyBackupPolymorphicReferenceTransform::IdentityOrOffset) {
            if ($value < $this->base) {
                return ['id' => $value, 'mode' => 'identity', 'slot' => 0];
            }
            $id = $value - $this->base;
            return $id > 0 ? ['id' => $id, 'mode' => 'offset', 'slot' => 0] : null;
        }
        if ($this->transform === CompanyBackupPolymorphicReferenceTransform::IdentityOrDecimalSlot
            && $value < $this->base
        ) {
            return ['id' => $value, 'mode' => 'identity', 'slot' => 0];
        }

        $encoded = $value - $this->base;
        if ($encoded <= 0) {
            return null;
        }
        $id = intdiv($encoded, $this->multiplier);
        $slot = $encoded % $this->multiplier;
        if ($id <= 0 || !in_array($slot, $this->slots, true)) {
            return null;
        }
        return ['id' => $id, 'mode' => 'decimal', 'slot' => $slot];
    }

    /** @param 'identity'|'decimal'|'offset' $mode */
    private function encode(int $mapped, string $mode, int $slot): ?int
    {
        if ($mode === 'identity') {
            return $mapped;
        }
        if ($mode === 'offset') {
            return $mapped <= PHP_INT_MAX - $this->base
                ? $this->base + $mapped
                : null;
        }
        $available = PHP_INT_MAX - $this->base - $slot;
        if ($available < 0 || $mapped > intdiv($available, $this->multiplier)) {
            return null;
        }
        return $this->base + $mapped * $this->multiplier + $slot;
    }

    /** @return list<string> */
    private static function identifierList(
        mixed $value,
        string $registryKey,
        string $column,
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($registryKey, $column);
        }
        $result = [];
        $seen = [];
        foreach ($value as $identifier) {
            if (!is_string($identifier)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $identifier) !== 1
                || isset($seen[$identifier])
            ) {
                throw self::invalid($registryKey, $column);
            }
            $seen[$identifier] = true;
            $result[] = $identifier;
        }
        return $result;
    }

    /** @return list<int> */
    private static function slots(mixed $value, string $registryKey, string $column): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($registryKey, $column);
        }
        $result = [];
        $previous = null;
        foreach ($value as $slot) {
            if (!is_int($slot) || ($previous !== null && $slot <= $previous)) {
                throw self::invalid($registryKey, $column);
            }
            $result[] = $slot;
            $previous = $slot;
        }
        return $result;
    }

    private static function invalid(
        string $registryKey,
        string $column,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_polymorphic_reference_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
