<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPerson;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRecord;

/**
 * Překlad řady přijatých měsíčních hlášení do kanonické podoby převzatých mezd
 * ({@see PayrollTakeoverRecord}, {@see PayrollMigrationReferenceTotals}). Nic nečte
 * ani nezapisuje — zápis dělají společné zapisovače převodů mezd, stejně jako
 * u PAMICA a PREMIER.
 *
 * Hlášení je zdroj „zvenku": nese úhrny, doby a identifikátory, ale ne kartu
 * osoby, účty ani nepřítomnosti s daty. Kanonická podoba se proto plní jen tím,
 * co z hlášení plyne:
 *
 *  - nástup a skončení vztahu z řady měsíců ({@see JmhzEmploymentHistory}),
 *  - sjednaná měsíční mzda, když ji tarif plně odpracovaných měsíců dokládá,
 *  - průměrný výdělek čtvrtletí, se kterým předchozí program počítal (10345 platí
 *    pro čtvrtletí měsíce hlášení),
 *  - pracoviště JMHZ, OIČ a ID PPV,
 *  - čerpání dovolené po měsících (hlášení nenese zůstatek, jen hodiny dovolené).
 *
 * Údaje na formuláři (prohlášení, slevy, děti, podmínky vztahu) přebírá import
 * hlášení po formulářích ({@see JmhzReportPlanner}).
 */
final class JmhzPayrollTakeover
{
    public const LABEL = 'hlášení JMHZ';

    public static function policy(): PayrollTakeoverPolicy
    {
        return new PayrollTakeoverPolicy(
            sourceKey: 'jmhz',
            label: self::LABEL,
            strict: true,
            countPlannedTermination: false,
            ignoreEndBeforeStart: true,
        );
    }

    /**
     * @param array<string,mixed> $row pracovní vztah v evidenci (`RegistrationImportLookup::employment()`)
     * @param string $startPeriod první měsíc vedení mezd v MyÚčtu (`YYYY-MM`)
     */
    public static function record(
        JmhzEmploymentHistory $history,
        string $key,
        int $employeeId,
        array $row,
        string $startPeriod,
    ): PayrollTakeoverRecord {
        $months = array_filter(
            $history->months($key),
            static fn (string $period): bool => $period < $startPeriod,
            ARRAY_FILTER_USE_KEY,
        );
        $latest = $months === [] ? null : end($months);
        $identity = ['oic' => null, 'id_ppv' => null, 'birth_date' => null];
        foreach (array_reverse($months) as $item) {
            $identity['oic'] ??= $item->form->personIdentifier;
            $identity['id_ppv'] ??= $item->form->employmentIdentifier;
            $identity['birth_date'] ??= $item->form->birthDate;
        }
        $start = $history->start($key);
        $end = $history->end($key);
        $salary = $history->monthlySalary($key);
        $workplace = $latest?->form->workplace;

        return new PayrollTakeoverRecord(
            new PayrollTakeoverPerson(
                key: 'employee:' . $employeeId,
                identity: $identity['birth_date'] === null ? [] : ['birth_date' => $identity['birth_date']],
            ),
            new PayrollTakeoverEmployment(
                personalNumber: (string) $row['code'],
                relationKey: 'employment:' . $row['id'],
                start: $start['on'] ?? null,
                end: $end !== null && $end['period'] < $startPeriod ? $end['on'] : null,
                monthlyWages: $salary === null || $salary['period'] >= $startPeriod ? [] : [[
                    'from' => $salary['period'] . '-01',
                    'amount' => (float) $salary['amount'],
                    'prorated' => false,
                ]],
                averages: self::averages($months),
                transferStart: $months === [] ? $startPeriod : (string) array_key_first($months),
                workplace: $workplace !== null && $workplace['city'] !== '' && $workplace['municipality_code'] !== ''
                    ? [
                        'work_place' => mb_substr($workplace['city'], 0, 255),
                        'municipality_code' => $workplace['municipality_code'],
                        'country_code' => $workplace['country_code'] !== '' ? $workplace['country_code'] : 'CZ',
                        'regular_workplace' => null,
                    ]
                    : null,
                oic: $identity['oic'],
                idPpv: $identity['id_ppv'],
                leaveTaken: self::leaveTaken($months),
            ),
        );
    }

    /**
     * Převzatý měsíc jednoho vztahu.
     *
     * | převzatý měsíc (za vztah)   | hlášení                                              |
     * |-----------------------------|------------------------------------------------------|
     * | hrubá mzda                  | `prijem/dan/zakladDane` vztahu; formulář se souhrnnými daty osoby k tomu nese i osvobozené příjmy: `zuctovanoCelkem` − Σ `zakladDane` osoby |
     * | čistá mzda, zdravotní pojištění, záloha, bonus, srážková daň | souhrnná data osoby (`mzdaCista`, `zdravPojZamestnanec`/`zdravPojZamestnavatel`, `danZalohaPoSleve`, `danBonus`, `srazenaDan`) — jen na formuláři, který je nese |
     * | vyměřovací základ, sociální pojištění | `castkaOdvodPojistneho`, `pojisteniZamestnanec`, `pojisteniZamestnavatel` vztahu |
     * | dny účasti, vyloučené doby  | Σ `pocetDnu`, Σ `vylouceneDobyCelkem` přes sekce ELDP |
     * | odpracované dny a hodiny    | `dnyOdpracovanePocet`, `odpracovaneHodiny/pocet`     |
     * | k výplatě                   | čistá mzda, jen když hlášení srážky nevykazuje (`srazkyZeMzdyEvidovany`) |
     *
     * Součet hrubé mzdy přes vztahy osoby tak dá úhrn zúčtovaných příjmů
     * `zuctovanoCelkem` — tentýž, který předchozí program zaúčtoval. Zdravotní
     * vyměřovací základ a výši srážek hlášení nenese, zůstávají nulové.
     *
     * @param list<JmhzBatchItem> $personItems formuláře téže osoby v témže měsíci (včetně `$item`)
     * @param array<string,mixed> $row pracovní vztah v evidenci
     */
    public static function totals(
        JmhzBatchItem $item,
        array $personItems,
        int $employeeId,
        array $row,
        ?string $activityCode,
    ): PayrollMigrationReferenceTotals {
        $form = $item->form;
        $czk = static fn (?int $value): int => ($value ?? 0) * 100;
        $gross = $czk($form->taxableIncome);
        if ($form->hasSummary) {
            $taxable = 0;
            foreach ($personItems as $sibling) {
                $taxable += $sibling->form->taxableIncome ?? 0;
            }
            $gross += max(0, $czk($form->incomeTotal) - $taxable * 100);
        }
        $summary = static fn (?int $value): int => $form->hasSummary ? ($value ?? 0) * 100 : 0;
        $net = $summary($form->netWage);
        $insuranceDays = min(31, (int) ($form->eldp['insurance_days'] ?? 0));

        return new PayrollMigrationReferenceTotals(
            $item->period(),
            'employee:' . $employeeId,
            'employment:' . $row['id'],
            $employeeId,
            (int) $row['id'],
            $gross,
            $net,
            $czk($form->socialBase),
            0,
            $czk($form->employeeSocial),
            $summary($form->employeeHealth),
            $czk($form->employerSocial),
            $summary($form->employerHealth),
            $summary($form->advance['after_credits'] ?? null),
            $summary($form->withholding['tax'] ?? null),
            $summary($form->advance['bonus'] ?? null),
            new PayrollMigrationTakeoverFacts(
                relationshipStartDate: $row['actual_start_date'] ?? $row['start_date'],
                relationshipEndDate: $row['end_date'],
                relationType: $row['relation_type'],
                activityCode: $activityCode,
                pensionParticipation: $insuranceDays > 0,
                insuranceDays: $insuranceDays,
                excludedDays: min(31, (int) ($form->eldp['excluded_days'] ?? 0)),
                workedDaysHundredths: ($form->workedDays ?? 0) * 100,
                workedMinutes: self::minutes($form->workedMillihours),
                netPayableMinor: $form->hasSummary && $form->deductionsRecorded === false ? $net : 0,
            ),
        );
    }

    /**
     * Průměr každého čtvrtletí z nejpozdějšího měsíce hlášení v něm. Rozhodné období
     * hlášení nenese; zapisovač doplní předchozí kalendářní čtvrtletí (§ 354 ZP).
     *
     * @param array<string,JmhzBatchItem> $months
     * @return list<array{year:int,quarter:int,hourly:float,from:string,to:string,gross:float,worked:float,days:float}>
     */
    private static function averages(array $months): array
    {
        $byQuarter = [];
        foreach ($months as $period => $item) {
            $milli = $item->form->averageHourlyMilli;
            if ($item->file->lenient || $milli === null || $milli <= 0) {
                continue;
            }
            $year = (int) substr($period, 0, 4);
            $quarter = intdiv((int) substr($period, 5, 2) - 1, 3) + 1;
            $byQuarter[$year * 10 + $quarter] = [
                'year' => $year,
                'quarter' => $quarter,
                'hourly' => $milli / 1000,
                'from' => '',
                'to' => '',
                'gross' => 0.0,
                'worked' => 0.0,
                'days' => 0.0,
            ];
        }
        ksort($byQuarter);

        return array_values($byQuarter);
    }

    /**
     * @param array<string,JmhzBatchItem> $months
     * @return list<array{period:string,minutes:int}>
     */
    private static function leaveTaken(array $months): array
    {
        $taken = [];
        foreach ($months as $period => $item) {
            $minutes = $item->file->lenient ? 0 : self::minutes($item->form->leaveMillihours);
            if ($minutes > 0) {
                $taken[] = ['period' => (string) $period, 'minutes' => $minutes];
            }
        }

        return $taken;
    }

    /** Tisíciny hodiny na celé minuty (zaokrouhlení na nejbližší minutu). */
    public static function minutes(?int $millihours): int
    {
        return $millihours === null || $millihours <= 0 ? 0 : intdiv($millihours * 60 + 500, 1000);
    }
}
