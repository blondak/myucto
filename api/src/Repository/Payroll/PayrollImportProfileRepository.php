<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Uložené profily mapování sloupců importu (pravidla podle hlaviček
 * a mzdové složky, které pravidla potřebují).
 *
 * @phpstan-type ImportProfile array{
 *   id:int,name:string,rules:list<array<string,mixed>>,components:list<array<string,mixed>>,
 *   is_sample:bool,updated_at:string
 * }
 */
final class PayrollImportProfileRepository
{
    private const COLUMNS = 'id, name, rules_json, components_json, is_sample, updated_at';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<ImportProfile> */
    public function list(int $supplierId, string $sourceSystem): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_import_profiles
              WHERE supplier_id = ? AND source_system = ?
              ORDER BY is_sample DESC, name, id'
        );
        $stmt->execute([$supplierId, $sourceSystem]);

        return array_map(
            $this->profile(...),
            PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'payroll_import_profiles'),
        );
    }

    /** @return ImportProfile|null */
    public function find(int $supplierId, string $sourceSystem, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_import_profiles
              WHERE supplier_id = ? AND source_system = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $sourceSystem, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->profile(PayrollTimeValue::row($row, 'payroll_import_profile'));
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @param list<array<string,mixed>> $components
     * @return ImportProfile|null null = profil neexistuje
     */
    public function save(
        int $supplierId,
        string $sourceSystem,
        ?int $id,
        string $name,
        array $rules,
        ?int $userId,
        array $components = [],
        bool $isSample = false,
    ): ?array {
        $rulesJson = json_encode($rules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $componentsJson = json_encode($components, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            if ($id === null) {
                $this->db->pdo()->prepare(
                    'INSERT INTO payroll_import_profiles
                        (supplier_id, source_system, name, rules_json, components_json, is_sample, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$supplierId, $sourceSystem, $name, $rulesJson, $componentsJson, $isSample ? 1 : 0, $userId]);
                $id = (int) $this->db->pdo()->lastInsertId();
            } else {
                if ($this->find($supplierId, $sourceSystem, $id) === null) {
                    return null;
                }
                $this->db->pdo()->prepare(
                    'UPDATE payroll_import_profiles
                        SET name = ?, rules_json = ?, components_json = ?, updated_by = ?,
                            row_version = row_version + 1
                      WHERE supplier_id = ? AND source_system = ? AND id = ?'
                )->execute([$name, $rulesJson, $componentsJson, $userId, $supplierId, $sourceSystem, $id]);
            }
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 || ($e->errorInfo[1] ?? null) === '1062') {
                throw new \InvalidArgumentException(
                    "Profil s názvem „{$name}“ už existuje. Zvolte jiný název nebo upravte existující profil.",
                );
            }
            throw $e;
        }

        return $this->find($supplierId, $sourceSystem, $id);
    }

    public function delete(int $supplierId, string $sourceSystem, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM payroll_import_profiles
              WHERE supplier_id = ? AND source_system = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $sourceSystem, $id]);

        return $stmt->rowCount() === 1;
    }

    public function supplierExists(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Ukázkový profil se firmě založí jednou; značka na firmě zajistí, že se
     * smazaný vzor znovu nevrátí. Uvnitř cizí transakce se nic nezakládá —
     * zámek řádku firmy by se tam držel až do jejího konce.
     *
     * @param list<array<string,mixed>> $rules
     * @param list<array<string,mixed>> $components
     * @return bool true, pokud vzor právě založil
     */
    public function seedSampleOnce(
        int $supplierId,
        string $sourceSystem,
        string $name,
        array $rules,
        array $components,
    ): bool {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return false;
        }
        $check = $pdo->prepare('SELECT payroll_attendance_sample_seeded_at FROM supplier WHERE id = ?');
        $check->execute([$supplierId]);
        $seeded = $check->fetchColumn();
        if ($seeded === false || $seeded !== null) {
            return false;
        }
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT payroll_attendance_sample_seeded_at FROM supplier WHERE id = ? FOR UPDATE');
            $lock->execute([$supplierId]);
            $current = $lock->fetchColumn();
            if ($current === false || $current !== null) {
                $pdo->commit();

                return false;
            }
            $exists = $pdo->prepare(
                'SELECT 1 FROM payroll_import_profiles WHERE supplier_id = ? AND source_system = ? AND name = ?'
            );
            $exists->execute([$supplierId, $sourceSystem, $name]);
            $created = $exists->fetchColumn() === false;
            if ($created) {
                $this->save($supplierId, $sourceSystem, null, $name, $rules, null, $components, true);
            }
            $pdo->prepare('UPDATE supplier SET payroll_attendance_sample_seeded_at = NOW() WHERE id = ?')
                ->execute([$supplierId]);
            $pdo->commit();

            return $created;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return ImportProfile
     */
    private function profile(array $row): array
    {
        $rules = json_decode((string) ($row['rules_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
        $components = json_decode((string) ($row['components_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);

        return [
            'id' => PayrollTimeValue::int($row['id'] ?? null, 'id'),
            'name' => (string) $row['name'],
            'rules' => is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [],
            'components' => is_array($components) ? array_values(array_filter($components, 'is_array')) : [],
            'is_sample' => (int) ($row['is_sample'] ?? 0) === 1,
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
