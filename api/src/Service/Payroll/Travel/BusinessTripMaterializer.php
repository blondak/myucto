<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollBusinessTripRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDOException;

/**
 * Promítne schválené vyúčtování pracovní cesty do mzdových vstupů.
 *
 * Nezdaňovaná část do zákonného limitu jde na složku `CESTOVNI_NAHRADA_LIMIT`
 * (osvobozeno od daně, mimo vyměřovací základy i exekuční základ), nadlimitní
 * část na `CESTOVNI_NAHRADA_NADLIMIT` (zdanitelný příjem ve všech základech).
 * Opakované volání nevytvoří duplicitu — dedupe drží unikátní external_id
 * mzdového vstupu a unikátní zdrojová reference v payroll_travel_compensation_links.
 */
final class BusinessTripMaterializer
{
    public const COMPONENT_EXEMPT = 'CESTOVNI_NAHRADA_LIMIT';
    public const COMPONENT_TAXABLE = 'CESTOVNI_NAHRADA_NADLIMIT';
    private const SOURCE_SYSTEM = 'payroll_business_trip';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollBusinessTripRepository $trips,
        private readonly PayrollComponentRepository $components,
    ) {}

    /** @return array<string,mixed> */
    public function materialize(int $supplierId, int $tripId, ?int $userId): array
    {
        $this->components->ensureDefaults($supplierId);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $state = $this->trips->lock($supplierId, $tripId);
            if ($state === null) {
                $this->rollbackOwned($ownsTransaction);
                return ['status' => 'not_found'];
            }
            if (!in_array($state['status'], ['approved', 'settled'], true)) {
                throw new \DomainException(
                    'Do mzdy lze promítnout jen schválené vyúčtování pracovní cesty.',
                );
            }
            $trip = $this->trips->find($supplierId, $tripId)
                ?? throw new \RuntimeException('Pracovní cestu se nepodařilo načíst.');
            $periodStart = PayrollTimeValue::string(
                $trip['settlement_period_start'] ?? null,
                'settlement_period_start',
            );

            $created = [];
            $replayed = [];
            foreach ([
                'exempt' => [self::COMPONENT_EXEMPT, (int) $trip['exempt_total_minor']],
                'taxable' => [self::COMPONENT_TAXABLE, (int) $trip['taxable_total_minor']],
            ] as $part => [$code, $amount]) {
                if ($amount <= 0) {
                    continue;
                }
                $componentId = $this->componentId($supplierId, $code, $periodStart);
                $result = $this->upsertInput(
                    $supplierId,
                    $trip,
                    $periodStart,
                    $componentId,
                    $part,
                    $amount,
                    $userId,
                );
                $this->linkInput($supplierId, $tripId, $result['input_id'], $part);
                $row = [
                    'part' => $part,
                    'component_code' => $code,
                    'input_id' => $result['input_id'],
                    'amount_minor' => $amount,
                ];
                if ($result['created']) {
                    $created[] = $row;
                } else {
                    $replayed[] = $row;
                }
            }
            $this->trips->markSettled($supplierId, $tripId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOwned($ownsTransaction);
            throw $e;
        }

        return [
            'status' => 'materialized',
            'trip_id' => $tripId,
            'period' => substr($periodStart, 0, 7),
            'created_count' => count($created),
            'replayed_count' => count($replayed),
            'created' => $created,
            'replayed' => $replayed,
        ];
    }

    /**
     * @param array<string,mixed> $trip
     * @return array{input_id:int,created:bool}
     */
    private function upsertInput(
        int $supplierId,
        array $trip,
        string $periodStart,
        int $componentId,
        string $part,
        int $amount,
        ?int $userId,
    ): array {
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');
        $employmentId = PayrollTimeValue::int($trip['employment_id'] ?? null, 'employment_id');
        $externalId = "travel:{$tripId}:{$part}";
        $snapshot = [
            'business_trip_id' => $tripId,
            'classification' => $part,
            'country_code' => PayrollTimeValue::string(
                $trip['country_code'] ?? null,
                'country_code',
            ),
            'departure_at' => PayrollTimeValue::string(
                $trip['departure_at'] ?? null,
                'departure_at',
            ),
            'arrival_at' => PayrollTimeValue::string($trip['arrival_at'] ?? null, 'arrival_at'),
            'entitlement_total_minor' => (int) $trip['entitlement_total_minor'],
            'exempt_total_minor' => (int) $trip['exempt_total_minor'],
            'taxable_total_minor' => (int) $trip['taxable_total_minor'],
            'advance_minor' => (int) $trip['advance_minor'],
            'ruleset_id' => PayrollTimeValue::string($trip['ruleset_id'] ?? null, 'ruleset_id'),
            'calculation' => $trip['calculation'],
        ];
        $json = CanonicalJson::encode($snapshot);
        $hash = hash('sha256', $json, true);

        $pdo = $this->db->pdo();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_inputs
                    (supplier_id, employee_id, employment_id, component_id,
                     period_start, amount_minor, source_kind, external_id,
                     source_snapshot_json, source_snapshot_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, "travel", ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                PayrollTimeValue::int($trip['employee_id'] ?? null, 'employee_id'),
                $employmentId,
                $componentId,
                $periodStart,
                $amount,
                $externalId,
                $json,
                $hash,
                $userId,
            ]);

            return ['input_id' => (int) $pdo->lastInsertId(), 'created' => true];
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $existing = $pdo->prepare(
                'SELECT id
                   FROM payroll_inputs
                  WHERE supplier_id = ? AND employment_id = ?
                    AND period_start = ? AND source_kind = "travel"
                    AND external_id = ? AND status <> "cancelled"'
            );
            $existing->execute([$supplierId, $employmentId, $periodStart, $externalId]);
            $id = $existing->fetchColumn();
            if ($id === false) {
                throw $e;
            }

            return ['input_id' => PayrollTimeValue::int($id, 'input_id'), 'created' => false];
        }
    }

    private function linkInput(int $supplierId, int $tripId, int $inputId, string $part): void
    {
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO payroll_travel_compensation_links
                (supplier_id, input_id, trip_id, source_system, source_reference,
                 classification_status)
             VALUES (?, ?, ?, ?, ?, "classified")'
        )->execute([
            $supplierId,
            $inputId,
            $tripId,
            self::SOURCE_SYSTEM,
            "trip:{$tripId}:{$part}",
        ]);
    }

    private function componentId(int $supplierId, string $code, string $periodStart): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND is_active = 1
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $code, $periodStart, $periodStart]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \DomainException(
                "Mzdová složka {$code} není v období vyúčtování účinná.",
            );
        }

        return PayrollTimeValue::int($id, 'component_id');
    }

    private function rollbackOwned(bool $ownsTransaction): void
    {
        $pdo = $this->db->pdo();
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
