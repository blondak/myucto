<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Currency\CurrencyConversionService;
use MyInvoice\Service\Invoice\CzkRecap;
use MyInvoice\Service\Report\VatLedgerService;

final class OssLedgerService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CurrencyConversionService $currencyConverter,
        private readonly VatLedgerService $vatLedger,
        private readonly OssThresholdService $threshold,
        private readonly OssRateCodebook $rateCodebook,
    ) {}

    /** @return array<string,mixed> */
    public function preview(int $supplierId, int $year, int $quarter): array
    {
        $quarter = max(1, min(4, $quarter));
        [$start, $end] = OssPeriod::range($year, $quarter);
        $settings = $this->supplierSettings($supplierId);
        $warnings = [];

        if (!$this->hasOssColumns()) {
            $warnings[] = 'Chybí databázová migrace OSS (0137_oss_foundation.sql). Spusťte php api/bin/migrate.php.';
            return $this->emptyPreview($year, $quarter, $start, $end, $settings, $warnings);
        }

        // Vypnutý OSS sem už nedojde — OssReportAction vrací 409 dřív, než se preview zavolá,
        // a UI stránku bez zapnutého režimu vůbec nezpřístupní. Varování proto odpadlo.

        $rows = $this->vatLedger->ossRows($supplierId, $start, $end);
        $countries = [];
        $corrections = [];
        $invoiceIds = [];
        $totalBase = 0.0;
        $totalVat = 0.0;
        $totalCorrections = 0.0;
        $correctionRowCount = 0;
        $invalidCorrectionCount = 0;
        $conversionMissingCount = 0;
        $returnCurrency = (string) ($settings['oss_return_currency'] ?? 'EUR');
        $currentPeriod = sprintf('%04dQ%d', $year, $quarter);

        foreach ($rows as $r) {
            $invoiceId = (int) $r['invoice_id'];
            $invoiceIds[$invoiceId] = true;
            $country = strtoupper((string) ($r['oss_consumer_country'] ?? ''));
            if ($country === '') {
                $country = '??';
                $warnings[] = 'Doklad ' . self::docLabel($r) . ' má OSS řádek bez země spotřeby.';
            }

            $rate = (float) $r['vat_rate_snapshot'];
            $rateKey = number_format($rate, 2, '.', '');
            $baseReturn = $this->returnAmount($r, 'oss_taxable_amount_return', 'total_without_vat', $returnCurrency);
            $vatReturn = $this->returnAmount($r, 'oss_vat_amount_return', 'total_vat', $returnCurrency);
            $conversionMissing = $baseReturn === null || $vatReturn === null;

            if ($conversionMissing) {
                $conversionMissingCount++;
                $warnings[] = 'Doklad ' . self::docLabel($r) . ' má OSS řádek bez přepočtu do měny podání.';
                $baseReturn = 0.0;
                $vatReturn = 0.0;
            } elseif (abs($vatReturn - ($baseReturn * $rate / 100.0)) > 0.02) {
                $warnings[] = 'Doklad ' . self::docLabel($r) . ' má OSS základ a DPH, které neodpovídají zadané sazbě.';
            }

            // Vnitřní konzistence (výše) říká jen to, že si základ a daň odpovídají —
            // konzistentně ŠPATNÁ sazba jí projde. Teprve číselník ověří, že sazba
            // vůbec platí ve státě spotřeby, a to k datu plnění.
            $rateWarning = $this->rateCodebook->checkRate(
                $country,
                $rate,
                $r['oss_rate_type'] !== null ? (string) $r['oss_rate_type'] : null,
                (string) ($r['tax_date'] ?? $r['issue_date'] ?? date('Y-m-d')),
            );
            if ($rateWarning !== null) {
                $warnings[] = 'Doklad ' . self::docLabel($r) . ': ' . $rateWarning;
            }

            $originalPeriod = strtoupper(trim((string) ($r['oss_original_period'] ?? '')));
            if ($originalPeriod !== '') {
                if ($country === '??' || $conversionMissing) {
                    $invalidCorrectionCount++;
                    continue;
                }
                if (!preg_match('/^(\d{4})Q([1-4])$/', $originalPeriod, $periodMatch)) {
                    $warnings[] = 'Doklad ' . self::docLabel($r) . ' má neplatné původní OSS období.';
                    $invalidCorrectionCount++;
                    continue;
                }
                if ($originalPeriod < '2021Q3' || $originalPeriod >= $currentPeriod) {
                    $warnings[] = 'Doklad ' . self::docLabel($r) . ' musí mít jako opravu OSS období od Q3 2021, které předchází aktuálnímu přiznání.';
                    $invalidCorrectionCount++;
                    continue;
                }

                $key = $originalPeriod . '|' . $country;
                $corrections[$key] ??= [
                    'period' => $originalPeriod,
                    'year' => (int) $periodMatch[1],
                    'quarter' => (int) $periodMatch[2],
                    'state_consumption' => $country,
                    'correction' => 0.0,
                    'count' => 0,
                    'rows' => [],
                ];
                $corrections[$key]['correction'] += $vatReturn;
                $corrections[$key]['count']++;
                $corrections[$key]['rows'][] = [
                    'invoice_id' => $invoiceId,
                    'item_id' => (int) $r['item_id'],
                    'doc_number' => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
                    'invoice_type' => (string) $r['invoice_type'],
                    'tax_date' => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
                    'client_name' => (string) $r['client_name'],
                    'description' => (string) $r['description'],
                    'currency' => (string) $r['currency'],
                    'base_return' => round($baseReturn, 2),
                    'vat_return' => round($vatReturn, 2),
                    'original_period' => $originalPeriod,
                ];
                $totalCorrections += $vatReturn;
                $correctionRowCount++;
                continue;
            }

            if (empty($r['oss_rate_type'])) {
                $warnings[] = 'Doklad ' . self::docLabel($r) . ' má OSS řádek bez typu sazby.';
            }

            $countries[$country] ??= [
                'country' => $country,
                'base' => 0.0,
                'vat' => 0.0,
                'rates' => [],
                'rows' => [],
            ];
            $countries[$country]['rates'][$rateKey] ??= [
                'rate' => $rate,
                'rate_type' => $r['oss_rate_type'] ?? null,
                'base' => 0.0,
                'vat' => 0.0,
                'count' => 0,
            ];

            $countries[$country]['base'] += $baseReturn;
            $countries[$country]['vat'] += $vatReturn;
            $countries[$country]['rates'][$rateKey]['base'] += $baseReturn;
            $countries[$country]['rates'][$rateKey]['vat'] += $vatReturn;
            $countries[$country]['rates'][$rateKey]['count']++;
            $countries[$country]['rows'][] = [
                'invoice_id' => $invoiceId,
                'item_id' => (int) $r['item_id'],
                'doc_number' => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
                'invoice_type' => (string) $r['invoice_type'],
                'tax_date' => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
                'client_name' => (string) $r['client_name'],
                'description' => (string) $r['description'],
                'currency' => (string) $r['currency'],
                'base' => (float) $r['total_without_vat'],
                'vat' => (float) $r['total_vat'],
                'base_return' => round($baseReturn, 2),
                'vat_return' => round($vatReturn, 2),
                'vat_rate' => $rate,
                'rate_type' => $r['oss_rate_type'] ?? null,
                'supply_type' => $r['oss_supply_type'] ?? null,
            ];

            $totalBase += $baseReturn;
            $totalVat += $vatReturn;
        }

        $countryRows = array_values(array_map(static function (array $country): array {
            $country['base'] = round($country['base'], 2);
            $country['vat'] = round($country['vat'], 2);
            $country['rates'] = array_values(array_map(static fn (array $rate): array => [
                'rate' => $rate['rate'],
                'rate_type' => $rate['rate_type'],
                'base' => round($rate['base'], 2),
                'vat' => round($rate['vat'], 2),
                'count' => $rate['count'],
            ], $country['rates']));
            usort($country['rates'], static fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);
            return $country;
        }, $countries));
        usort($countryRows, static fn (array $a, array $b): int => strcmp($a['country'], $b['country']));

        $correctionRows = array_values(array_map(static function (array $correction): array {
            $correction['correction'] = round($correction['correction'], 2);
            return $correction;
        }, $corrections));
        usort($correctionRows, static fn (array $a, array $b): int =>
            [$a['year'], $a['quarter'], $a['state_consumption']]
            <=> [$b['year'], $b['quarter'], $b['state_consumption']]
        );

        return [
            'period' => [
                'year' => $year,
                'quarter' => $quarter,
                'start' => $start,
                'end' => $end,
                'label' => 'Q' . $quarter . ' ' . $year,
                'submission_deadline' => self::deadline($year, $quarter),
            ],
            'settings' => $settings,
            'summary' => [
                'return_currency' => $returnCurrency,
                'total_base' => round($totalBase, 2),
                'total_vat' => round($totalVat, 2),
                'total_corrections' => round($totalCorrections, 2),
                'total_payable' => round($totalVat + $totalCorrections, 2),
                'invoice_count' => count($invoiceIds),
                'row_count' => count($rows),
                'correction_row_count' => $correctionRowCount,
                'invalid_correction_count' => $invalidCorrectionCount,
                'conversion_missing_count' => $conversionMissingCount,
            ],
            'countries' => $countryRows,
            'corrections' => $correctionRows,
            // Práh 10 000 EUR (§ 8 odst. 3 ZDPH) se sleduje za CELÝ kalendářní rok, ne za
            // čtvrtletí podání — proto vlastní blok vedle kvartálních čísel.
            'threshold' => $this->threshold->progress($supplierId, $year),
            'warnings' => array_values(array_unique(array_merge(
                $warnings,
                $this->threshold->registrationSanityWarnings(
                    $supplierId,
                    $year,
                    (bool) ($settings['oss_enabled'] ?? false),
                ),
            ))),
        ];
    }

    /**
     * OSS je opt-in v nastavení firmy. Endpointy se na tohle ptají, aby nezaregistrovanému
     * dodavateli nevracely OSS podklad — UI ho ze stejného důvodu skrývá z menu i routeru.
     */
    public function isEnabledFor(int $supplierId): bool
    {
        return (bool) ($this->supplierSettings($supplierId)['oss_enabled'] ?? false);
    }

    /** @return array<string,mixed> */
    private function supplierSettings(int $supplierId): array
    {
        $hasSettings = $this->hasSupplierOssColumns();
        if (!$hasSettings) {
            return [
                'oss_enabled' => false,
                'oss_valid_from' => null,
                'oss_valid_to' => null,
                'oss_identification_country' => null,
                'oss_return_currency' => 'EUR',
            ];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT oss_enabled, oss_valid_from, oss_valid_to, oss_identification_country, oss_return_currency
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'oss_enabled' => (bool) ($row['oss_enabled'] ?? false),
            'oss_valid_from' => $row['oss_valid_from'] ?? null,
            'oss_valid_to' => $row['oss_valid_to'] ?? null,
            'oss_identification_country' => $row['oss_identification_country'] ?? null,
            'oss_return_currency' => $row['oss_return_currency'] ?? 'EUR',
        ];
    }

    private function returnAmount(array $row, string $field, string $sourceField, string $returnCurrency): ?float
    {
        if ($row[$field] !== null) {
            return (float) $row[$field];
        }
        if ((string) $row['currency'] === $returnCurrency) {
            return (float) $row[$sourceField];
        }
        if ($row['oss_exchange_rate'] !== null) {
            // HALF_UP přes bcmath, ne `round()`: tahle částka jde do PODÁNÍ (VetaR
            // taxable_amount / vat_amount). `round()` kvůli binární nepřesnosti dá
            // u součinu na půlhaléřové hranici o haléř míň — změřeno 603× na 1,6 mil.
            // kombinací částek a reálných kurzů ČNB. SSOT je CzkRecap::multiplyHalfUp().
            return CzkRecap::multiplyHalfUp((float) $row[$sourceField], (float) $row['oss_exchange_rate']);
        }
        $date = (string) ($row['tax_date'] ?? $row['issue_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        try {
            $dateValue = new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return null;
        }
        $converted = $this->currencyConverter->convert(
            (float) $row[$sourceField],
            (string) $row['currency'],
            $returnCurrency,
            $dateValue,
        );
        return $converted['amount'] ?? null;
    }

    private static function deadline(int $year, int $quarter): string
    {
        [$start] = OssPeriod::range($year, $quarter);
        return (new \DateTimeImmutable($start))->modify('+4 months -1 day')->format('Y-m-d');
    }

    /** @return array<string,mixed> */
    private function emptyPreview(int $year, int $quarter, string $start, string $end, array $settings, array $warnings): array
    {
        return [
            'period' => [
                'year' => $year,
                'quarter' => $quarter,
                'start' => $start,
                'end' => $end,
                'label' => 'Q' . $quarter . ' ' . $year,
                'submission_deadline' => self::deadline($year, $quarter),
            ],
            'settings' => $settings,
            'summary' => [
                'return_currency' => $settings['oss_return_currency'] ?? 'EUR',
                'total_base' => 0.0,
                'total_vat' => 0.0,
                'total_corrections' => 0.0,
                'total_payable' => 0.0,
                'invoice_count' => 0,
                'row_count' => 0,
                'correction_row_count' => 0,
                'invalid_correction_count' => 0,
                'conversion_missing_count' => 0,
            ],
            'countries' => [],
            'corrections' => [],
            // Bez OSS sloupců nelze práh počítat — nulový blok drží tvar odpovědi,
            // ať se UI nemusí ptát, jestli klíč vůbec existuje.
            'threshold' => [
                'year' => $year, 'threshold_eur' => 0.0, 'total_eur' => 0.0, 'pct' => 0.0,
                'exceeded' => false, 'exceeded_on' => null, 'near_threshold' => false,
                'by_country' => [], 'unconverted_rows' => 0, 'warnings' => [],
            ],
            'warnings' => $warnings,
        ];
    }

    private function hasOssColumns(): bool
    {
        return $this->db->hasColumn('invoice_items', 'oss_applicable');
    }

    private function hasSupplierOssColumns(): bool
    {
        return $this->db->hasColumn('supplier', 'oss_enabled');
    }

    /** @param array<string,mixed> $row */
    private static function docLabel(array $row): string
    {
        return (string) ($row['doc_number'] ?? ('#' . (int) $row['invoice_id']));
    }
}
