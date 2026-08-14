<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_currencies — číselník prodejních měn e-shopu (migrace 1371).
 *
 * Per tenant (supplier_id); UNIQUE (supplier_id, code). Kód je ISO 4217, shodný
 * s `stock_item_prices.currency_code` a sourozenci.
 *
 * Vědomě nemá nic společného s `currencies` (měnové účty dodavatele): prodejní
 * měna je prezentace ceny, ne místo, kam přistanou peníze. Zboží se dá nacenit
 * v GBP i bez britského účtu.
 */
final class StockCurrencyRepository
{
    private const COLUMNS =
        'id, supplier_id, code, name, symbol, display_order, is_default, archived,
         created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_currencies WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_currencies WHERE supplier_id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, strtoupper($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM stock_currencies WHERE supplier_id = ?';
        if ($activeOnly) {
            $sql .= ' AND archived = 0';
        }
        $sql .= ' ORDER BY display_order ASC, code ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Kódy, které smí zápisová cesta přijmout. Archivované jsou uvnitř schválně —
     * archivace měnu jen stáhne z nabídky pro nové řádky, existující cena musí jít
     * dál uložit (formulář posílá celou sadu zpátky).
     *
     * @return list<string>
     */
    public function codes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code FROM stock_currencies WHERE supplier_id = ? ORDER BY display_order, code'
        );
        $stmt->execute([$supplierId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @param array{code:string, name:string, symbol?:?string, display_order?:int, is_default?:bool, archived?:bool} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_currencies
                (supplier_id, code, name, symbol, display_order, is_default, archived)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            strtoupper((string) $data['code']),
            (string) $data['name'],
            self::symbol($data['symbol'] ?? null),
            (int) ($data['display_order'] ?? 0),
            (int) ($data['is_default'] ?? false),
            (int) ($data['archived'] ?? false),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{code:string, name:string, symbol?:?string, display_order?:int, is_default?:bool, archived?:bool} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_currencies SET
                code = ?, name = ?, symbol = ?, display_order = ?, is_default = ?, archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            strtoupper((string) $data['code']),
            (string) $data['name'],
            self::symbol($data['symbol'] ?? null),
            (int) ($data['display_order'] ?? 0),
            (int) ($data['is_default'] ?? false),
            (int) ($data['archived'] ?? false),
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Výchozí měna je nejvýš jedna — ostatní se shodí. */
    public function clearDefaultExcept(int $supplierId, int $id): void
    {
        $this->db->pdo()
            ->prepare('UPDATE stock_currencies SET is_default = 0 WHERE supplier_id = ? AND id <> ?')
            ->execute([$supplierId, $id]);
    }

    /**
     * Existuje v měně aspoň jedna cena, akční cena nebo poplatek? Blokuje hard delete —
     * smazáním by se z nabídky ztratila měna, ve které se reálně prodává.
     */
    public function isReferenced(int $supplierId, string $code): bool
    {
        $code = strtoupper($code);
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM stock_item_prices WHERE supplier_id = ? AND currency_code = ?
                UNION ALL
                SELECT 1 FROM stock_item_promo_prices WHERE supplier_id = ? AND currency_code = ?
                UNION ALL
                SELECT 1 FROM stock_item_fees WHERE supplier_id = ? AND currency_code = ?
             )'
        );
        $stmt->execute([$supplierId, $code, $supplierId, $code, $supplierId, $code]);
        return (bool) $stmt->fetchColumn();
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_currencies WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private static function symbol(mixed $value): ?string
    {
        $s = is_string($value) ? trim($value) : '';
        return $s === '' ? null : mb_substr($s, 0, 8);
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['display_order'] = (int) $r['display_order'];
        $r['is_default'] = (bool) $r['is_default'];
        $r['archived'] = (bool) $r['archived'];
        return $r;
    }
}
