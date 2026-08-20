<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Tenantová evidence pracovních cest (MZ-08-W07). Cesta, její položky
 * a bezplatná jídla se ukládají v jedné transakci; složené cizí klíče
 * (supplier_id, id) drží izolaci firmy i na úrovni databáze.
 */
final class PayrollBusinessTripRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollBusinessTripDeletionRepository $deletion,
    ) {}

    /**
     * Strop stránky seznamu. Cesty jsou pracovní tabulka — čte se pár desítek
     * posledních, ne archiv firmy. Strop je nízký i proto, že {@see self::hydrate()}
     * dotahuje ke KAŽDÉ cestě položky a bezplatná jídla dvěma dalšími dotazy.
     */
    public const LIST_MAX_LIMIT = 100;

    public const LIST_DEFAULT_LIMIT = 50;

    /**
     * Seznam pracovních cest firmy. Filtr na období je volitelný, takže bez
     * stropu tenhle dotaz přečetl všechny cesty od vzniku firmy — a ke každé
     * ještě její položky a jídla. Stránkuje se s tvrdým stropem.
     *
     * Zúžení na jeden pracovní vztah (`$employmentId`) padá do TÉHOŽ dotazu jako
     * stránkování. Dokud filtroval prohlížeč nad načtenou stránkou, cesta z jiné
     * strany se tiše neprojevila.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function list(
        int $supplierId,
        ?string $periodStart = null,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?int $employmentId = null,
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        if ($employmentId !== null && $employmentId <= 0) {
            throw new \InvalidArgumentException('Vztah musí být kladné číslo.');
        }

        $params = [$supplierId];
        $where = '';
        if ($periodStart !== null) {
            $where = ' AND trip.settlement_period_start = ?';
            $params[] = $periodStart;
        }
        if ($employmentId !== null) {
            $where .= ' AND trip.employment_id = ?';
            $params[] = $employmentId;
        }
        $from = 'FROM payroll_business_trips trip
               JOIN payroll_employees employee
                 ON employee.supplier_id = trip.supplier_id
                AND employee.id = trip.employee_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = trip.supplier_id
                AND employment.id = trip.employment_id
              WHERE trip.supplier_id = ?' . $where;

        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*) ' . $from);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // `trip.id` na konci řazení je nutný rozstřel — dvě cesty se stejným
        // odjezdem by jinak mezi stránkami skákaly.
        $stmt = $this->db->pdo()->prepare(
            'SELECT trip.*, employee.full_name AS employee_name,
                    employment.code AS employment_code,
                    employment.relation_type
               ' . $from . '
              ORDER BY trip.departure_at_utc DESC, trip.id DESC
              LIMIT ? OFFSET ?'
        );
        $position = 1;
        foreach ($params as $param) {
            $stmt->bindValue($position++, $param);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $trips = PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'payroll_business_trips',
        );

        return [
            'items' => $this->deletion->decorate(
                $supplierId,
                array_map(fn (array $row): array => $this->hydrate($supplierId, $row), $trips),
            ),
            'total' => $total,
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT trip.*, employee.full_name AS employee_name,
                    employment.code AS employment_code,
                    employment.relation_type
               FROM payroll_business_trips trip
               JOIN payroll_employees employee
                 ON employee.supplier_id = trip.supplier_id
                AND employee.id = trip.employee_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = trip.supplier_id
                AND employment.id = trip.employment_id
              WHERE trip.supplier_id = ? AND trip.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : $this->deletion->decorateOne(
                $supplierId,
                $this->hydrate($supplierId, PayrollTimeValue::row($row, 'payroll_business_trip')),
            );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $data, ?int $userId): array
    {
        $this->assertValidReferences($supplierId, $data);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_business_trips
                    (supplier_id, employee_id, employment_id, country_code,
                     timezone_name, departure_at_utc, arrival_at_utc,
                     origin_place, destination_place,
                     purpose, transport_mode, meal_rate_band_1_minor,
                     meal_rate_band_2_minor, meal_rate_band_3_minor, advance_minor,
                     settlement_period_start, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $data['employee_id'],
                $data['employment_id'],
                $data['country_code'],
                $data['timezone_name'],
                $data['departure_at_utc'],
                $data['arrival_at_utc'],
                $data['origin_place'],
                $data['destination_place'],
                $data['purpose'],
                $data['transport_mode'],
                $data['meal_rate_band_1_minor'],
                $data['meal_rate_band_2_minor'],
                $data['meal_rate_band_3_minor'],
                $data['advance_minor'],
                $data['settlement_period_start'],
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->replaceChildren($supplierId, $id, $data);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOwned($ownsTransaction);
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Pracovní cestu se nepodařilo načíst.');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(int $supplierId, int $id, array $data, int $expectedVersion): ?array
    {
        $this->assertValidReferences($supplierId, $data);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $current = $this->lock($supplierId, $id);
            if ($current === null) {
                $this->rollbackOwned($ownsTransaction);
                return null;
            }
            if ($current['status'] !== 'draft') {
                throw new \DomainException('Upravit lze jen rozpracovanou pracovní cestu.');
            }
            if ($current['row_version'] !== $expectedVersion) {
                throw new PayrollBusinessTripConflictException($current['row_version']);
            }
            $stmt = $pdo->prepare(
                'UPDATE payroll_business_trips
                    SET employee_id = ?, employment_id = ?, country_code = ?,
                        timezone_name = ?,
                        departure_at_utc = ?, arrival_at_utc = ?, origin_place = ?,
                        destination_place = ?, purpose = ?, transport_mode = ?,
                        meal_rate_band_1_minor = ?, meal_rate_band_2_minor = ?,
                        meal_rate_band_3_minor = ?, advance_minor = ?,
                        settlement_period_start = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status = "draft"'
            );
            $stmt->execute([
                $data['employee_id'],
                $data['employment_id'],
                $data['country_code'],
                $data['timezone_name'],
                $data['departure_at_utc'],
                $data['arrival_at_utc'],
                $data['origin_place'],
                $data['destination_place'],
                $data['purpose'],
                $data['transport_mode'],
                $data['meal_rate_band_1_minor'],
                $data['meal_rate_band_2_minor'],
                $data['meal_rate_band_3_minor'],
                $data['advance_minor'],
                $data['settlement_period_start'],
                $supplierId,
                $id,
                $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollBusinessTripConflictException($current['row_version']);
            }
            $this->replaceChildren($supplierId, $id, $data);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOwned($ownsTransaction);
            throw $e;
        }

        return $this->find($supplierId, $id);
    }

    /**
     * Uloží schválené vyúčtování včetně neměnného protokolu výpočtu.
     *
     * @return array<string,mixed>|null
     */
    public function approve(
        int $supplierId,
        int $id,
        int $expectedVersion,
        string $rulesetId,
        string $calculationJson,
        int $entitlementMinor,
        int $exemptMinor,
        int $taxableMinor,
        ?int $userId,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $current = $this->lock($supplierId, $id);
            if ($current === null) {
                $this->rollbackOwned($ownsTransaction);
                return null;
            }
            if ($current['status'] !== 'draft') {
                throw new \DomainException('Schválit lze jen rozpracovanou pracovní cestu.');
            }
            if ($current['row_version'] !== $expectedVersion) {
                throw new PayrollBusinessTripConflictException($current['row_version']);
            }
            $stmt = $pdo->prepare(
                'UPDATE payroll_business_trips
                    SET status = "approved",
                        ruleset_id = ?,
                        calculation_json = ?,
                        calculation_hash = UNHEX(SHA2(?, 256)),
                        entitlement_total_minor = ?,
                        exempt_total_minor = ?,
                        taxable_total_minor = ?,
                        approved_by = ?,
                        approved_at = NOW(),
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status = "draft"'
            );
            $stmt->execute([
                $rulesetId,
                $calculationJson,
                $calculationJson,
                $entitlementMinor,
                $exemptMinor,
                $taxableMinor,
                $userId,
                $supplierId,
                $id,
                $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollBusinessTripConflictException($current['row_version']);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOwned($ownsTransaction);
            throw $e;
        }

        return $this->find($supplierId, $id);
    }

    public function markSettled(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_business_trips
                SET status = "settled", row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND status = "approved"'
        )->execute([$supplierId, $id]);
    }

    /** @return array{status:string,row_version:int}|null */
    public function lock(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, row_version
               FROM payroll_business_trips
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'status' => PayrollTimeValue::string($row['status'] ?? null, 'status'),
            'row_version' => PayrollTimeValue::int($row['row_version'] ?? null, 'row_version'),
        ];
    }

    /** @param array<string,mixed> $data */
    private function replaceChildren(int $supplierId, int $tripId, array $data): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'DELETE FROM payroll_business_trip_items WHERE supplier_id = ? AND trip_id = ?'
        )->execute([$supplierId, $tripId]);
        $pdo->prepare(
            'DELETE FROM payroll_business_trip_free_meals WHERE supplier_id = ? AND trip_id = ?'
        )->execute([$supplierId, $tripId]);

        $item = $pdo->prepare(
            'INSERT INTO payroll_business_trip_items
                (supplier_id, trip_id, item_kind, spent_on, description, amount_minor,
                 is_documented, document_reference, vehicle_kind, distance_m,
                 consumption_ml_per_100km, fuel_kind, documented_fuel_price_minor,
                 sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        /** @var list<array<string,mixed>> $items */
        $items = $data['items'] ?? [];
        foreach ($items as $row) {
            $item->execute([
                $supplierId,
                $tripId,
                $row['item_kind'],
                $row['spent_on'],
                $row['description'],
                $row['amount_minor'],
                $row['is_documented'] ? 1 : 0,
                $row['document_reference'],
                $row['vehicle_kind'],
                $row['distance_m'],
                $row['consumption_ml_per_100km'],
                $row['fuel_kind'],
                $row['documented_fuel_price_minor'],
                $row['sort_order'],
            ]);
        }

        $meal = $pdo->prepare(
            'INSERT INTO payroll_business_trip_free_meals
                (supplier_id, trip_id, meal_date, meal_count)
             VALUES (?, ?, ?, ?)'
        );
        /** @var array<string,int> $meals */
        $meals = $data['free_meals'] ?? [];
        foreach ($meals as $date => $count) {
            $meal->execute([$supplierId, $tripId, $date, $count]);
        }
    }

    /** @param array<string,mixed> $data */
    private function assertValidReferences(int $supplierId, array $data): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employments employment
              WHERE employment.supplier_id = ?
                AND employment.id = ?
                AND employment.employee_id = ?'
        );
        $stmt->execute([$supplierId, $data['employment_id'], $data['employee_id']]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Zaměstnanec nebo pracovní vztah nepatří této firmě.'
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(int $supplierId, array $row): array
    {
        $id = PayrollTimeValue::int($row['id'] ?? null, 'id');
        foreach ([
            'id',
            'supplier_id',
            'employee_id',
            'employment_id',
            'meal_rate_band_1_minor',
            'meal_rate_band_2_minor',
            'meal_rate_band_3_minor',
            'advance_minor',
            'entitlement_total_minor',
            'exempt_total_minor',
            'taxable_total_minor',
            'row_version',
            'created_by',
            'approved_by',
        ] as $key) {
            if (($row[$key] ?? null) !== null) {
                $row[$key] = PayrollTimeValue::int($row[$key], $key);
            }
        }
        // Uložený je UTC instant + zóna (vzor směn). Místní čas — to, co uživatel
        // do formuláře napsal — se z nich odvozuje TADY, na jediném místě: čte ho
        // jak výpočet stravného (pásma podle místních kalendářních dnů), tak
        // seznam cest v prohlížeči.
        $timezone = PayrollTimeValue::string($row['timezone_name'] ?? null, 'timezone_name');
        foreach (['departure_at', 'arrival_at'] as $moment) {
            $row[$moment . '_local'] = PayrollTimeValue::localMoment(
                PayrollTimeValue::string($row[$moment . '_utc'] ?? null, $moment . '_utc'),
                $timezone,
            );
        }
        unset($row['calculation_hash']);
        $row['calculation'] = $row['calculation_json'] === null
            ? null
            : json_decode((string) $row['calculation_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['calculation_json']);
        $row['items'] = $this->items($supplierId, $id);
        $row['free_meals'] = $this->freeMeals($supplierId, $id);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function items(int $supplierId, int $tripId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_business_trip_items
              WHERE supplier_id = ? AND trip_id = ?
              ORDER BY sort_order, id'
        );
        $stmt->execute([$supplierId, $tripId]);

        return array_map(
            static function (array $row): array {
                foreach ([
                    'id',
                    'supplier_id',
                    'trip_id',
                    'amount_minor',
                    'distance_m',
                    'consumption_ml_per_100km',
                    'documented_fuel_price_minor',
                    'sort_order',
                ] as $key) {
                    if (($row[$key] ?? null) !== null) {
                        $row[$key] = PayrollTimeValue::int($row[$key], $key);
                    }
                }
                $row['is_documented'] = PayrollTimeValue::bool(
                    $row['is_documented'] ?? null,
                    'is_documented',
                );
                return $row;
            },
            PayrollTimeValue::rows(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                'payroll_business_trip_items',
            ),
        );
    }

    /** @return array<string,int> */
    private function freeMeals(int $supplierId, int $tripId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT meal_date, meal_count
               FROM payroll_business_trip_free_meals
              WHERE supplier_id = ? AND trip_id = ?
              ORDER BY meal_date'
        );
        $stmt->execute([$supplierId, $tripId]);
        $meals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $meals[PayrollTimeValue::string($row['meal_date'] ?? null, 'meal_date')] =
                PayrollTimeValue::int($row['meal_count'] ?? null, 'meal_count');
        }

        return $meals;
    }

    private function rollbackOwned(bool $ownsTransaction): void
    {
        $pdo = $this->db->pdo();
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
