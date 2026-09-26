<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Potvrzení odloženého příjmu (JMHZ scénář 8, typ 10548) za skončený pracovní
 * vztah a měsíc, do kterého se příjem zúčtoval.
 *
 * Mzdový běh si potvrzení zmrazí do vstupu. Bez něj příjem po skončení vztahu
 * nemá doložené, ke kterému měsíci patří, a výpočet pojistného ho odmítne
 * (`post_termination_income_attribution_unverified`).
 */
final class PayrollDeferredIncomeRepository
{
    /**
     * Typy číselníku „Typ odloženého příjmu", které aplikace zpracuje sama.
     * Ostatní (náhrada mzdy při neplatném skončení, roční zúčtování po
     * skončení) mají vlastní pravidla pro ELDP a pojistné a potvrdit je tu
     * nejde.
     */
    public const SUPPORTED_TYPES = ['1'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{id:int,employment_id:int,period_start:string,deferred_type:string,note:?string,row_version:int,updated_at:string}>
     */
    public function listForEmployment(int $supplierId, int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employment_id, period_start, deferred_type, note, row_version, updated_at
               FROM payroll_employment_deferred_incomes
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY period_start DESC, id DESC',
        );
        $statement->execute([$supplierId, $employmentId]);

        return array_map(
            static fn (array $row): array => self::cast($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Potvrzení za měsíc po vztazích, zdroj pro zmrazený vstup mzdového běhu.
     *
     * @return array<int,array{deferred_type:string,note:?string,row_version:int}>
     */
    public function forPeriod(int $supplierId, string $periodStart): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment_id, deferred_type, note, row_version
               FROM payroll_employment_deferred_incomes
              WHERE supplier_id = ? AND period_start = ?',
        );
        $statement->execute([$supplierId, $periodStart]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']] = [
                'deferred_type' => (string) $row['deferred_type'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
                'row_version' => (int) $row['row_version'],
            ];
        }

        return $result;
    }

    /**
     * Uloží potvrzení za měsíc. Odložený příjem se váže na SKONČENÝ vztah
     * (kontrola 336 ČSSZ): měsíc zúčtování musí ležet až za dnem skončení.
     *
     * @return array{id:int,employment_id:int,period_start:string,deferred_type:string,note:?string,row_version:int,updated_at:string}
     */
    public function save(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $deferredType,
        ?string $note,
        ?int $userId,
    ): array {
        if (!in_array($deferredType, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Aplikace zpracuje sama jen odložený příjem typu 1 (příjem po skončení'
                . ' zaměstnaneckého poměru). Náhradu mzdy při neplatném skončení a roční'
                . ' zúčtování po skončení podejte přes ePortál ČSSZ.',
            );
        }
        $employment = $this->db->pdo()->prepare(
            'SELECT end_date FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $employment->execute([$supplierId, $employmentId]);
        $endDate = $employment->fetchColumn();
        if ($endDate === false) {
            throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        }
        if (!is_string($endDate) || $endDate === '' || substr($endDate, 0, 7) >= substr($periodStart, 0, 7)) {
            throw new \InvalidArgumentException(
                'Odložený příjem se potvrzuje za měsíc po skončení pracovního vztahu.'
                . ' Vztah musí mít vyplněný den skončení dřív, než začíná měsíc zúčtování.',
            );
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_deferred_incomes
                (supplier_id, employment_id, period_start, deferred_type, note,
                 created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                deferred_type = VALUES(deferred_type),
                note = VALUES(note),
                updated_by = VALUES(updated_by),
                row_version = row_version + 1',
        )->execute([
            $supplierId,
            $employmentId,
            $periodStart,
            $deferredType,
            $note,
            $userId,
            $userId,
        ]);

        foreach ($this->listForEmployment($supplierId, $employmentId) as $row) {
            if ($row['period_start'] === $periodStart) {
                return $row;
            }
        }
        throw new \LogicException('Uložené potvrzení odloženého příjmu se nepodařilo načíst.');
    }

    public function delete(int $supplierId, int $employmentId, string $periodStart): bool
    {
        $statement = $this->db->pdo()->prepare(
            'DELETE FROM payroll_employment_deferred_incomes
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?',
        );
        $statement->execute([$supplierId, $employmentId, $periodStart]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,employment_id:int,period_start:string,deferred_type:string,note:?string,row_version:int,updated_at:string}
     */
    private static function cast(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'employment_id' => (int) $row['employment_id'],
            'period_start' => (string) $row['period_start'],
            'deferred_type' => (string) $row['deferred_type'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'row_version' => (int) $row['row_version'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
