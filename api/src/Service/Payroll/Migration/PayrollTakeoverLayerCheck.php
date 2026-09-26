<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Shoda dvou vrstev převzatých dat roku přechodu.
 *
 * Převzatý měsíc žije v MyÚčtu dvakrát a každá vrstva má jiné čtenáře:
 *
 *  - (A) počáteční stavy kumulací (`payroll_statutory_accumulator_openings`)
 *    čte roční zúčtování, potvrzení § 38j, mzdový list, vyúčtování daně
 *    a roční maximum sociálního pojištění,
 *  - (B) převzaté mzdy (`payroll_migration_reference_totals`) čte evidenční
 *    list důchodového pojištění, převzatý mzdový běh a kontrolní sestava.
 *
 * Plní se odděleně (mřížka a tabulka počátečních stavů vs. tabulka převzatých
 * mezd a převody) a nic dosud nehlídalo, že tvrdí totéž. Rozchod znamená, že
 * roční zúčtování počítá s jiným základem, než jaký šel do ELDP. Tahle třída
 * porovná to, co mají obě vrstvy společné, a ukáže rozdíly — nerozhoduje, která
 * strana je správně.
 */
final class PayrollTakeoverLayerCheck
{
    /**
     * Veličina => [pole rozpisu počátečního stavu (A), pole převzaté mzdy (B)].
     *
     * Základ daně (A) se porovnává s hrubou mzdou (B): převzaté mzdy základ daně
     * nenesou a u běžné mzdy jsou obě čísla stejná. Rozdíl tu proto může mít
     * legitimní důvod (osvobozený příjem), u ostatních veličin ne.
     *
     * @var array<string,array{0:list<string>,1:list<string>}>
     */
    public const METRICS = [
        'tax_base' => [['advance_base_minor_units', 'withholding_base_minor_units'], ['gross_minor']],
        'advance_tax' => [['advance_tax_minor_units'], ['advance_tax_minor']],
        'withholding_tax' => [['withholding_tax_minor_units'], ['withholding_tax_minor']],
        'tax_bonus' => [['tax_bonus_minor_units'], ['tax_bonus_minor']],
        'social_base' => [['social_assessment_base_minor_units'], ['social_base_minor']],
        'health_base' => [['health_assessment_base_minor_units'], ['health_base_minor']],
    ];

    /** Názvy veličin, jak jdou po drátě (klíče {@see METRICS}; shodu hlídá test). */
    public const METRIC_NAMES = [
        'tax_base',
        'advance_tax',
        'withholding_tax',
        'tax_bonus',
        'social_base',
        'health_base',
    ];

    public function __construct(
        private readonly PayrollTakeoverCoverage $coverage,
        private readonly PayrollTakeoverReader $reader,
    ) {}

    /**
     * @return array{
     *   differences:list<array{employee_id:int,employee_name:string,period:string,metric:string,opening_minor:int,takeover_minor:int,difference_minor:int}>,
     *   opening_only:list<array{employee_id:int,employee_name:string,periods:list<string>}>,
     *   takeover_only:list<array{employee_id:int,employee_name:string,periods:list<string>}>
     * }
     */
    public function check(int $supplierId, int $year): array
    {
        $openings = $this->coverage->openingMonths($supplierId, $year);
        $takeover = $this->reader->forSupplier($supplierId, $year);
        $takeoverMonths = $this->coverage->takeoverMonths($supplierId, $year);

        /** @var array<int,array<int,list<PayrollTakeoverMonth>>> $wages */
        $wages = [];
        foreach ($takeover->months as $month) {
            if ($month->employeeId === null) {
                continue;
            }
            $number = (int) substr($month->period, 5, 2);
            // Porovnávají se jen převzaté měsíce: za měsíc, který počítá
            // MyÚčto, počáteční stav být nemá a převzatá mzda je kontrola
            // přepočtu, kterou dělá kontrolní sestava.
            if (!in_array($number, $takeoverMonths, true)) {
                continue;
            }
            $wages[$month->employeeId][$number][] = $month;
        }

        $result = self::compare($openings, $wages, $year);
        $ids = [];
        foreach ([...$result['differences'], ...$result['opening_only'], ...$result['takeover_only']] as $row) {
            $ids[$row['employee_id']] = $row['employee_id'];
        }
        $names = $this->coverage->employeeNames($supplierId, array_values($ids));
        foreach (['differences', 'opening_only', 'takeover_only'] as $key) {
            foreach ($result[$key] as $index => $row) {
                $result[$key][$index]['employee_name'] = $names[$row['employee_id']] ?? ('#' . $row['employee_id']);
            }
        }

        return $result;
    }

    /**
     * Čisté porovnání — bez databáze, aby šlo testovat.
     *
     * @param array<int,array<int,array<string,int>>> $openings employee => měsíc => řádek rozpisu (A)
     * @param array<int,array<int,list<PayrollTakeoverMonth>>> $wages employee => měsíc => řádky vztahů (B)
     * @return array{
     *   differences:list<array{employee_id:int,employee_name:string,period:string,metric:string,opening_minor:int,takeover_minor:int,difference_minor:int}>,
     *   opening_only:list<array{employee_id:int,employee_name:string,periods:list<string>}>,
     *   takeover_only:list<array{employee_id:int,employee_name:string,periods:list<string>}>
     * }
     */
    public static function compare(array $openings, array $wages, int $year): array
    {
        $differences = [];
        $openingOnly = [];
        $takeoverOnly = [];
        $employees = array_unique([...array_keys($openings), ...array_keys($wages)]);
        sort($employees, SORT_NUMERIC);
        foreach ($employees as $employeeId) {
            $a = $openings[$employeeId] ?? [];
            $b = $wages[$employeeId] ?? [];
            $months = array_unique([...array_keys($a), ...array_keys($b)]);
            sort($months, SORT_NUMERIC);
            $onlyA = [];
            $onlyB = [];
            foreach ($months as $month) {
                $period = sprintf('%04d-%02d', $year, $month);
                if (!isset($b[$month])) {
                    $onlyA[] = $period;
                    continue;
                }
                if (!isset($a[$month])) {
                    $onlyB[] = $period;
                    continue;
                }
                foreach (self::METRICS as $metric => [$openingFields, $wageFields]) {
                    // Zdravotní základ: počáteční stav bez sloupce zdravotního
                    // pojištění ho netvrdí a hlášení JMHZ ho nenese — porovnává
                    // se jen tehdy, když ho mají obě strany.
                    if ($metric === 'health_base'
                        && (!array_key_exists('health_assessment_base_minor_units', $a[$month])
                            || self::hasSource($b[$month], PayrollMigrationReferenceTotalsWriter::SOURCE_JMHZ))
                    ) {
                        continue;
                    }
                    $opening = 0;
                    foreach ($openingFields as $field) {
                        $opening += (int) ($a[$month][$field] ?? 0);
                    }
                    $taken = 0;
                    foreach ($b[$month] as $row) {
                        $values = $row->toArray();
                        foreach ($wageFields as $field) {
                            $taken += (int) ($values[$field] ?? 0);
                        }
                    }
                    if ($opening !== $taken) {
                        $differences[] = [
                            'employee_id' => (int) $employeeId,
                            'employee_name' => '',
                            'period' => $period,
                            'metric' => $metric,
                            'opening_minor' => $opening,
                            'takeover_minor' => $taken,
                            'difference_minor' => $opening - $taken,
                        ];
                    }
                }
            }
            if ($onlyA !== []) {
                $openingOnly[] = ['employee_id' => (int) $employeeId, 'employee_name' => '', 'periods' => $onlyA];
            }
            if ($onlyB !== []) {
                $takeoverOnly[] = ['employee_id' => (int) $employeeId, 'employee_name' => '', 'periods' => $onlyB];
            }
        }

        return [
            'differences' => $differences,
            'opening_only' => $openingOnly,
            'takeover_only' => $takeoverOnly,
        ];
    }

    /** @param list<PayrollTakeoverMonth> $rows */
    private static function hasSource(array $rows, string $source): bool
    {
        foreach ($rows as $row) {
            if ($row->source === $source) {
                return true;
            }
        }

        return false;
    }
}
