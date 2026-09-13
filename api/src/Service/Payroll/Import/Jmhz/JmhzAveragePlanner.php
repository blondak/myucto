<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Service\Payroll\Absence\AverageEarningCalculator;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Návrh průměrného výdělku z hlášení předchozího programu.
 *
 * Hotovou hodnotu 10345 `vydelekPrumernyHod` evidence uložit neumí — průměr
 * je v ní vždy VÝPOČET z hrubé mzdy a odpracované doby za rozhodné čtvrtletí
 * ({@see AverageEarningCalculator}). Pokrývá-li dávka všechny tři měsíce
 * čtvrtletí, založí se návrh ve stavu `manual_review` (schvaluje ho člověk
 * stejně jako návrh z vlastních běhů) a porovná se s průměrem v hlášení
 * následujícího čtvrtletí.
 *
 * Vstupy a jejich doložení:
 *  - **Odpracovaná doba** = Σ 10268 `odpracovaneHodiny/pocet`. Je to TÁŽ
 *    veličina jako `worked_millihours`, ze které
 *    {@see \MyInvoice\Service\Payroll\Absence\AverageEarningDerivationService}
 *    bere odpracované minuty (a kterou `JmhzScenario1XmlSerializer::workMonth()`
 *    píše do 10268), včetně přesčasu.
 *  - **Odpracované dny** = Σ 10267 `dnyOdpracovanePocet` (nepovinný prvek;
 *    bez něj návrh nevznikne — § 355 ZP pracuje s dny).
 *  - **Započitatelná mzda** = Σ 10328 `mzdaZuctovana`: datový slovník ji
 *    vymezuje jako mzdu zúčtovanou za odpracovanou dobu (tarif, odměny,
 *    příplatky) BEZ náhrad (10337–10342 stojí mimo ni), což je okruh § 353
 *    odst. 1 ZP i okruh složek, které evidence do průměru zahrnuje
 *    (`average_earning_treatment = included`). Nejde to ale doložit složku po
 *    složce, a proto návrh nevznikne, když hlášení ve čtvrtletí uvádí
 *    nepravidelné odměny (10331 — mohou být za delší období a potřebují
 *    poměrné rozpočítání podle § 358 ZP) nebo odměnu za pracovní pohotovost
 *    (10343 — stojí mimo 10328 a její zařazení import neposoudí). Návrh je
 *    vždy ke schválení, ne hotový průměr.
 */
final class JmhzAveragePlanner
{
    public function __construct(
        private readonly PayrollAbsenceValidator $validator,
        private readonly AverageEarningCalculator $calculator,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly RegistrationImportLookup $lookup,
    ) {}

    /**
     * @param list<array<string,mixed>> $plans
     * @return list<array<string,mixed>>
     */
    public function preview(int $supplierId, array $plans): array
    {
        return array_map(
            static fn (array $candidate): array => $candidate['public'],
            $this->candidates($supplierId, $plans),
        );
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @return array{created:int,skipped:list<array<string,mixed>>}
     */
    public function apply(int $supplierId, array $plans, ?int $userId): array
    {
        $created = 0;
        $skipped = [];
        foreach ($this->candidates($supplierId, $plans) as $candidate) {
            $public = $candidate['public'];
            if ($public['status'] !== 'ready') {
                $skipped[] = $this->skip($public, (string) $public['reason']);
                continue;
            }
            try {
                $this->averages->create(
                    $supplierId,
                    $candidate['data']['employment_id'],
                    $candidate['data']['applicable_year'],
                    $candidate['data']['applicable_quarter'],
                    $candidate['data']['decisive_from'],
                    $candidate['data']['decisive_to'],
                    $candidate['data']['gross_earnings_minor'],
                    $candidate['data']['longer_period_allocated_minor'],
                    $candidate['data']['worked_minutes'],
                    $candidate['data']['worked_days'],
                    $candidate['data']['rationale'],
                    $candidate['result'],
                    $this->rulesets->forDate(PayrollRulesetDomain::CompensationAverages, $candidate['application_date']),
                    $userId,
                );
                $created++;
            } catch (\InvalidArgumentException|\DomainException $e) {
                $skipped[] = $this->skip($public, $e->getMessage());
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Vstupy průměru ze tří měsíců rozhodného čtvrtletí.
     *
     * @param array<int,JmhzReportForm> $forms měsíc => formulář vztahu
     * @return array{gross_minor:int,worked_minutes:int,worked_days:int,reason:?string}
     */
    public static function quarterInputs(array $forms): array
    {
        $gross = 0;
        $minutes = 0;
        $days = 0;
        $fail = static fn (string $reason): array => [
            'gross_minor' => 0,
            'worked_minutes' => 0,
            'worked_days' => 0,
            'reason' => $reason,
        ];
        foreach ($forms as $month => $form) {
            $label = sprintf('%02d', $month);
            if ($form->wage === null || $form->workedMillihours === null) {
                return $fail("Formulář za měsíc {$label} nenese mzdu zúčtovanou (10328) a odpracované hodiny (10268).");
            }
            if (($form->irregularBonuses ?? 0) > 0) {
                return $fail("Hlášení za měsíc {$label} uvádí nepravidelné odměny (10331). Mohou být za delší období "
                    . 'než čtvrtletí a do průměru se rozpočítávají poměrně (§ 358 ZP); průměr zadejte ručně.');
            }
            if (($form->standbyPay ?? 0) > 0) {
                return $fail("Hlášení za měsíc {$label} uvádí odměnu za pracovní pohotovost (10343), která stojí mimo "
                    . 'mzdu zúčtovanou; zda do průměru patří, import neposoudí. Průměr zadejte ručně.');
            }
            if ($form->workedDays === null) {
                return $fail("Hlášení za měsíc {$label} neuvádí počet odpracovaných dnů (10267), bez něj průměr nejde "
                    . 'posoudit podle § 355 ZP.');
            }
            if (($form->workedMillihours * 60) % 1000 !== 0) {
                return $fail("Odpracované hodiny za měsíc {$label} nejdou převést na celé minuty.");
            }
            $gross += $form->wage * 100;
            $minutes += intdiv($form->workedMillihours * 60, 1000);
            $days += $form->workedDays;
        }

        return ['gross_minor' => $gross, 'worked_minutes' => $minutes, 'worked_days' => $days, 'reason' => null];
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @return list<array<string,mixed>>
     */
    private function candidates(int $supplierId, array $plans): array
    {
        $byEmployment = [];
        $files = [];
        foreach ($plans as $plan) {
            if (($plan['_effective'] ?? false) !== true || $plan['blocker'] !== null || $plan['_employment_id'] === null) {
                continue;
            }
            /** @var JmhzBatchItem $item */
            $item = $plan['_item'];
            if ($item->form->variant !== 'bezPriznaku') {
                continue;
            }
            $employmentId = (int) $plan['_employment_id'];
            $byEmployment[$employmentId][$item->file->year][$item->file->month] = $item->form;
            $files[$employmentId][$item->fileName] = true;
        }

        $result = [];
        foreach ($byEmployment as $employmentId => $years) {
            $existing = [];
            foreach ($this->averages->list($supplierId, $employmentId) as $row) {
                $existing[(int) $row['applicable_year'] . '-' . (int) $row['applicable_quarter']] = true;
            }
            ksort($years);
            foreach ($years as $year => $months) {
                for ($quarter = 1; $quarter <= 4; $quarter++) {
                    $forms = [];
                    foreach ([1, 2, 3] as $offset) {
                        $month = ($quarter - 1) * 3 + $offset;
                        if (isset($months[$month])) {
                            $forms[$month] = $months[$month];
                        }
                    }
                    if (count($forms) !== 3) {
                        continue;
                    }
                    $result[] = $this->candidate(
                        $supplierId,
                        $employmentId,
                        $year,
                        $quarter,
                        $forms,
                        $byEmployment[$employmentId],
                        $existing,
                        array_keys($files[$employmentId]),
                    );
                }
            }
        }

        return $result;
    }

    /**
     * @param array<int,JmhzReportForm> $forms
     * @param array<int,array<int,JmhzReportForm>> $allMonths
     * @param array<string,true> $existing
     * @param list<string> $fileNames
     * @return array<string,mixed>
     */
    private function candidate(
        int $supplierId,
        int $employmentId,
        int $year,
        int $quarter,
        array $forms,
        array $allMonths,
        array $existing,
        array $fileNames,
    ): array {
        [$applicableYear, $applicableQuarter] = $quarter === 4 ? [$year + 1, 1] : [$year, $quarter + 1];
        $employment = $this->lookup->employment($supplierId, $employmentId);
        $label = ($employment === null ? '' : ($this->lookup->employeeName($supplierId, (int) $employment['employee_id']) ?? '') . ' · ' . $employment['code']);
        $inputs = self::quarterInputs($forms);
        $public = [
            'employment_id' => $employmentId,
            'label' => trim($label, ' ·'),
            'year' => $applicableYear,
            'quarter' => $applicableQuarter,
            'gross_minor' => $inputs['reason'] === null ? $inputs['gross_minor'] : null,
            'worked_minutes' => $inputs['reason'] === null ? $inputs['worked_minutes'] : null,
            'average_hourly_minor' => null,
            'reported_hourly_minor' => $this->reported($allMonths, $applicableYear, $applicableQuarter),
            'status' => 'blocked',
            'reason' => $inputs['reason'],
        ];
        if (isset($existing[$applicableYear . '-' . $applicableQuarter])) {
            return ['public' => ['status' => 'exists', 'reason' => "Průměr pro {$applicableYear}/Q{$applicableQuarter} už "
                . 'je v evidenci (návrh nebo schválený); import další nezakládá.'] + $public];
        }
        if ($inputs['reason'] !== null) {
            return ['public' => $public];
        }
        $applicationDate = sprintf('%04d-%02d-01', $applicableYear, ($applicableQuarter - 1) * 3 + 1);
        $decisiveFrom = sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1);
        $decisiveTo = (new \DateTimeImmutable($applicationDate))->modify('-1 day')->format('Y-m-d');
        try {
            $data = $this->validator->average([
                'employment_id' => $employmentId,
                'applicable_year' => $applicableYear,
                'applicable_quarter' => $applicableQuarter,
                'decisive_from' => $decisiveFrom,
                'decisive_to' => $decisiveTo,
                'gross_earnings_minor' => $inputs['gross_minor'],
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => $inputs['worked_minutes'],
                'worked_days' => $inputs['worked_days'],
                'probable_hourly_minor' => null,
                'rationale' => mb_substr('Převzato z měsíčních hlášení JMHZ předchozího mzdového programu ('
                    . implode(', ', $fileNames) . '): mzda zúčtovaná (10328), odpracované hodiny (10268) a dny (10267) '
                    . "za {$year}/Q{$quarter}.", 0, 1000),
            ]);
            $result = $this->calculator->calculate(
                $applicationDate,
                $data['gross_earnings_minor'],
                $data['longer_period_allocated_minor'],
                $data['worked_minutes'],
                $data['worked_days'],
            );
        } catch (\InvalidArgumentException|\OverflowException $e) {
            return ['public' => ['reason' => $e->getMessage()] + $public];
        }
        $public['average_hourly_minor'] = $result->averageHourlyMinor;
        $public['status'] = 'ready';
        $reported = $public['reported_hourly_minor'];
        if ($reported !== null && $reported !== $result->averageHourlyMinor) {
            $public['reason'] = sprintf(
                'Průměr v hlášení (%s Kč) se liší od průměru spočteného z hlášení za rozhodné čtvrtletí (%s Kč). '
                . 'Před schválením ověřte, co předchozí program do průměru zahrnul.',
                number_format($reported / 100, 2, ',', ' '),
                number_format($result->averageHourlyMinor / 100, 2, ',', ' '),
            );
        }

        return [
            'public' => $public,
            'data' => $data,
            'result' => $result,
            'application_date' => $applicationDate,
        ];
    }

    /**
     * Průměr, který hlášení vykazuje v použitém čtvrtletí (první dostupný měsíc).
     *
     * @param array<int,array<int,JmhzReportForm>> $allMonths
     */
    private function reported(array $allMonths, int $year, int $quarter): ?int
    {
        foreach ([1, 2, 3] as $offset) {
            $form = $allMonths[$year][($quarter - 1) * 3 + $offset] ?? null;
            if ($form !== null && $form->averageHourlyMinor() !== null) {
                return $form->averageHourlyMinor();
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $public
     * @return array<string,mixed>
     */
    private function skip(array $public, string $reason): array
    {
        return [
            'employment_id' => $public['employment_id'],
            'label' => $public['label'],
            'year' => $public['year'],
            'quarter' => $public['quarter'],
            'reason' => $reason,
        ];
    }
}
