<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Service\Migration\OssMigrationPolicy;
use MyInvoice\Service\Oss\OssDocumentCoherence;
use MyInvoice\Service\Vat\VatRateResolver;

final class AbraTaxPlanner
{
    public function __construct(
        private readonly OssMigrationPolicy $oss,
        private readonly VatRateResolver $rates,
    ) {}

    /** @param array<string,mixed> $plan @return array{plan:array<string,mixed>,warnings:list<string>,oss_items:int} */
    public function plan(int $supplierId, int $clientId, array $plan): array
    {
        $warnings = [];
        if ($plan['kind'] !== 'issued' || $plan['status'] !== 'draft') {
            if ($plan['status'] === 'draft') $warnings[] = 'document_tax_classification_requires_review';
            return ['plan' => $plan, 'warnings' => $warnings, 'oss_items' => 0];
        }
        $country = (string) ($plan['vat_country'] ?? '');
        $eligible = preg_match('/^[A-Z]{2}$/D', $country) === 1 && $country !== 'CZ';
        $client = null;
        $ossCount = 0;
        foreach ($plan['items'] as $index => $item) {
            if (!$item['vat_requires_review'] || abs((float) $item['vat']) < 0.005 || !$eligible
                || $item['vat_classification'] === null) {
                continue;
            }
            $client ??= $this->oss->clientContext($clientId, $country, (string) ($plan['partner']['dic'] ?? ''));
            $outcome = $this->oss->planItem($supplierId, $client, (float) $item['vat_rate'],
                $item['unit'], $plan['tax_date'] ?? $plan['date'], $item['source_vat_class']);
            if ($outcome['rate_id'] === null || (string) ($outcome['columns']['oss_consumer_country'] ?? '') !== $country) {
                if (in_array($item['vat_classification'], ['26', '24z'], true)
                    && $plan['ready_booked_at'] !== null) {
                    $rate = $this->rates->resolve($country, (float) $item['vat_rate'],
                        $plan['tax_date'] ?? $plan['date']);
                    if ($rate->found() && !$rate->isWarning()) {
                        $plan['items'][$index]['foreign_rate_id'] = $rate->id;
                        $plan['items'][$index]['vat_requires_review'] = false;
                    }
                }
                continue;
            }
            $plan['items'][$index]['oss_rate_id'] = $outcome['rate_id'];
            $plan['items'][$index]['vat_rate'] = $outcome['rate_percent'];
            $plan['items'][$index]['vat_classification'] = null;
            $plan['items'][$index]['vat_requires_review'] = false;
            $plan['items'][$index]['oss'] = $outcome['columns']
                + $this->oss->returnAmounts($supplierId, $plan['currency'], $item['base'], $item['vat']);
            $ossCount++;
            if ($outcome['manual_review'] || $outcome['warnings'] !== []) {
                $warnings[] = 'document_oss_item_requires_review';
            }
        }
        if ($ossCount > 0) {
            $normalized = [];
            foreach ($plan['items'] as $index => $item) {
                $normalized[$index] = [
                    'applicable' => (int) ($item['oss']['oss_applicable'] ?? 0) === 1,
                    'country' => $item['oss']['oss_consumer_country'] ?? null,
                    'rate' => abs((float) $item['vat']) >= 0.005 ? (float) $item['vat_rate'] : 0.0,
                ];
            }
            $contradiction = OssDocumentCoherence::detect($normalized);
            if ($contradiction !== null) {
                foreach ($contradiction->affectedKeys as $key) {
                    $plan['items'][$key]['oss'] = array_replace(OssMigrationPolicy::DOMESTIC_COLUMNS,
                        $plan['items'][$key]['oss'] ?? [], ['oss_needs_manual_review' => 1]);
                }
                $warnings[] = 'document_mixed_oss_and_domestic_requires_review';
            }
        }
        if (array_any($plan['items'], static fn (array $item): bool => $item['vat_requires_review'] ?? false)) {
            $warnings[] = 'document_tax_classification_requires_review';
        } else {
            $plan['status'] = $plan['ready_status'];
            $plan['booked_at'] = $plan['ready_booked_at'];
        }
        return ['plan' => $plan, 'warnings' => $warnings, 'oss_items' => $ossCount];
    }
}
