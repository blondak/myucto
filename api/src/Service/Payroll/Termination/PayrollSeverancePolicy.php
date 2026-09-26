<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Termination;

/**
 * Zákonné minimum odstupného (§ 67 ZP) a jednorázové náhrady (§ 271ca ZP)
 * v násobcích průměrného MĚSÍČNÍHO výdělku (§ 67 odst. 4).
 *
 *  - § 67 odst. 1 písm. a) až c): jeden, dva, nebo tři násobky podle toho,
 *    zda pracovní poměr trval méně než rok, rok až dva, nebo aspoň dva roky;
 *    písm. d) přidává tři násobky, vztahuje-li se na zaměstnance v kontu
 *    pracovní doby postup podle § 86 odst. 4,
 *  - § 67 odst. 2: do trvání se započte předchozí pracovní poměr u téhož
 *    zaměstnavatele, nepřesáhla-li mezera šest měsíců,
 *  - § 67 odst. 3: dvanáctinásobek při § 52 písm. e) (nejvyšší přípustná
 *    expozice),
 *  - § 271ca odst. 1: dvanáctinásobek při § 52 písm. d) z pracovního úrazu
 *    nebo nemoci z povolání.
 *
 * Kolektivní smlouva nebo vnitřní předpis smí násobek jen ZVÝŠIT — zákon
 * stanoví minimum („nejméně").
 */
final class PayrollSeverancePolicy
{
    public const MAX_OVERRIDE_MULTIPLE = 36;

    /**
     * @param list<array{start:string,end:string}> $previousEmployments předchozí
     *        pracovní poměry téhož zaměstnance u téhož zaměstnavatele
     * @return array{multiple:int,tenure_start:string,counted_previous:list<array{start:string,end:string}>,rule:string}
     */
    public static function statutory(
        PayrollTerminationReason $reason,
        string $start,
        string $end,
        array $previousEmployments,
        bool $workingTimeAccount,
    ): array {
        if ($reason->workInjuryCompensation()) {
            return ['multiple' => 12, 'tenure_start' => $start, 'counted_previous' => [], 'rule' => 'zp-271ca-1'];
        }
        $basis = $reason->severanceBasis();
        if ($basis === 'max_exposure') {
            return ['multiple' => 12, 'tenure_start' => $start, 'counted_previous' => [], 'rule' => 'zp-67-3'];
        }
        if ($basis !== 'organizational') {
            return ['multiple' => 0, 'tenure_start' => $start, 'counted_previous' => [], 'rule' => 'none'];
        }
        [$tenureStart, $counted] = self::tenureStart($start, $previousEmployments);
        $years = self::fullYears($tenureStart, $end);
        $multiple = match (true) {
            $years < 1 => 1,
            $years < 2 => 2,
            default => 3,
        };
        if ($workingTimeAccount) {
            $multiple += 3;
        }

        return [
            'multiple' => $multiple,
            'tenure_start' => $tenureStart,
            'counted_previous' => $counted,
            'rule' => $workingTimeAccount ? 'zp-67-1-d' : 'zp-67-1',
        ];
    }

    /**
     * Začátek započitatelné doby trvání. Předchozí poměr se započte DÉLKOU
     * (§ 67 odst. 2 — „doba trvání předchozího pracovního poměru"), mezera
     * mezi poměry ne. Proto se začátek posune o délku předchozího poměru,
     * ne na jeho počátek.
     *
     * @param list<array{start:string,end:string}> $previous
     * @return array{0:string,1:list<array{start:string,end:string}>}
     */
    public static function tenureStart(string $start, array $previous): array
    {
        usort($previous, static fn (array $a, array $b): int => $b['end'] <=> $a['end']);
        $chainStart = new \DateTimeImmutable($start);
        $effective = $chainStart;
        $counted = [];
        foreach ($previous as $row) {
            $prevEnd = new \DateTimeImmutable($row['end']);
            if ($prevEnd >= $chainStart) {
                continue;
            }
            $gapLimit = $prevEnd->modify('+6 months');
            if ($chainStart->modify('-1 day') > $gapLimit) {
                break;
            }
            $prevStart = new \DateTimeImmutable($row['start']);
            $days = (int) $prevStart->diff($prevEnd)->days + 1;
            $effective = $effective->modify("-{$days} days");
            $counted[] = $row;
            $chainStart = $prevStart;
        }

        return [$effective->format('Y-m-d'), $counted];
    }

    /** Počet celých let trvání od `$start` do `$end` včetně obou dnů. */
    public static function fullYears(string $start, string $end): int
    {
        $from = new \DateTimeImmutable($start);
        $to = new \DateTimeImmutable($end);
        $years = 0;
        while ($from->modify('+' . ($years + 1) . ' years -1 day') <= $to) {
            ++$years;
        }

        return $years;
    }

    /**
     * Částka v haléřích: násobek × průměrný měsíční výdělek, zaokrouhleno na
     * celé koruny nahoru. Zákon stanoví minimum, takže zaokrouhlení dolů by
     * minimum podlezlo.
     */
    public static function amount(int $multiple, int $monthlyAverageMinor): int
    {
        if ($multiple <= 0 || $monthlyAverageMinor <= 0) {
            return 0;
        }

        return intdiv($multiple * $monthlyAverageMinor + 99, 100) * 100;
    }
}
