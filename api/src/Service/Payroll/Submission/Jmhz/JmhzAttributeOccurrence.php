<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Jeden výskyt atributu datového slovníku ve vyrobeném XML.
 *
 * `groupKey` je tečková cesta rodičovského elementu včetně pořadí opakovaných
 * sourozenců (`pojisteni.eldpSeznam.eldp[2]`). Bez ní by se u opakovaného
 * bloku ELDP porovnávalo „platnost od" jedné sekce proti „platnost do" jiné.
 */
final readonly class JmhzAttributeOccurrence
{
    public function __construct(
        public string $attributeId,
        public string $value,
        public string $groupKey,
        public string $path,
    ) {}
}
