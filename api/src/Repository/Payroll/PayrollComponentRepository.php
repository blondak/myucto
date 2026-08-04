<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class PayrollComponentRepository
{
    /** @var list<list<string|null>> */
    private const DEFAULTS = [
        ['MZDA_MESICNI', 'Základní měsíční mzda', 'base_wage', 'monetary', 'regular', 'included', 'included', 'included', 'included', 'included', 'included', 'included'],
        ['MZDA_HODINOVA', 'Základní hodinová mzda', 'hourly_wage', 'monetary', 'regular', 'included', 'included', 'included', 'included', 'included', 'included', 'included'],
        ['MZDA_UKOLOVA', 'Úkolová mzda', 'task_wage', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included'],
        ['ODMENA', 'Odměna', 'bonus', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included'],
        ['PREMIE_PRIPLATKY', 'Prémie a příplatky', 'premium', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included'],
        ['PROVIZE', 'Provize', 'commission', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included'],
        ['NAHRADA_MZDY', 'Náhrada mzdy', 'compensation', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included'],
        ['ODSTUPNE', 'Odstupné', 'severance', 'monetary', 'one_off', 'included', 'excluded', 'excluded', 'excluded', 'included', 'included', 'included'],
        ['NAHRADA_KONKURENCNI_DOLOZKA', 'Náhrada za konkurenční doložku', 'competitive_clause', 'monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included'],
        ['DOPLATEK_MZDY', 'Doplatek mzdy za minulé období', 'backpay', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included'],
        ['NEPENEZNI_PRIJEM', 'Nepeněžní příjem', 'non_cash', 'non_monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included'],
        ['PRISPEVEK_STRAVOVANI', 'Příspěvek na stravování', 'benefit_meal', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included'],
        ['SOUKROME_VOZIDLO', 'Soukromé užití vozidla', 'benefit_vehicle', 'non_monetary', 'regular', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included'],
        ['PRISPEVEK_PENZE_ZIVOTNI', 'Příspěvek na penzijní a životní produkty', 'benefit_pension', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included'],
        ['PRISPEVEK_DLOUHODOBA_PECE', 'Příspěvek na dlouhodobou péči', 'benefit_care', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included'],
        ['VZDELAVANI', 'Vzdělávání zaměstnance', 'benefit_education', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included'],
        ['REKREACE_VOLNY_CAS', 'Rekreace a volnočasový benefit', 'benefit_recreation', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included'],
        ['ZDRAVOTNI_BENEFIT', 'Zdravotní benefit', 'benefit_health', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included'],
        ['PRISPEVEK_RIZIKOVE_SPORENI', 'Povinný příspěvek na spoření u rizikové práce', 'risky_savings', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included'],
        ['CESTOVNI_NAHRADA', 'Cestovní náhrada', 'travel_reimbursement', 'monetary', 'one_off', 'manual_review', 'excluded', 'excluded', 'excluded', 'excluded', 'manual_review', 'included'],
    ];

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, ?string $effectiveOn = null): array
    {
        $this->ensureDefaults($supplierId);
        $params = [$supplierId];
        $where = '';
        if ($effectiveOn !== null) {
            $where = ' AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)';
            $params[] = $effectiveOn;
            $params[] = $effectiveOn;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_component_definitions
              WHERE supplier_id = ?'
            . $where
            . ' ORDER BY is_active DESC, code ASC'
        );
        $stmt->execute($params);

        return array_map(
            self::cast(...),
            PayrollTimeValue::rows(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                'payroll_component_definitions',
            ),
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $this->ensureDefaults($supplierId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? null
            : self::cast(PayrollTimeValue::row($row, 'payroll_component_definition'));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $data): array
    {
        $this->ensureDefaults($supplierId);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->prepareVersionInterval($supplierId, $data);
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_component_definitions
                    (supplier_id, code, name, component_kind, value_kind,
                     frequency_kind, tax_treatment,
                     social_participation_treatment, social_treatment,
                     health_participation_treatment, health_treatment,
                     average_earning_treatment,
                     enforcement_treatment, jmhz_treatment,
                     statistics_treatment, accounting_debit_code,
                     accounting_credit_code, annual_limit_minor, valid_from,
                     valid_to, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $data['code'],
                $data['name'],
                $data['component_kind'],
                $data['value_kind'],
                $data['frequency_kind'],
                $data['tax_treatment'],
                $data['social_participation_treatment'],
                $data['social_treatment'],
                $data['health_participation_treatment'],
                $data['health_treatment'],
                $data['average_earning_treatment'],
                $data['enforcement_treatment'],
                $data['jmhz_treatment'],
                $data['statistics_treatment'],
                $data['accounting_debit_code'],
                $data['accounting_credit_code'],
                $data['annual_limit_minor'],
                $data['valid_from'],
                $data['valid_to'],
                PayrollTimeValue::bool($data['is_active'] ?? null, 'is_active') ? 1 : 0,
            ]);
            $id = PayrollTimeValue::int($pdo->lastInsertId(), 'last_insert_id');
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (PDOException $e) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            if ((string) $e->getCode() === '23000') {
                throw new \InvalidArgumentException(
                    'Tato verze mzdové složky už existuje nebo překrývá jinou platnost.',
                    previous: $e,
                );
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Mzdovou složku se nepodařilo načíst.');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(
        int $supplierId,
        int $id,
        array $data,
        int $expectedVersion,
    ): ?array {
        $current = $this->find($supplierId, $id);
        if ($current === null) {
            return null;
        }
        if (PayrollTimeValue::string($current['code'] ?? null, 'code')
                !== PayrollTimeValue::string($data['code'] ?? null, 'code')
            || PayrollTimeValue::string(
                $current['valid_from'] ?? null,
                'valid_from',
            ) !== PayrollTimeValue::string(
                $data['valid_from'] ?? null,
                'valid_from',
            )
        ) {
            throw new \InvalidArgumentException(
                'Kód a začátek platnosti verze nelze měnit; založte novou verzi.'
            );
        }
        $this->assertNotUsedByApprovedInput($supplierId, $id);
        $currentVersion = PayrollTimeValue::int(
            $current['row_version'] ?? null,
            'row_version',
        );
        if ($currentVersion !== $expectedVersion) {
            throw new PayrollComponentConflictException($currentVersion);
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_component_definitions
                SET code = ?, name = ?, component_kind = ?, value_kind = ?,
                    frequency_kind = ?, tax_treatment = ?,
                    social_participation_treatment = ?, social_treatment = ?,
                    health_participation_treatment = ?, health_treatment = ?,
                    average_earning_treatment = ?,
                    enforcement_treatment = ?, jmhz_treatment = ?,
                    statistics_treatment = ?, accounting_debit_code = ?,
                    accounting_credit_code = ?, annual_limit_minor = ?,
                    valid_from = ?, valid_to = ?, is_active = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND row_version = ?'
        );
        $stmt->execute([
            $data['code'],
            $data['name'],
            $data['component_kind'],
            $data['value_kind'],
            $data['frequency_kind'],
            $data['tax_treatment'],
            $data['social_participation_treatment'],
            $data['social_treatment'],
            $data['health_participation_treatment'],
            $data['health_treatment'],
            $data['average_earning_treatment'],
            $data['enforcement_treatment'],
            $data['jmhz_treatment'],
            $data['statistics_treatment'],
            $data['accounting_debit_code'],
            $data['accounting_credit_code'],
            $data['annual_limit_minor'],
            $data['valid_from'],
            $data['valid_to'],
            PayrollTimeValue::bool($data['is_active'] ?? null, 'is_active') ? 1 : 0,
            $supplierId,
            $id,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            $latest = $this->find($supplierId, $id);
            throw new PayrollComponentConflictException(
                $latest === null
                    ? $expectedVersion
                    : PayrollTimeValue::int(
                        $latest['row_version'] ?? null,
                        'row_version',
                    ),
            );
        }

        return $this->find($supplierId, $id);
    }

    public function ensureDefaults(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment,
                 enforcement_treatment, jmhz_treatment, statistics_treatment,
                 valid_from)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "2026-01-01")'
        );
        foreach (self::DEFAULTS as $row) {
            $stmt->execute([
                $supplierId,
                $row[0],
                $row[1],
                $row[2],
                $row[3],
                $row[4],
                $row[5],
                $row[6],
                $row[6],
                $row[7],
                $row[7],
                $row[8],
                $row[9],
                $row[10],
                $row[11],
            ]);
        }
    }

    /** @param array<string,mixed> $data */
    private function prepareVersionInterval(int $supplierId, array $data): void
    {
        $code = PayrollTimeValue::string($data['code'] ?? null, 'code');
        $validFrom = PayrollTimeValue::string(
            $data['valid_from'] ?? null,
            'valid_from',
        );
        $requestedTo = ($data['valid_to'] ?? null) === null
            ? null
            : PayrollTimeValue::string($data['valid_to'], 'valid_to');
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, valid_from, valid_to
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ?
              ORDER BY valid_from DESC
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $code]);
        $versions = PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'component_versions',
        );
        foreach ($versions as $version) {
            $from = PayrollTimeValue::string(
                $version['valid_from'] ?? null,
                'valid_from',
            );
            $to = ($version['valid_to'] ?? null) === null
                ? null
                : PayrollTimeValue::string($version['valid_to'], 'valid_to');
            if ($from === $validFrom
                || ($from < $validFrom && ($to === null || $to >= $validFrom))
                || ($from > $validFrom
                    && ($requestedTo === null || $from <= $requestedTo))
            ) {
                if ($from < $validFrom && $to === null) {
                    $this->db->pdo()->prepare(
                        'UPDATE payroll_component_definitions
                            SET valid_to = DATE_SUB(?, INTERVAL 1 DAY),
                                row_version = row_version + 1
                          WHERE supplier_id = ? AND id = ? AND valid_to IS NULL'
                    )->execute([
                        $validFrom,
                        $supplierId,
                        PayrollTimeValue::int($version['id'] ?? null, 'id'),
                    ]);
                    continue;
                }
                throw new \InvalidArgumentException(
                    'Platnost verze mzdové složky se překrývá s existující verzí.'
                );
            }
        }
    }

    private function assertNotUsedByApprovedInput(int $supplierId, int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_inputs
              WHERE supplier_id = ? AND component_id = ?
                AND status IN ("approved", "locked")
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $id]);
        if ($stmt->fetchColumn() !== false) {
            throw new \DomainException(
                'Použitou mzdovou složku nelze měnit; založte novou účinnou verzi.'
            );
        }
    }

    private function rollbackOwned(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'annual_limit_minor', 'row_version'] as $key) {
            if (($row[$key] ?? null) !== null) {
                $row[$key] = PayrollTimeValue::int($row[$key], $key);
            }
        }
        $row['is_active'] = PayrollTimeValue::bool(
            $row['is_active'] ?? null,
            'is_active',
        );
        return $row;
    }
}
