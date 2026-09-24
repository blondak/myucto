<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Kdo dávku spustil a co smí: dávka běží na pozadí bez požadavku, proto se oprávnění
 * uživatele zafixují při spuštění a jdou s jobem.
 */
final class MigrationBatchActor
{
    /**
     * @param list<int>|null $allowedSupplierIds firmy, do kterých smí převádět (null = všechny, superadmin a CLI)
     * @param bool $canCreate smí zakládat firmy
     * @param bool $assignCreator založenou firmu mu přiřadit (kdo není superadmin, jinak by ji neviděl)
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?array $allowedSupplierIds,
        public readonly bool $canCreate,
        public readonly bool $assignCreator,
    ) {}

    /** Příkazová řádka: správce instalace. */
    public static function cli(int $userId): self
    {
        return new self($userId, null, true, false);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $allowed = $data['allowed_supplier_ids'] ?? null;
        return new self(
            (int) ($data['user_id'] ?? 0),
            is_array($allowed) ? array_values(array_map('intval', $allowed)) : null,
            (bool) ($data['can_create'] ?? false),
            (bool) ($data['assign_creator'] ?? true),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'allowed_supplier_ids' => $this->allowedSupplierIds,
            'can_create' => $this->canCreate,
            'assign_creator' => $this->assignCreator,
        ];
    }

    /** Po založení firmy ji uživatel vidí i v dalších položkách dávky. */
    public function withSupplier(int $supplierId): self
    {
        if ($this->allowedSupplierIds === null || in_array($supplierId, $this->allowedSupplierIds, true)) {
            return $this;
        }
        return new self($this->userId, [...$this->allowedSupplierIds, $supplierId], $this->canCreate, $this->assignCreator);
    }
}
