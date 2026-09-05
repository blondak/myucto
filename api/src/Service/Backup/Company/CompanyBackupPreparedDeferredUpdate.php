<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Přesný stav před a po druhém průchodu svázaný s cílovým primárním klíčem. */
final readonly class CompanyBackupPreparedDeferredUpdate
{
    /**
     * @param array<string,mixed> $beforeValues
     * @param array<string,mixed> $afterValues
     */
    public function __construct(
        public CompanyBackupSourceKey $targetPrimaryKey,
        public array $beforeValues,
        public array $afterValues,
    ) {
        if ($beforeValues === []
            || array_keys($beforeValues) !== array_keys($afterValues)
        ) {
            throw new \InvalidArgumentException(
                'Připravená odložená aktualizace nemá shodné sloupce.',
            );
        }
        foreach (array_keys($beforeValues) as $column) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1) {
                throw new \InvalidArgumentException(
                    'Připravená odložená aktualizace má neplatný sloupec.',
                );
            }
        }
    }

    public function hasChanges(): bool
    {
        return $this->beforeValues !== $this->afterValues;
    }
}
