<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * F7 §3.5 — fail-closed vynucení EU rezidence dat. Pojmenovaná, testovatelná
 * služba (ne roztroušené `if`). Volá se uvnitř {@see LlmGatewayRouter} na VŠECH
 * delegovaných metodách (4× extrakce + strongerModel upgrade + testConnection).
 * Tenant s `ai_eu_residency_required=1` se nikdy nesmí dostat na non-EU region.
 */
final class ResidencyPolicy
{
    /**
     * @throws ResidencyViolationException když EU-required tenant míří na non-EU region
     */
    public function assertAllowed(string $provider, string $region, bool $euRequired): void
    {
        if (!$euRequired) {
            return;
        }
        if (strtolower(trim($region)) !== 'eu') {
            throw new ResidencyViolationException(sprintf(
                'EU data residency required, but provider "%s" resolves to region "%s".',
                $provider,
                $region,
            ));
        }
    }
}
