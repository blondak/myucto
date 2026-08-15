<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Odpověď na jedinou otázku, kterou po přerušeném odeslání potřebujeme:
 * **odešla ta zpráva, nebo ne?**
 *
 * Kanál na ni odpovídá dohledáním vlastní correlation reference v odeslaných
 * zprávách. Třetí možnost — „pořád nevím" — je plnohodnotná odpověď: dokud
 * schránka neodpoví, nesmí se nic odeslat znovu.
 */
final readonly class DispatchProbe
{
    private function __construct(
        public bool $resolved,
        public ?string $externalMessageId,
        public ?string $reason,
    ) {}

    /** Zpráva v odeslaných je — odešla, adoptujeme její identifikátor. */
    public static function found(string $externalMessageId): self
    {
        if (trim($externalMessageId) === '') {
            throw new \InvalidArgumentException('Nalezená zpráva musí mít identifikátor.');
        }
        return new self(true, trim($externalMessageId), null);
    }

    /** Zpráva v odeslaných není — neodešla, je bezpečné opakovat. */
    public static function notSent(string $reason): self
    {
        return new self(true, null, $reason);
    }

    /** Dohledat se nepodařilo. Nevědomost trvá; opakovat se NESMÍ. */
    public static function inconclusive(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
