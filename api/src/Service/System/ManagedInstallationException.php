<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

/**
 * Pokus změnit něco, co ve spravované instalaci drží provozovatel.
 *
 * Nese předmět zámku (konfigurační klíč nebo schopnost) i hotový lidský text,
 * aby volající nemusel skládat hlášku sám — jinak by se rozešly formulace
 * mezi HTTP vrstvou a CLI a uživatel by ze dvou míst dostal dvě různá vysvětlení.
 */
final class ManagedInstallationException extends \RuntimeException
{
    public function __construct(
        public readonly string $subject,
        string $message,
    ) {
        parent::__construct($message);
    }
}
