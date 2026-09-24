<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Service\Accounting\Obligations\OtherItemForecastService;
use MyInvoice\Service\Crm\CrmAggregationService;

/**
 * Pohledávky a závazky daňové evidence (Epic DE, A3).
 *
 * Sestava zahrnuje:
 *  - mapper 5→3+ kbelíky: CRM vrací {not_due, overdue_30, overdue_60, overdue_90,
 *    overdue_90_plus}; DE prezentuje {not_due, 1-30, 31-90, 90+}. `not_due` zůstává
 *    vlastní kbelík (je PŘED splatností), overdue_60 + overdue_90 se slévají do 31-90.
 *  - nativní částky per měna (žádný CZK přepočet, §14/4) — sumace count/total v rámci
 *    (currency, cílový kbelík).
 *  - KPI (DSO/DPO/punktualita) přebrané z CRM beze změny.
 *
 * Faktury pocházejí z CRM aging SQL, ostatní položky z vlastní evidence.
 * Vše supplier-scoped a READ-ONLY.
 */
final class ReceivablesPayablesService
{
    /** Zdrojový CRM kbelík → cílový DE kbelík (R13 mapper 5→3+not_due). */
    private const BUCKET_MAP = [
        'not_due'         => 'not_due',
        'overdue_30'      => '1-30',
        'overdue_60'      => '31-90',
        'overdue_90'      => '31-90',
        'overdue_90_plus' => '90+',
    ];

    /** Kanonické pořadí cílových kbelíků pro prezentaci. */
    public const BUCKET_ORDER = ['not_due', '1-30', '31-90', '90+'];

    public function __construct(
        private readonly CrmAggregationService $crm,
        private readonly OtherItemForecastService $otherItemForecast,
    ) {}

    /**
     * @return array{
     *   receivables: list<array{currency:string, bucket:string, count:int, total:float}>,
     *   payables: list<array{currency:string, bucket:string, count:int, total:float}>,
     *   currencies: list<string>,
     *   kpis: array<string,mixed>
     * }
     */
    public function build(int $supplierId): array
    {
        $other = $this->otherItems($supplierId);
        $receivables = $this->mapBuckets(array_merge($this->crm->agingReceivables($supplierId), $other['receivable']));
        $payables    = $this->mapBuckets(array_merge($this->crm->agingPayables($supplierId), $other['payable']));

        $currencies = array_values(array_unique(array_merge(
            array_column($receivables, 'currency'),
            array_column($payables, 'currency'),
        )));
        sort($currencies);

        return [
            'receivables' => $receivables,
            'payables'    => $payables,
            'currencies'  => $currencies,
            'kpis'        => [
                'dso'          => $this->crm->daysSalesOutstanding($supplierId),
                'dpo'          => $this->crm->daysPayableOutstanding($supplierId),
                'punctuality'  => $this->crm->paymentPunctuality($supplierId),
            ],
        ];
    }

    /**
     * @return array{receivable:list<array{bucket:string,currency:string,count:int,total:float}>,payable:list<array{bucket:string,currency:string,count:int,total:float}>}
     */
    private function otherItems(int $supplierId): array
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $groups = ['receivable' => [], 'payable' => []];
        foreach ($this->otherItemForecast->dueBetween($supplierId, '1000-01-01', '9999-12-31') as $row) {
            if (!in_array($row['status'], ['confirmed', 'posted'], true)) continue;
            $days = (new \DateTimeImmutable($row['due_on']))->diff(new \DateTimeImmutable($today))->days;
            $bucket = $row['due_on'] > $today ? 'not_due'
                : ($days <= 30 ? 'overdue_30' : ($days <= 60 ? 'overdue_60'
                    : ($days <= 90 ? 'overdue_90' : 'overdue_90_plus')));
            $key = $bucket . '|' . $row['currency'];
            $groups[$row['side']][$key] ??= [
                'bucket' => $bucket, 'currency' => $row['currency'], 'count' => 0, 'total' => 0.0,
            ];
            $groups[$row['side']][$key]['count']++;
            $groups[$row['side']][$key]['total'] += $row['remaining'];
        }
        return [
            'receivable' => array_values($groups['receivable']),
            'payable' => array_values($groups['payable']),
        ];
    }

    /**
     * Sloučí CRM 5 kbelíků do DE 3+not_due, nativně per měna (bez CZK přepočtu).
     *
     * @param list<array{bucket:string, currency:string, count:int, total:float}> $rows
     * @return list<array{currency:string, bucket:string, count:int, total:float}>
     */
    private function mapBuckets(array $rows): array
    {
        $acc = []; // "currency|bucket" => [count, total]
        foreach ($rows as $r) {
            $currency = (string) $r['currency'];
            $target   = self::BUCKET_MAP[(string) $r['bucket']] ?? '90+';
            $key      = $currency . '|' . $target;
            if (!isset($acc[$key])) {
                $acc[$key] = ['currency' => $currency, 'bucket' => $target, 'count' => 0, 'total' => 0.0];
            }
            $acc[$key]['count'] += (int) $r['count'];
            $acc[$key]['total']  = round($acc[$key]['total'] + (float) $r['total'], 2);
        }

        $out = array_values($acc);
        usort($out, static function (array $a, array $b): int {
            return ($a['currency'] <=> $b['currency'])
                ?: (array_search($a['bucket'], self::BUCKET_ORDER, true) <=> array_search($b['bucket'], self::BUCKET_ORDER, true));
        });
        return $out;
    }
}
