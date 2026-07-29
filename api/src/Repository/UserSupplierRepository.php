<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Membership uživatel ↔ supplier (tabulka user_suppliers, migrace 1000, Epic F0).
 *
 * Prázdné přiřazení znamená nulový přístup pro každého kromě superadmina.
 * `role_id` je volitelný per-supplier override; NULL dědí users.role_id.
 */
final class UserSupplierRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Mapa supplier_id => role_id override (NULL = zdědit výchozí roli).
     * Jediný indexovaný dotaz — pokrývá membership check i role override.
     *
     * @return array<int, ?int>
     */
    public function assignmentsForUser(int $userId): array
    {
        if ($userId <= 0) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, role_id FROM user_suppliers WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['supplier_id']] = $r['role_id'] !== null ? (int) $r['role_id'] : null;
        }
        return $out;
    }

    /**
     * Seznam povolených supplier id. Prázdné pole = nulový přístup.
     *
     * @return list<int>
     */
    public function allowedSupplierIds(int $userId): array
    {
        $ids = array_keys($this->assignmentsForUser($userId));
        sort($ids);
        return $ids;
    }

    /** Per-supplier role override; NULL = žádný řádek nebo zdědit globální roli. */
    public function roleForSupplier(int $userId, int $supplierId): ?int
    {
        if ($userId <= 0 || $supplierId <= 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT role_id FROM user_suppliers WHERE user_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$userId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return $row['role_id'] !== null ? (int) $row['role_id'] : null;
    }

    /**
     * Přiřazení uživatele vč. jména firmy (pro admin UI).
     *
     * @return list<array{supplier_id:int, name:string, role_id:?int}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT us.supplier_id,
                    COALESCE(NULLIF(s.display_name, \'\'), s.company_name) AS name,
                    us.role_id
               FROM user_suppliers us
               JOIN supplier s ON s.id = us.supplier_id
              WHERE us.user_id = ?
           ORDER BY us.supplier_id'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'supplier_id' => (int) $r['supplier_id'],
                'name'        => (string) $r['name'],
                'role_id'     => $r['role_id'] !== null ? (int) $r['role_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * Nahradí kompletní sadu přiřazení uživatele (delete + insert v transakci).
     * Prázdné pole = odebrat veškerý přístup k firmám.
     *
     * @param list<array{supplier_id:int, role_id:?int}> $assignments
     */
    public function replaceForUser(int $userId, array $assignments): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_suppliers WHERE user_id = ?')->execute([$userId]);
            if ($assignments !== []) {
                $ins = $pdo->prepare(
                    'INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)'
                );
                foreach ($assignments as $a) {
                    $ins->execute([$userId, (int) $a['supplier_id'], $a['role_id'] ?? null]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
