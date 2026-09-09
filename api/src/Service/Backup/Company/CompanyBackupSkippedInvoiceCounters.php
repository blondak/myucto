<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\CompanyBackupInvoiceCounterDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\CanonicalJson;

/** Uzavřený seznam výhradně osiřelých čítačů; originální JSONL se nekrátí. */
final readonly class CompanyBackupSkippedInvoiceCounters
{
    public const MAX_ROWS = 10000;
    /** @var array<string,CompanyBackupSourceKey> */
    public array $keys;

    /** @param list<CompanyBackupSourceKey> $keys */
    public function __construct(array $keys = [])
    {
        if (count($keys) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('Příliš mnoho osiřelých čítačů.');
        }
        $indexed = [];
        foreach ($keys as $key) {
            if ($key->registryKey !== CompanyBackupInvoiceCounterKey::REGISTRY_KEY
                || $key->columns !== CompanyBackupInvoiceCounterKey::COLUMNS
                || ($key->values['client_id'] === 0 && $key->values['revenue_category_id'] === 0)
                || isset($indexed[$key->id])) {
                throw new \InvalidArgumentException('Neplatný seznam vynechaných čítačů.');
            }
            $indexed[$key->id] = $key;
        }
        ksort($indexed, SORT_STRING);
        $this->keys = $indexed;
    }

    public static function assertDefinition(TenantDataDefinition $definition): void
    {
        if (CanonicalJson::sha256($definition->toArray()) !== CanonicalJson::sha256(CompanyBackupInvoiceCounterDefinition::definition()->toArray())) {
            throw new CompanyBackupPreflightException('counter_skip_contract_mismatch', $definition->key);
        }
    }

    /** @param array<string,mixed> $row */
    public static function isOrphan(array $row, CompanyBackupSourceIdentityLookup $identities): bool
    {
        $orphan = false;
        foreach (['client_id' => 'table:clients', 'revenue_category_id' => 'table:revenue_categories'] as $column => $table) {
            if ($row[$column] === 0) {
                continue;
            }
            $identity = $identities->find(CompanyBackupSourceKey::fromValues($table, ['id' => $row[$column]]));
            if ($identity === null) {
                $orphan = true;
            } elseif (($identity->tenantScopedPrimaryKey?->values['supplier_id'] ?? null) !== $row['supplier_id']) {
                throw new CompanyBackupPreflightException('counter_owner_mismatch', CompanyBackupInvoiceCounterKey::REGISTRY_KEY, $column);
            }
        }
        return $orphan;
    }

    public function count(string $registryKey = CompanyBackupInvoiceCounterKey::REGISTRY_KEY): int
    {
        return $registryKey === CompanyBackupInvoiceCounterKey::REGISTRY_KEY ? count($this->keys) : 0;
    }

    public function bindingSha256(): ?string
    {
        return $this->count() === 0 ? null : CanonicalJson::sha256($this->toArray());
    }

    /** @return list<array<string,mixed>> */
    public function toArray(): array
    {
        return array_values(array_map(static fn (CompanyBackupSourceKey $key): array => $key->toArray(), $this->keys));
    }

    /** @return list<array{code:string,message:string}> */
    public function warnings(): array
    {
        return $this->count() === 0 ? [] : [[
            'code' => 'orphan_invoice_counters_skipped',
            'message' => 'Při obnově se vynechají čítače řad s klientem nebo kategorií chybějící v záloze (počet: '
                . $this->count() . '). Zdroj ani obsah zálohy se nemění; náhradní klienti a kategorie se nevytvářejí.',
        ]];
    }

    /** @param callable(array<string,mixed>):void $visitor */
    public function consumeRows(CompanyBackupImportSource $source, string $registryKey, callable $visitor): int
    {
        if ($registryKey !== CompanyBackupInvoiceCounterKey::REGISTRY_KEY) {
            return $source->consumeRows($registryKey, $visitor);
        }
        $definition = $source->targetRegistry()->registry->definition($registryKey);
        if ($definition === null) {
            throw new \LogicException('Chybí definice čítačů.');
        }
        self::assertDefinition($definition);
        $skipped = 0;
        $consumed = $source->consumeRows($registryKey, function (array $row) use ($visitor, &$skipped): void {
            $key = CompanyBackupSourceKey::fromRow(CompanyBackupInvoiceCounterKey::REGISTRY_KEY, CompanyBackupInvoiceCounterKey::COLUMNS, $row);
            if (isset($this->keys[$key->id])) {
                $skipped++;
            } else {
                $visitor($row);
            }
        });
        if ($skipped !== $this->count()) {
            throw new CompanyBackupPreflightException('counter_skip_count_mismatch', $registryKey);
        }
        return $consumed;
    }
}
