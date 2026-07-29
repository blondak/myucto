<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

/**
 * Selhání síťové komunikace s licenčním serverem (nedostupný, timeout, neplatná
 * odpověď). Volající (renew middleware / cron) chybu jen zaloguje a stav licence
 * ponechá řízený platností posledního tokenu.
 */
final class LicenseNetworkException extends \RuntimeException
{
}
