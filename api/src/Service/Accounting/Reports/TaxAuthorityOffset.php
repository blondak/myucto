<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

/**
 * Souhrnné vykázání daňových pohledávek a závazků vůči finančnímu úřadu v rozvaze
 * (§ 58 odst. 2 vyhl. 500/2002 Sb.).
 *
 * Rozvaha jinak pohledávky a závazky nekompenzuje: přeplatek na jedné dani je v aktivech
 * (Stát — daňové pohledávky), nedoplatek na jiné v pasivech (Stát — daňové závazky).
 * Předpis ale za vzájemné zúčtování nepovažuje souhrnné vykázání pohledávek a závazků
 * vůči téže osobě se splatností do jednoho roku ve stejné měně; finanční úřad spravuje
 * daně na jednom osobním daňovém účtu. Firma, která tuto možnost použije, ji uvede
 * v příloze. Volba je proto per firma a výchozí je vypnutá.
 *
 * Započte se nejvýš menší ze dvou stran: aktiva i pasiva klesnou o stejnou částku, rozvaha
 * zůstane vyrovnaná a ani jedna strana nepřejde přes nulu. Dotace (346) nejsou daň a do
 * započtení nevstupují.
 */
final class TaxAuthorityOffset
{
    /** Syntetiky daní spravovaných finančním úřadem (daň z příjmů, ostatní přímé daně, DPH, ostatní daně). */
    public const PREFIXES = ['341', '342', '343', '344', '345'];

    public const LABEL = 'Souhrnné vykázání s daňovými závazky vůči finančnímu úřadu (§ 58 odst. 2 vyhl. 500/2002 Sb.)';

    /**
     * @param list<array<string,mixed>> $rows   řádky verze výkazu (kvůli section)
     * @param array<string, array{gross: float, correction: float, accounts: list<array<string,mixed>>}> $mapped výstup StatementMapper::map()
     * @return array{mapped: array<string, array{gross: float, correction: float, accounts: list<array<string,mixed>>}>, amount: float}
     */
    public static function apply(array $rows, array $mapped): array
    {
        $sectionByRow = [];
        foreach ($rows as $r) {
            $sectionByRow[(string) $r['row_code']] = (string) $r['section'];
        }

        $sides = ['assets' => [], 'liabilities' => []];
        foreach ($mapped as $rowCode => $v) {
            $section = $sectionByRow[(string) $rowCode] ?? '';
            if (!isset($sides[$section])) {
                continue;
            }
            $sum = 0.0;
            foreach ($v['accounts'] as $a) {
                if ((string) $a['target'] === 'gross' && (float) $a['amount'] > 0 && self::isTaxAccount((string) $a['account_code'])) {
                    $sum += (float) $a['amount'];
                }
            }
            if (self::cents($sum) > 0) {
                $sides[$section][(string) $rowCode] = round($sum, 2);
            }
        }

        $amount = round(min(array_sum($sides['assets']), array_sum($sides['liabilities'])), 2);
        if (self::cents($amount) <= 0) {
            return ['mapped' => $mapped, 'amount' => 0.0];
        }

        foreach ($sides as $contributions) {
            $left = $amount;
            foreach ($contributions as $rowCode => $available) {
                if (self::cents($left) <= 0) {
                    break;
                }
                $take = round(min($available, $left), 2);
                $left = round($left - $take, 2);
                $mapped[$rowCode]['gross'] = round($mapped[$rowCode]['gross'] - $take, 2);
                $mapped[$rowCode]['accounts'][] = [
                    'account_id'   => 0,
                    'account_code' => '',
                    'name'         => self::LABEL,
                    'amount'       => -$take,
                    'target'       => 'gross',
                ];
            }
        }

        return ['mapped' => $mapped, 'amount' => $amount];
    }

    public static function isTaxAccount(string $code): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
