<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Úplný inventář jedné registrované souborové oblasti. */
final readonly class CompanyBackupFileAreaInventory
{
    private const MAX_ENTRIES = 100_000;

    /** @var list<CompanyBackupFileEntry> */
    public array $entries;

    /** @param list<CompanyBackupFileEntry> $entries */
    private function __construct(
        public string $registryKey,
        public int $order,
        array $entries,
    ) {
        $this->entries = $entries;
    }

    public static function fromArray(
        mixed $value,
        TenantDataDefinition $definition,
        int $expectedOrder,
        TenantDataRegistry $registry,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($definition->key);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['entries', 'order', 'registry_key']
            || $value['registry_key'] !== $definition->key
            || $value['order'] !== $expectedOrder
            || !is_array($value['entries'])
            || !array_is_list($value['entries'])
            || count($value['entries']) > self::MAX_ENTRIES
        ) {
            throw self::invalid($definition->key);
        }

        $policy = CompanyBackupFilePolicy::fromDefinition($definition);
        $allowedOwners = CompanyBackupFileOwnerSet::fromDefinition(
            $definition,
            $registry,
        );
        $entries = [];
        $seen = [];
        foreach ($value['entries'] as $entryValue) {
            $entry = CompanyBackupFileEntry::fromArray(
                $entryValue,
                $definition->name(),
                $policy,
                $registry,
                $allowedOwners,
            );
            if (isset($seen[$entry->sourcePath])) {
                throw self::invalid($definition->key);
            }
            $seen[$entry->sourcePath] = true;
            $entries[] = $entry;
        }
        $ordered = $entries;
        usort(
            $ordered,
            static fn (CompanyBackupFileEntry $left, CompanyBackupFileEntry $right): int =>
                strcmp($left->sourcePath, $right->sourcePath),
        );
        if ($ordered !== $entries) {
            throw self::invalid($definition->key);
        }
        return new self($definition->key, $expectedOrder, $entries);
    }

    /**
     * @return array{
     *   registry_key:string,
     *   order:int,
     *   entries:list<array<string,mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'registry_key' => $this->registryKey,
            'order' => $this->order,
            'entries' => array_map(
                static fn (CompanyBackupFileEntry $entry): array => $entry->toArray(),
                $this->entries,
            ),
        ];
    }

    private static function invalid(string $registryKey): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            'Inventář souborové oblasti ' . $registryKey . ' není platný.',
        );
    }
}
