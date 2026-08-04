<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollRecurringAmountCalculator;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollQuickInputRepository
{
    private const BASE_CODE = 'MZDA_MESICNI';
    private const OVERTIME_CODE = 'PREMIE_PRIPLATKY';
    private const BONUS_CODE = 'ODMENA';
    private const EXTERNAL_PREFIX = 'quick-monthly:';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollRecurringAmountCalculator $recurringAmounts,
    ) {}

    /** @return array{period:string,items:list<array<string,mixed>>} */
    public function month(int $supplierId, string $period): array
    {
        $periodStart = $period . '-01';
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $quarter = intdiv((int) substr($period, 5, 2) - 1, 3) + 1;
        $year = (int) substr($period, 0, 4);
        $this->components->list($supplierId, $periodStart);

        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id AS employment_id, employment.employee_id,
                    employment.code AS employment_code, employment.relation_type,
                    employment.monthly_gross_minor, employment.start_date,
                    employment.actual_start_date, employment.end_date,
                    employee.full_name,
                    (
                        SELECT identifier.value_masked
                          FROM payroll_person_identifiers identifier
                         WHERE identifier.supplier_id = employment.supplier_id
                           AND identifier.employee_id = employment.employee_id
                           AND identifier.identifier_type = "birth_number"
                         ORDER BY identifier.id DESC
                         LIMIT 1
                    ) AS birth_number_masked,
                    average.id AS overtime_average_snapshot_id,
                    average.row_version AS overtime_average_snapshot_version,
                    average.average_hourly_minor AS overtime_hourly_rate_minor
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
          LEFT JOIN (
                    SELECT ranked.*
                      FROM (
                            SELECT snapshot.*,
                                   ROW_NUMBER() OVER (
                                     PARTITION BY snapshot.supplier_id,
                                                  snapshot.employment_id,
                                                  snapshot.applicable_year,
                                                  snapshot.applicable_quarter
                                     ORDER BY snapshot.revision_no DESC, snapshot.id DESC
                                   ) AS position_no
                              FROM payroll_average_earning_snapshots snapshot
                             WHERE snapshot.status = "approved"
                               AND snapshot.support_status = "supported"
                           ) ranked
                     WHERE ranked.position_no = 1
                    ) average
                 ON average.supplier_id = employment.supplier_id
                AND average.employment_id = employment.id
                AND average.applicable_year = ?
                AND average.applicable_quarter = ?
              WHERE employment.supplier_id = ?
                AND employment.status IN ("active", "suspended", "ended")
                AND COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1
                           THEN "1900-01-01" ELSE NULL END
                    ) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
              ORDER BY employee.full_name, employment.is_primary DESC, employment.id'
        );
        $stmt->execute([$year, $quarter, $supplierId, $periodEnd, $periodStart]);
        $rows = PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'quick_employments');

        $inputStmt = $this->db->pdo()->prepare(
            'SELECT input.id, input.employment_id, input.amount_minor,
                    input.quantity_milliunits, input.source_kind, input.external_id,
                    input.status, input.row_version, input.source_snapshot_json,
                    component.code AS component_code,
                    component.component_kind
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.period_start = ?
                AND input.status <> "cancelled"
              ORDER BY input.id'
        );
        $inputStmt->execute([$supplierId, $periodStart]);
        $byEmployment = [];
        foreach (PayrollTimeValue::rows($inputStmt->fetchAll(PDO::FETCH_ASSOC), 'quick_inputs') as $input) {
            $byEmployment[(int) $input['employment_id']][] = $input;
        }

        $recurringStmt = $this->db->pdo()->prepare(
            'SELECT recurring.*, component.code AS component_code,
                    component.component_kind, employment.monthly_gross_minor
               FROM payroll_recurring_components recurring
               JOIN payroll_component_definitions component
                 ON component.supplier_id = recurring.supplier_id
                AND component.id = recurring.component_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = recurring.supplier_id
                AND employment.id = recurring.employment_id
              WHERE recurring.supplier_id = ?
                AND recurring.is_active = 1
                AND recurring.valid_from <= ?
                AND (recurring.valid_to IS NULL OR recurring.valid_to >= ?)
                AND component.is_active = 1
                AND component.valid_from <= ?
                AND (component.valid_to IS NULL OR component.valid_to >= ?)
              ORDER BY recurring.employment_id, recurring.id'
        );
        $recurringStmt->execute([
            $supplierId,
            $periodEnd,
            $periodStart,
            $periodEnd,
            $periodStart,
        ]);
        $recurringByEmployment = [];
        foreach (PayrollTimeValue::rows(
            $recurringStmt->fetchAll(PDO::FETCH_ASSOC),
            'quick_recurring',
        ) as $recurring) {
            $recurringByEmployment[(int) $recurring['employment_id']][] = $recurring;
        }

        $items = [];
        foreach ($rows as $row) {
            $employmentId = PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id');
            $items[] = $this->buildItem(
                $row,
                $byEmployment[$employmentId] ?? [],
                $recurringByEmployment[$employmentId] ?? [],
                $periodStart,
                $periodEnd,
            );
        }
        return ['period' => $period, 'items' => $items];
    }

    /**
     * @param list<array{
     *   employment_id:int,base_amount_minor:int,overtime_mode:string,
     *   overtime_hours_milli:?int,overtime_amount_minor:?int,bonus_amount_minor:int,
     *   overtime_average_snapshot_id:?int,overtime_average_snapshot_version:?int,
     *   versions:array{base:?int,overtime:?int,bonus:?int}
     * }> $rows
     * @return array{period:string,items:list<array<string,mixed>>}
     */
    public function save(
        int $supplierId,
        string $period,
        array $rows,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $current = $this->month($supplierId, $period);
            $items = [];
            foreach ($current['items'] as $item) {
                $items[(int) $item['employment_id']] = $item;
            }
            $componentIds = $this->componentIds($supplierId, $period . '-01');
            usort($rows, static fn(array $left, array $right): int =>
                $left['employment_id'] <=> $right['employment_id']);
            foreach ($rows as $row) {
                $employmentId = $row['employment_id'];
                $item = $items[$employmentId] ?? null;
                if ($item === null) {
                    throw new \InvalidArgumentException(
                        'Pracovní vztah nepatří této firmě nebo není v daném měsíci účinný.'
                    );
                }
                $this->lockEffectiveEmployment($supplierId, $employmentId, $period);
                if ((bool) $item['base_conflict']) {
                    throw new \DomainException(
                        'Základní mzda je v měsíci evidována rychlým i jiným vstupem. Duplicitní podklady nejprve opravte v měsíčních vstupech.'
                    );
                }
                if ((bool) $item['overtime_conflict'] || (bool) $item['bonus_conflict']) {
                    throw new \DomainException(
                        'Přesčas nebo odměna je v měsíci evidována rychlým i jiným vstupem. Duplicitní podklady nejprve opravte.'
                    );
                }
                if ((bool) $item['base_managed_elsewhere']) {
                    if ($row['base_amount_minor'] !== (int) $item['base_amount_minor']) {
                        throw new \DomainException(
                            'Základní mzdu v tomto měsíci spravuje jiný schválený nebo pravidelný vstup.'
                        );
                    }
                } else {
                    $this->upsert(
                        $supplierId,
                        (int) $item['employee_id'],
                        $employmentId,
                        $componentIds[self::BASE_CODE],
                        $period,
                        self::BASE_CODE,
                        $row['base_amount_minor'],
                        null,
                        $row['versions']['base'],
                        $userId,
                        null,
                    );
                }

                $overtimeAmount = $row['overtime_amount_minor'];
                $hours = $row['overtime_hours_milli'];
                $overtimeSource = null;
                if ((bool) $item['overtime_managed_elsewhere']) {
                    if ($row['overtime_mode'] !== 'amount'
                        || (int) $overtimeAmount !== (int) $item['overtime_amount_minor']) {
                        throw new \DomainException(
                            'Přesčas nebo příplatek v tomto měsíci spravuje jiný vstup.'
                        );
                    }
                } elseif ($row['overtime_mode'] === 'hours') {
                    $existing = $item['inputs']['overtime'];
                    $unchanged = is_array($existing)
                        && $existing['quantity_milliunits'] === $hours;
                    if ($unchanged) {
                        $overtimeAmount = (int) $existing['amount_minor'];
                        $overtimeSource = $existing['source_snapshot'] ?? null;
                    } else {
                        $rate = $item['overtime_hourly_rate_minor'];
                        if (!is_int($rate) || $rate <= 0
                            || $row['overtime_average_snapshot_id']
                                !== $item['overtime_average_snapshot_id']
                            || $row['overtime_average_snapshot_version']
                                !== $item['overtime_average_snapshot_version']) {
                            throw new \InvalidArgumentException(
                                'Schválený průměrný výdělek se změnil. Obnovte formulář a výpočet zkontrolujte.'
                            );
                        }
                        if ((int) $hours !== 0 && $rate > intdiv(PHP_INT_MAX, (int) $hours)) {
                            throw new \InvalidArgumentException(
                                'Výpočet přesčasu překračuje podporovaný rozsah.'
                            );
                        }
                        $overtimeAmount = RoundingMode::HalfUp->roundFraction(
                            $rate * (int) $hours,
                            800,
                        );
                        $overtimeSource = [
                            'schema_version' => 'payroll-quick-overtime-source.v1',
                            'average_snapshot_id' => $row['overtime_average_snapshot_id'],
                            'average_snapshot_row_version' =>
                                $row['overtime_average_snapshot_version'],
                            'average_hourly_minor' => $rate,
                            'overtime_hours_milli' => $hours,
                            'premium_basis_points' => 2_500,
                            'rounding' => 'half-up-minor-unit',
                        ];
                    }
                }
                if (!(bool) $item['overtime_managed_elsewhere']) {
                    $this->upsert(
                        $supplierId,
                        (int) $item['employee_id'],
                        $employmentId,
                        $componentIds[self::OVERTIME_CODE],
                        $period,
                        self::OVERTIME_CODE,
                        (int) $overtimeAmount,
                        $hours,
                        $row['versions']['overtime'],
                        $userId,
                        is_array($overtimeSource) ? $overtimeSource : null,
                    );
                }
                if ((bool) $item['bonus_managed_elsewhere']) {
                    if ($row['bonus_amount_minor'] !== (int) $item['bonus_amount_minor']) {
                        throw new \DomainException(
                            'Bonus nebo odměnu v tomto měsíci spravuje jiný vstup.'
                        );
                    }
                } else {
                    $this->upsert(
                        $supplierId,
                        (int) $item['employee_id'],
                        $employmentId,
                        $componentIds[self::BONUS_CODE],
                        $period,
                        self::BONUS_CODE,
                        $row['bonus_amount_minor'],
                        null,
                        $row['versions']['bonus'],
                        $userId,
                        null,
                    );
                }
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->rollback($pdo);
            }
            throw $e;
        }
        return $this->month($supplierId, $period);
    }

    /**
     * @param array<string,mixed> $employment
     * @param list<array<string,mixed>> $inputs
     * @param list<array<string,mixed>> $recurring
     * @return array<string,mixed>
     */
    private function buildItem(
        array $employment,
        array $inputs,
        array $recurring,
        string $periodStart,
        string $periodEnd,
    ): array {
        $quick = ['base' => null, 'overtime' => null, 'bonus' => null];
        $managed = ['base' => false, 'overtime' => false, 'bonus' => false];
        $managedAmounts = ['base' => 0, 'overtime' => 0, 'bonus' => 0];
        $blockers = [];
        $other = 0;
        foreach ($inputs as $input) {
            $code = PayrollTimeValue::string($input['component_code'] ?? null, 'component_code');
            $kind = PayrollTimeValue::string(
                $input['component_kind'] ?? null,
                'component_kind',
            );
            $externalId = $input['external_id'] === null
                ? null
                : PayrollTimeValue::string($input['external_id'], 'external_id');
            $quickSlot = match ($code) {
                self::BASE_CODE => 'base',
                self::OVERTIME_CODE => 'overtime',
                self::BONUS_CODE => 'bonus',
                default => null,
            };
            $isQuick = $quickSlot !== null
                && $externalId === self::EXTERNAL_PREFIX . $code;
            if ($isQuick) {
                $quick[$quickSlot] = $this->inputView($input);
                continue;
            }
            $managedSlot = $quickSlot ?? match ($kind) {
                'base_wage' => 'base',
                'premium' => 'overtime',
                'bonus', 'commission' => 'bonus',
                default => null,
            };
            $amount = PayrollTimeValue::int($input['amount_minor'] ?? null, 'amount_minor');
            if ($managedSlot !== null) {
                $managed[$managedSlot] = true;
                $managedAmounts[$managedSlot] += $amount;
            } elseif (in_array($kind, [
                'hourly_wage', 'task_wage', 'allowance', 'compensation',
                'severance', 'competitive_clause', 'backpay',
            ], true)) {
                $other += PayrollTimeValue::int($input['amount_minor'] ?? null, 'amount_minor');
            }
        }

        foreach ($recurring as $assignment) {
            $code = PayrollTimeValue::string(
                $assignment['component_code'] ?? null,
                'component_code',
            );
            $kind = PayrollTimeValue::string(
                $assignment['component_kind'] ?? null,
                'component_kind',
            );
            $slot = match ($code) {
                self::BASE_CODE => 'base',
                self::OVERTIME_CODE => 'overtime',
                self::BONUS_CODE => 'bonus',
                default => match ($kind) {
                    'base_wage' => 'base',
                    'premium' => 'overtime',
                    'bonus', 'commission' => 'bonus',
                    default => null,
                },
            };
            if ($slot === null) {
                continue;
            }
            $managed[$slot] = true;
            $calculation = $this->recurringAmounts->calculate($assignment, $periodStart);
            if ($calculation['status'] === 'supported'
                && is_int($calculation['amount_minor'])) {
                $managedAmounts[$slot] += $calculation['amount_minor'];
            } else {
                $blockers[] = "{$slot}_recurring_manual_review";
            }
        }

        $conflicts = [];
        foreach (['base', 'overtime', 'bonus'] as $slot) {
            $conflicts[$slot] = $managed[$slot] && $quick[$slot] !== null;
            if ($conflicts[$slot]) {
                $blockers[] = "{$slot}_conflict";
            } elseif ($managed[$slot]) {
                $blockers[] = "{$slot}_managed_elsewhere";
            }
        }

        $effectiveStart = $employment['actual_start_date']
            ?? $employment['start_date']
            ?? $periodStart;
        $effectiveEnd = $employment['end_date'] ?? $periodEnd;
        $partialMonth = (string) $effectiveStart > $periodStart
            || (string) $effectiveEnd < $periodEnd;
        $baseRequiresEntry = $partialMonth
            && !$managed['base']
            && $quick['base'] === null;
        if ($baseRequiresEntry) {
            $blockers[] = 'partial_month_base_required';
        }

        $base = $managed['base']
            ? $managedAmounts['base'] + ($quick['base']['amount_minor'] ?? 0)
            : ($quick['base']['amount_minor'] ?? (
                $baseRequiresEntry || $employment['monthly_gross_minor'] === null
                    ? 0
                    : PayrollTimeValue::int(
                        $employment['monthly_gross_minor'],
                        'monthly_gross_minor',
                    )
            ));
        $overtime = $managed['overtime']
            ? $managedAmounts['overtime'] + ($quick['overtime']['amount_minor'] ?? 0)
            : ($quick['overtime']['amount_minor'] ?? 0);
        $bonus = $managed['bonus']
            ? $managedAmounts['bonus'] + ($quick['bonus']['amount_minor'] ?? 0)
            : ($quick['bonus']['amount_minor'] ?? 0);
        $currentRate = $employment['overtime_hourly_rate_minor'] === null
            ? null
            : PayrollTimeValue::int(
                $employment['overtime_hourly_rate_minor'],
                'overtime_hourly_rate_minor',
            );
        $currentAverageId = $employment['overtime_average_snapshot_id'] === null
            ? null
            : PayrollTimeValue::int(
                $employment['overtime_average_snapshot_id'],
                'overtime_average_snapshot_id',
            );
        $currentAverageVersion = $employment['overtime_average_snapshot_version'] === null
            ? null
            : PayrollTimeValue::int(
                $employment['overtime_average_snapshot_version'],
                'overtime_average_snapshot_version',
            );
        $storedOvertimeSource = $quick['overtime']['source_snapshot'] ?? null;
        $usesStoredAverage = $quick['overtime'] !== null
            && $quick['overtime']['quantity_milliunits'] !== null
            && is_array($storedOvertimeSource);
        $rate = $usesStoredAverage
            ? ($storedOvertimeSource['average_hourly_minor'] ?? null)
            : $currentRate;
        $averageId = $usesStoredAverage
            ? ($storedOvertimeSource['average_snapshot_id'] ?? null)
            : $currentAverageId;
        $averageVersion = $usesStoredAverage
            ? ($storedOvertimeSource['average_snapshot_row_version'] ?? null)
            : $currentAverageVersion;
        return [
            'employee_id' => PayrollTimeValue::int($employment['employee_id'] ?? null, 'employee_id'),
            'employment_id' => PayrollTimeValue::int($employment['employment_id'] ?? null, 'employment_id'),
            'full_name' => PayrollTimeValue::string($employment['full_name'] ?? null, 'full_name'),
            'birth_number_masked' => $employment['birth_number_masked'] === null
                ? null
                : PayrollTimeValue::string($employment['birth_number_masked'], 'birth_number_masked'),
            'employment_code' => PayrollTimeValue::string($employment['employment_code'] ?? null, 'employment_code'),
            'relation_type' => PayrollTimeValue::string($employment['relation_type'] ?? null, 'relation_type'),
            'base_amount_minor' => $base,
            'base_managed_elsewhere' => $managed['base'],
            'base_conflict' => $conflicts['base'],
            'partial_month' => $partialMonth,
            'base_requires_entry' => $baseRequiresEntry,
            'overtime_mode' => ($quick['overtime']['quantity_milliunits'] ?? null) === null ? 'amount' : 'hours',
            'overtime_hours_milli' => $quick['overtime']['quantity_milliunits'] ?? null,
            'overtime_amount_minor' => $overtime,
            'overtime_hourly_rate_minor' => is_int($rate) ? $rate : null,
            'overtime_average_snapshot_id' => is_int($averageId) ? $averageId : null,
            'overtime_average_snapshot_version' =>
                is_int($averageVersion) ? $averageVersion : null,
            'overtime_hours_available' => is_int($rate) && $rate > 0,
            'overtime_managed_elsewhere' => $managed['overtime'],
            'overtime_conflict' => $conflicts['overtime'],
            'bonus_amount_minor' => $bonus,
            'bonus_managed_elsewhere' => $managed['bonus'],
            'bonus_conflict' => $conflicts['bonus'],
            'other_amount_minor' => $other,
            'gross_preview_minor' => $base + $overtime + $bonus + $other,
            'inputs' => $quick,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private function lockEffectiveEmployment(
        int $supplierId,
        int $employmentId,
        string $period,
    ): void {
        $periodStart = $period . '-01';
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ? AND employment.id = ?
                AND employment.status IN ("active", "suspended", "ended")
                AND COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1
                           THEN "1900-01-01" ELSE NULL END
                    ) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Pracovní vztah nepatří této firmě nebo není v daném měsíci účinný.'
            );
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   id:int,amount_minor:int,quantity_milliunits:?int,source_kind:string,
     *   status:string,row_version:int,source_snapshot:?array<string,mixed>
     * }
     */
    private function inputView(array $input): array
    {
        return [
            'id' => PayrollTimeValue::int($input['id'] ?? null, 'id'),
            'amount_minor' => PayrollTimeValue::int($input['amount_minor'] ?? null, 'amount_minor'),
            'quantity_milliunits' => $input['quantity_milliunits'] === null
                ? null
                : PayrollTimeValue::int($input['quantity_milliunits'], 'quantity_milliunits'),
            'source_kind' => PayrollTimeValue::string($input['source_kind'] ?? null, 'source_kind'),
            'status' => PayrollTimeValue::string($input['status'] ?? null, 'status'),
            'row_version' => PayrollTimeValue::int($input['row_version'] ?? null, 'row_version'),
            'source_snapshot' => $input['source_snapshot_json'] === null
                ? null
                : PayrollTimeValue::row(
                    json_decode(
                        PayrollTimeValue::string(
                            $input['source_snapshot_json'],
                            'source_snapshot_json',
                        ),
                        true,
                        flags: JSON_THROW_ON_ERROR,
                    ),
                    'source_snapshot',
                ),
        ];
    }

    private function rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /** @return array<string,int> */
    private function componentIds(int $supplierId, string $effectiveOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, id
               FROM payroll_component_definitions
              WHERE supplier_id = ?
                AND code IN ("MZDA_MESICNI", "PREMIE_PRIPLATKY", "ODMENA")
                AND is_active = 1
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)'
        );
        $stmt->execute([$supplierId, $effectiveOn, $effectiveOn]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['code']] = (int) $row['id'];
        }
        foreach ([self::BASE_CODE, self::OVERTIME_CODE, self::BONUS_CODE] as $code) {
            if (!isset($result[$code])) {
                throw new \InvalidArgumentException("Chybí účinná mzdová složka {$code}.");
            }
        }
        return $result;
    }

    /** @param array<string,mixed>|null $sourceSnapshot */
    private function upsert(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        int $componentId,
        string $period,
        string $componentCode,
        int $amountMinor,
        ?int $quantityMilliunits,
        ?int $expectedVersion,
        ?int $userId,
        ?array $sourceSnapshot,
    ): void {
        $periodStart = $period . '-01';
        $externalId = self::EXTERNAL_PREFIX . $componentCode;
        $find = $this->db->pdo()->prepare(
            'SELECT id, amount_minor, quantity_milliunits, status, row_version
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND source_kind = "manual" AND external_id = ?
                AND status <> "cancelled"
              FOR UPDATE'
        );
        $find->execute([$supplierId, $employmentId, $periodStart, $externalId]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            if ($expectedVersion !== null) {
                throw new PayrollInputConflictException($expectedVersion);
            }
            $this->inputs->create($supplierId, [
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'component_id' => $componentId,
                'period_start' => $periodStart,
                'source_period_start' => null,
                'amount_minor' => $amountMinor,
                'quantity_milliunits' => $quantityMilliunits,
                'source_kind' => 'manual',
                'external_id' => $externalId,
                'source_snapshot_json' => $sourceSnapshot === null
                    ? null
                    : CanonicalJson::encode($sourceSnapshot),
                'source_snapshot_hash' => $sourceSnapshot === null
                    ? null
                    : hash('sha256', CanonicalJson::encode($sourceSnapshot), true),
            ], $userId);
            return;
        }

        $currentAmount = (int) $row['amount_minor'];
        $currentQuantity = $row['quantity_milliunits'] === null ? null : (int) $row['quantity_milliunits'];
        $currentVersion = (int) $row['row_version'];
        if ($currentAmount === $amountMinor && $currentQuantity === $quantityMilliunits) {
            return;
        }
        if ((string) $row['status'] !== 'draft') {
            throw new \DomainException(
                'Schválený nebo uzamčený mzdový vstup nelze rychlým formulářem přepsat.'
            );
        }
        if ($expectedVersion === null || $expectedVersion !== $currentVersion) {
            throw new PayrollInputConflictException($currentVersion);
        }
        $updated = $this->inputs->update($supplierId, (int) $row['id'], [
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'component_id' => $componentId,
            'period_start' => $periodStart,
            'source_period_start' => null,
            'amount_minor' => $amountMinor,
            'quantity_milliunits' => $quantityMilliunits,
            'source_kind' => 'manual',
            'external_id' => $externalId,
            'source_snapshot_json' => $sourceSnapshot === null
                ? null
                : CanonicalJson::encode($sourceSnapshot),
            'source_snapshot_hash' => $sourceSnapshot === null
                ? null
                : hash('sha256', CanonicalJson::encode($sourceSnapshot), true),
        ], $currentVersion);
        if ($updated === null) {
            throw new PayrollInputConflictException($currentVersion);
        }
    }
}
