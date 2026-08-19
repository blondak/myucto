<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Tři prvky, které ČSSZ skládá do věci odpovědi v datové schránce.
 *
 * Tvar `[{classname}-{correlationId}-{dmId}]` slibuje Podávací a dotazovací
 * protokol ČSSZ v1.47 na straně 24. Rozebrané prvky nesou různou váhu a je to
 * záměr, ne nedůslednost:
 *
 * - `originalMessageId` (dmId naší odeslané zprávy) je JEDINÝ, který podání
 *   prokazatelně identifikuje — je jedinečný v celém ISDS a známe ho z odeslání.
 * - `className` slouží jen jako pojistka proti odpovědi na jinou agendu.
 * - `correlationId` se veze dál k parseru protokolu; sám o sobě nic neprokazuje,
 *   protože věc datové zprávy je nepodepsaný text.
 */
final readonly class JmhzIsdsResponseReference
{
    public function __construct(
        public string $className,
        public string $correlationId,
        public string $originalMessageId,
    ) {}
}
