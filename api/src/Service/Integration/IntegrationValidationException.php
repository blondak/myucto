<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

/** Chyba nastavení připojení s cestou k poli, které ji způsobilo (např. `mappings.warehouses`). */
final class IntegrationValidationException extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
