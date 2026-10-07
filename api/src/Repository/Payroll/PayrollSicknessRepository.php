<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\SicknessCompensationResult;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollSicknessRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAbsenceRepository $absences,
    ) {}

    /**
     * Uloží výpočet náhrady mzdy při DPN jako neměnný důkaz.
     *
     * Bez potvrzené účasti na pojištění (DPN bez nároku, § 15a zák. č. 187/2006 Sb.)
     * se výpočet ukládá také, jen s nulovou náhradou: okno § 192 ZP z něj čte evidenční
     * list a krácení mzdy. Vyloučení souběžné dávky se vyžaduje jen tam, kde náhrada
     * náleží — u DPN bez nároku nemá co vylučovat.
     *
     * @param array<string,mixed> $absence
     * @return array<string,mixed>
     */
    public function record(
        array $absence,
        bool $firstDayFullyWorked,
        bool $insuranceEligibilityConfirmed,
        bool $conflictingBenefitExcluded,
        SicknessCompensationResult $result,
        ?int $userId,
    ): array {
        if ($insuranceEligibilityConfirmed && !$conflictingBenefitExcluded) {
            throw new \InvalidArgumentException(
                'Náhradu mzdy při DPN lze schválit až po vyloučení souběžné dávky.'
            );
        }
        if (!$insuranceEligibilityConfirmed && $result->compensationMinor !== 0) {
            throw new \InvalidArgumentException(
                'DPN bez nároku na nemocenské nemá náhradu mzdy (§ 192 odst. 1 ZP).'
            );
        }
        // Stejné okno § 192 ZP jako ve směnových segmentech i v krácení mzdy — jedno
        // místo pro vyčerpané dny i pro konec vztahu, aby se uložené okno a spočítané
        // segmenty nerozešly. Prázdné okno se ukládá jako den před svým začátkem.
        $bounds = $this->absences->absenceBounds($absence, $firstDayFullyWorked);
        $from = $bounds['from'];
        $to = $bounds['window_to'] < $from ? $from->modify('-1 day') : $bounds['window_to'];
        $reduction = $result->reduction();

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_sickness_events
                (supplier_id, absence_id, first_day_fully_worked,
                 insurance_eligibility_confirmed, conflicting_benefit_excluded,
                 average_snapshot_id, compensation_window_from, compensation_window_to,
                 reduced_hourly_minor, compensation_minor, compensation_reduction,
                 compensation_reduction_basis_points, compensation_reduction_minor,
                 compensation_reduction_reason, support_status,
                 ruleset_id, ruleset_hash, calculation_trace, calculated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $absence['supplier_id'], $absence['id'], $firstDayFullyWorked ? 1 : 0,
            $insuranceEligibilityConfirmed ? 1 : 0, $conflictingBenefitExcluded ? 1 : 0,
            $absence['average_snapshot_id'] ?? null,
            $from->format('Y-m-d'),
            $to->format('Y-m-d'), $result->reducedHourlyMinor, $result->compensationMinor,
            $reduction->kind, $reduction->basisPoints, $reduction->amountMinor, $reduction->reason,
            $result->supportStatus, $result->rulesetId, $result->rulesetHash,
            CanonicalJson::encode($result->trace), $userId,
        ]);
        $eventId = (int) $this->db->pdo()->lastInsertId();
        $segmentStmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_sickness_compensation_segments
                (supplier_id, sickness_event_id, shift_id, local_date, planned_minutes,
                 eligible_minutes, hourly_average_minor, reduced_hourly_minor,
                 compensation_minor, trace)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($result->segments as $segment) {
            $segmentStmt->execute([
                $absence['supplier_id'], $eventId, $segment['shift_id'], $segment['local_date'],
                $segment['planned_minutes'], $segment['eligible_minutes'],
                $segment['hourly_average_minor'], $segment['reduced_hourly_minor'],
                $segment['compensation_minor'], CanonicalJson::encode($segment),
            ]);
        }
        return $this->find((int) $absence['supplier_id'], $eventId)
            ?? throw new \RuntimeException('Výpočet náhrady DPN nebyl nalezen.');
    }

    /**
     * Potvrzení „první plánovaná směna byla celá odpracována" u schválené
     * neschopnosti; `null`, když k absenci výpočet náhrady není.
     */
    public function firstDayFullyWorkedForAbsence(int $supplierId, int $absenceId): ?bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT first_day_fully_worked FROM payroll_sickness_events
              WHERE supplier_id = ? AND absence_id = ?
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $absenceId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (bool) $value;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_sickness_events WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($event)) {
            return null;
        }
        $segments = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_sickness_compensation_segments
              WHERE supplier_id = ? AND sickness_event_id = ? ORDER BY local_date, id'
        );
        $segments->execute([$supplierId, $id]);
        $event['segments'] = array_map(static function (array $row): array {
            foreach (['id', 'supplier_id', 'sickness_event_id', 'shift_id', 'planned_minutes',
                'eligible_minutes', 'hourly_average_minor', 'reduced_hourly_minor',
                'compensation_minor'] as $key) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
            $row['trace'] = json_decode((string) $row['trace'], true, flags: JSON_THROW_ON_ERROR);
            return $row;
        }, $segments->fetchAll(PDO::FETCH_ASSOC));
        foreach (['id', 'supplier_id', 'absence_id', 'reduced_hourly_minor',
            'compensation_minor', 'row_version'] as $key) {
            $event[$key] = (int) $event[$key];
        }
        foreach (['average_snapshot_id', 'compensation_reduction_basis_points', 'compensation_reduction_minor'] as $key) {
            if (array_key_exists($key, $event)) {
                $event[$key] = $event[$key] === null ? null : (int) $event[$key];
            }
        }
        $event['first_day_fully_worked'] = (bool) $event['first_day_fully_worked'];
        $event['insurance_eligibility_confirmed'] = (bool) $event['insurance_eligibility_confirmed'];
        $event['conflicting_benefit_excluded'] = (bool) $event['conflicting_benefit_excluded'];
        $event['calculation_trace'] = json_decode(
            (string) $event['calculation_trace'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        return $event;
    }
}
