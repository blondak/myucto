<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

use Psr\Http\Message\ServerRequestInterface as Request;

final class GroupDashboardService
{
    public const SECTIONS = ['overview', 'trends', 'cashflow', 'forecast', 'balances', 'receivables', 'risks'];

    public function __construct(
        private readonly GroupDashboardAccess $access,
        private readonly GroupDashboardCompanyReader $reader,
        private readonly GroupDashboardCurrencyView $currencyView,
    ) {}

    public function dashboard(Request $request, string $section, int $months, int $weeks, ?string $from = null, ?string $to = null, bool $includeRelated = true): array
    {
        $period = self::period($months, $from, $to);
        $companies = [];
        foreach ($this->access->companies($request) as $entry) {
            $companies[] = $this->reader->read($entry['request'], $entry['supplier'], $section, $months, $weeks, $period, $includeRelated);
        }
        usort($companies, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return [
            'section' => $section, 'months' => $months, 'weeks' => $weeks,
            'as_of' => date('Y-m-d'), 'generated_at' => date(\DateTimeInterface::ATOM),
            'period' => $period,
            'include_related' => $includeRelated,
            'concentration_period' => [
                'months' => 12, 'from' => (new \DateTimeImmutable('first day of this month'))->modify('-12 months')->format('Y-m-d'),
                'to' => null, 'basis' => 'crm_months',
            ],
            'forecast_period' => ['from' => date('Y') . '-01-01', 'to' => date('Y') . '-12-31',
                'basis' => 'annual_model', 'includes_future_documents' => true, 'range_kind' => 'signal_spread'],
            'company_count' => count($companies), 'companies' => $companies,
            'totals' => GroupDashboardTotals::aggregate($companies),
            'converted_czk' => $this->currencyView->convert($companies, date('Y-m-d')),
        ];
    }

    public static function period(int $months = 12, ?string $from = null, ?string $to = null, ?\DateTimeImmutable $today = null): array
    {
        if ($months < 1 || $months > 36 || ($from === null) !== ($to === null)) throw new \InvalidArgumentException('Invalid dashboard period.');
        $custom = $from !== null;
        $today ??= new \DateTimeImmutable('today');
        $from ??= $today->modify('first day of this month')->modify('-' . ($months - 1) . ' months')->format('Y-m-d');
        $to ??= $today->format('Y-m-d');
        foreach ([$from, $to] as $value) {
            if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $m) !== 1
                || (int) $m[1] < 1001 || (int) $m[1] > 9998 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new \InvalidArgumentException('Invalid dashboard date.');
            }
        }
        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        $calendarMonths = ((int) $end->format('Y') - (int) $start->format('Y')) * 12
            + (int) $end->format('n') - (int) $start->format('n') + 1;
        if ($from > $to || $calendarMonths > 36) throw new \InvalidArgumentException('Invalid dashboard range.');
        $previous = static function (\DateTimeImmutable $date): string {
            $anchor = $date->setDate((int) $date->format('Y') - 1, (int) $date->format('n'), 1);
            return $anchor->setDate((int) $anchor->format('Y'), (int) $anchor->format('n'),
                min((int) $date->format('j'), (int) $anchor->format('t')))->format('Y-m-d');
        };
        return ['from' => $from, 'to' => $to, 'previous_from' => $previous($start), 'previous_to' => $previous($end),
            'mode' => $custom ? 'custom' : 'rolling'];
    }
}
