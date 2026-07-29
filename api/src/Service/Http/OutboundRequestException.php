<?php

declare(strict_types=1);

namespace MyInvoice\Service\Http;

/**
 * Odchozí HTTP požadavek byl guardem odmítnut, nebo selhal na síti.
 * Zpráva je bezpečná pro zobrazení uživateli (neobsahuje credentials).
 */
final class OutboundRequestException extends \RuntimeException
{
}
