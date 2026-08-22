<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

/**
 * Vyhodnocený stav kvóty (H-10) — co z měření plyne pro chování aplikace.
 *
 * ⚠️ `percent` je `?float` schválně. Nezměřená instance MUSÍ vracet `null`,
 * ne `0.0`. Nula je tvrzení „nic nezabíráme", null je „ještě jsme neměřili" —
 * a právě záměna těchhle dvou by udělala z nezměřené instalace uklidňující
 * „0 %, vše v pořádku".
 */
final class StorageQuotaStatus
{
    public function __construct(
        public readonly StorageQuotaState $state,
        public readonly ?float $percent,
        public readonly ?int $usageBytes,
        public readonly ?int $quotaBytes,
        public readonly StorageUsageSnapshot $snapshot,
        /** Uplatňuje se režim na téhle instalaci vůbec? (managed + kvóta + zapnuto) */
        public readonly bool $enforceable,
        public readonly int $warnPercent,
        public readonly int $readOnlyPercent,
    ) {}

    public function warns(): bool
    {
        return $this->state->warns();
    }

    public function blocksWrites(): bool
    {
        return $this->state->blocksWrites();
    }

    /** Kolik bajtů ještě zbývá; null, dokud se neměřilo nebo není kvóta. */
    public function remainingBytes(): ?int
    {
        if ($this->usageBytes === null || $this->quotaBytes === null) {
            return null;
        }

        return max(0, $this->quotaBytes - $this->usageBytes);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'state'             => $this->state->value,
            'enforceable'       => $this->enforceable,
            // Nikdy `?? 0` — viz hlavička.
            'percent'           => $this->percent,
            'usage_bytes'       => $this->usageBytes,
            'quota_bytes'       => $this->quotaBytes,
            'remaining_bytes'   => $this->remainingBytes(),
            'warn_percent'      => $this->warnPercent,
            'read_only_percent' => $this->readOnlyPercent,
            'measurement'       => $this->snapshot->toArray(),
        ];
    }
}
