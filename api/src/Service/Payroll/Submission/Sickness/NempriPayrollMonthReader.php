<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;

/**
 * Měsíce rozhodného období ze schválených mzdových běhů MyÚčta.
 *
 * Zdroj je tentýž jako u jednotného měsíčního hlášení: aktuální schválená
 * revize běhu, její zmrazený vstup (nepřítomnosti vztahu) a výsledek
 * (sociální pojištění vztahu). Do NEMPRI se ale berou dvě veličiny jinak než
 * do hlášení:
 *
 *  - **započitatelný příjem** je vyměřovací základ PŘED krácením ročním
 *    maximem (§ 18 odst. 2 věta třetí zák. č. 187/2006 Sb.), hlášení nese
 *    zastropovaný 10477. U dohody a malého rozsahu v měsíci bez účasti se
 *    bere příjem posuzovaný pro účast (§ 19 odst. 9; 10476);
 *  - **vyloučené dny** § 18 odst. 7 odvozuje stejný modul jako hlášení
 *    ({@see EldpExcludedPeriodDeriver::deriveSection18()}). Když se z revize
 *    odvodit nedají, je hodnota `null` a resolver podání zastaví — nula by
 *    byla vymyšlený údaj.
 *
 * Měsíc, ve kterém vztah v revizi není, se nevrací vůbec.
 */
final readonly class NempriPayrollMonthReader
{
    public function __construct(
        private EldpExcludedPeriodDeriver $section18 = new EldpExcludedPeriodDeriver(),
    ) {}

    /**
     * @param list<array<string,mixed>> $revisions aktuální schválené revize běhů
     *        (`EldpStatementRepository::revisionsForYear()`)
     * @return array<string,array{income_minor:?int,excluded_days:?int}> klíčem je `YYYY-MM`
     */
    public function months(int $supplierId, int $employmentId, array $revisions): array
    {
        $months = [];
        foreach ($revisions as $revision) {
            $periodStart = $revision['period_start'] ?? null;
            if (!is_string($periodStart) || ($revision['status'] ?? null) !== 'approved') {
                continue;
            }
            if (($revision['current_revision_no'] ?? null) !== ($revision['revision_no'] ?? null)) {
                continue;
            }
            $input = self::snapshot($revision['input_snapshot_json'] ?? null, $revision['input_snapshot_hash'] ?? null);
            $result = self::snapshot($revision['result_snapshot_json'] ?? null, $revision['result_snapshot_hash'] ?? null);
            if (($input['supplier_id'] ?? null) !== $supplierId) {
                continue;
            }
            $entry = self::employmentEntry($input, $employmentId);
            if ($entry === null) {
                continue;
            }
            [$employeeId, $data] = $entry;
            $employment = is_array($data['employment'] ?? null) ? $data['employment'] : [];
            $start = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
            $end = $employment['end_date'] ?? null;
            $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
            $from = is_string($start) ? max($periodStart, $start) : $periodStart;
            $to = is_string($end) ? min($periodEnd, $end) : $periodEnd;
            if ($from > $to) {
                continue;
            }
            $absences = $data['absences'] ?? null;
            $excluded = null;
            if (is_array($absences) && array_is_list($absences)) {
                $derived = $this->section18->deriveSection18($absences, $from, $to);
                if ($derived['derivable'] === true) {
                    $excluded = min($derived['total'], EldpExcludedPeriodDeriver::inclusiveDays($from, $to));
                }
            }
            $months[substr($periodStart, 0, 7)] = [
                'income_minor' => self::countableIncome($result, $employeeId, $employmentId),
                'excluded_days' => $excluded,
            ];
        }
        ksort($months, SORT_STRING);

        return $months;
    }

    /**
     * Vyměřovací základ vztahu před krácením maximem, u měsíce bez účasti
     * příjem posuzovaný pro účast. `null` = výsledek ho nenese.
     *
     * @param array<string,mixed> $result
     */
    private static function countableIncome(array $result, int $employeeId, int $employmentId): ?int
    {
        foreach ((array) ($result['people'] ?? []) as $person) {
            if (!is_array($person) || ($person['employee_id'] ?? null) !== $employeeId) {
                continue;
            }
            $social = $person['statutory']['social_insurance'] ?? null;
            if (!is_array($social) || ($social['status'] ?? null) !== 'calculated') {
                return null;
            }
            foreach ((array) ($social['relationships'] ?? []) as $relationship) {
                if (!is_array($relationship)
                    || ($relationship['relationship_id'] ?? null) !== "employment:{$employmentId}"
                ) {
                    continue;
                }
                $base = $relationship['assessment_base_minor_units'] ?? null;
                if (!is_int($base) || $base < 0) {
                    return null;
                }
                $participation = $relationship['participation']['participation_income_minor_units'] ?? null;

                return max($base, is_int($participation) ? $participation : 0);
            }

            return null;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:int,1:array<string,mixed>}|null
     */
    private static function employmentEntry(array $input, int $employmentId): ?array
    {
        foreach ((array) ($input['people'] ?? []) as $person) {
            $employeeId = is_array($person) ? ($person['employee']['id'] ?? null) : null;
            if (!is_int($employeeId)) {
                continue;
            }
            foreach ((array) ($person['employments'] ?? []) as $entry) {
                if (is_array($entry) && ($entry['employment']['id'] ?? null) === $employmentId) {
                    return [$employeeId, $entry];
                }
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private static function snapshot(mixed $json, mixed $hash): array
    {
        if (!is_string($json) || !is_string($hash) || !hash_equals($hash, hash('sha256', $json))) {
            throw new SicknessException(
                'nempri_decisive_payroll_source_invalid',
                'Otisk zmrazené mzdové revize nesouhlasí, takže z ní nejde sestavit rozhodné období.',
            );
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
