<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Diff „náš výpočet vs. podané přiznání" (Featura A, `private/REAL_data_followup_UX.md` §A).
 * Čistá, testovatelná třída bez DB — vstup jsou řádky z {@see DppoReturnCalculator::compute()}
 * (`lines`) a struktura z {@see DppoEpoXmlParser::parse()}, výstup je řádek-po-řádku srovnání
 * pro FE tabulku s drill-downem (zelené/červené řádky).
 *
 * Tolerance 1 Kč (zaokrouhlovací šum mezi haléřovým vnitřním výpočtem a celokorunovým EPO XML).
 * Řádky, které EPO XML nemá vyplněné vůbec, se berou jako 0 (shodná konvence jako
 * {@see DppoXmlBuilder}: nulové atributy se v podání nevyplňují).
 */
final class DppoReconciliationDiffBuilder
{
    private const TOLERANCE = 1.0;

    /**
     * @param list<array{line:int,code:string,label:string,value:float,source:string}> $ourLines
     * @param array<int,float> $filedLines číslo řádku → hodnota z podaného XML
     * @return array{
     *   rows: list<array{line:int,code:string,label:string,our_value:float,filed_value:float,
     *     diff:float,match:bool,filed_present:bool}>,
     *   matched: int, mismatched: int, max_abs_diff: float, max_abs_diff_line: ?int,
     * }
     */
    public function build(array $ourLines, array $filedLines): array
    {
        $rows = [];
        $matched = 0;
        $mismatched = 0;
        $maxAbsDiff = 0.0;
        $maxAbsDiffLine = null;

        foreach ($ourLines as $l) {
            $line = (int) $l['line'];
            $our = round((float) $l['value'], 2);
            $present = array_key_exists($line, $filedLines);
            $filed = $present ? round($filedLines[$line], 2) : 0.0;
            $diff = round($our - $filed, 2);
            $isMatch = abs($diff) <= self::TOLERANCE;

            if ($isMatch) {
                $matched++;
            } else {
                $mismatched++;
            }
            if (abs($diff) > $maxAbsDiff) {
                $maxAbsDiff = abs($diff);
                $maxAbsDiffLine = $line;
            }

            $rows[] = [
                'line' => $line,
                'code' => (string) $l['code'],
                'label' => (string) $l['label'],
                'our_value' => $our,
                'filed_value' => $filed,
                'diff' => $diff,
                'match' => $isMatch,
                'filed_present' => $present,
            ];
        }

        return [
            'rows' => $rows,
            'matched' => $matched,
            'mismatched' => $mismatched,
            'max_abs_diff' => $maxAbsDiff,
            'max_abs_diff_line' => $maxAbsDiffLine,
        ];
    }
}
