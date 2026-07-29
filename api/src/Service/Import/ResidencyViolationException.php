<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * F7 §3.5 — vyhozena, když je tenant s vynucenou EU rezidencí dat směrován na
 * non-EU provider/region. {@see LlmGatewayRouter} ji mapuje na
 * `['ok'=>false,'error'=>'residency_conflict']` (Action → HTTP 422). Nikdy tichý
 * fallback na jiný provider/region/tenant.
 */
final class ResidencyViolationException extends \RuntimeException
{
}
