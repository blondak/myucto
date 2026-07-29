<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Perzistence rozhodnutí o změně výše záloh §38a (tax_advance_overrides, migrace 1107).
 *
 * Override = durable zdroj pravdy pro výši/periodicitu záloh dle rozhodnutí FÚ (§174 DŘ)
 * nebo ručního nastavení účetní. Generování předpisů ({@see TaxAdvanceScheduleService})
 * ho konzultuje a od `effective_from` používá override částku MÍSTO predikce z přiznání.
 * Tím se párování #39 počítá proti reálné (rozhodnuté) výši a legitimní snížená záloha se
 * napáruje jako 'exact', kdežto doplatek/cizí platba se stejným VS dál padnou mimo pásmo.
 *
 * Tenant izolace přes supplier_id na každém dotazu. UI drží jeden override na
 * (supplier, typ, druh, rok): {@see upsert()} nejdřív smaže existující skupinu a vloží
 * jediný nový (schéma přes UNIQUE uq_tao podporuje i víc dle effective_from, kdyby bylo
 * potřeba modelovat postupné změny).
 */
final class TaxAdvanceOverrideRepository
{
    public function __construct(private readonly Connection $db) {}

    private const COLS = 'id, supplier_id, taxpayer_type, advance_kind, period_year,
                          effective_from, effective_to, amount, periodicity, note, source, created_at, updated_at';

    /**
     * Účinný override pro daný KALENDÁŘNÍ rok/druh: rozhodnutí, jehož rozsah účinnosti
     * [effective_from, effective_to] protíná rok $periodYear (nejnovější dle effective_from).
     * Vrací null, když rok žádné rozhodnutí neprotíná → generování spadne zpět na predikci.
     *
     * Rozsah OD-DO (#46): effective_to = NULL znamená otevřený konec (do nekonečna). „Protíná
     * rok" = effective_from <= 31. 12. daného roku AND (effective_to IS NULL OR >= 1. 1.).
     *
     * @return array<string,mixed>|null
     */
    public function activeForYear(int $supplierId, string $type, string $kind, int $periodYear): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . '
               FROM tax_advance_overrides
              WHERE supplier_id = ? AND taxpayer_type = ? AND advance_kind = ?
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([
            $supplierId, $type, $kind,
            sprintf('%04d-12-31', $periodYear), sprintf('%04d-01-01', $periodYear),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * VŠECHNA rozhodnutí, jejichž rozsah [effective_from, effective_to] protíná kalendářní
     * rok $periodYear (generování předpisů — jeden rok může protínat víc rozhodnutí, každé
     * pro svůj úsek). Řazeno chronologicky dle effective_from (novější úsek při překryvu
     * vyhrává až v generování). @return list<array<string,mixed>>
     */
    public function intersectingYear(int $supplierId, string $type, string $kind, int $periodYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . '
               FROM tax_advance_overrides
              WHERE supplier_id = ? AND taxpayer_type = ? AND advance_kind = ?
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from, id'
        );
        $stmt->execute([
            $supplierId, $type, $kind,
            sprintf('%04d-12-31', $periodYear), sprintf('%04d-01-01', $periodYear),
        ]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Všechna rozhodnutí supplera pro druh NAPŘÍČ ROKY (globální tabulka rozhodnutí FÚ ve FE).
     * Řazeno dle účinnosti. @return list<array<string,mixed>>
     */
    public function listAll(int $supplierId, string $type, string $kind): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . '
               FROM tax_advance_overrides
              WHERE supplier_id = ? AND taxpayer_type = ? AND advance_kind = ?
              ORDER BY effective_from, id'
        );
        $stmt->execute([$supplierId, $type, $kind]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Všechny overridy pro rok/druh (FE panel, časová osa změn). Řazeno dle účinnosti.
     *
     * @return list<array<string,mixed>>
     */
    public function listForYear(int $supplierId, string $type, string $kind, int $periodYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . '
               FROM tax_advance_overrides
              WHERE supplier_id = ? AND taxpayer_type = ? AND advance_kind = ? AND period_year = ?
              ORDER BY effective_from, id'
        );
        $stmt->execute([$supplierId, $type, $kind, $periodYear]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Nahradí override skupiny (supplier+type+kind+period_year) jediným novým záznamem.
     * Idempotentní (delete+insert v transakci). Legacy per-rok cesta (#43) — nová FE používá
     * id-based CRUD ({@see insert()}/{@see updateById()}). @return array<string,mixed>
     */
    public function upsert(
        int $supplierId,
        string $type,
        string $kind,
        int $periodYear,
        string $effectiveFrom,
        float $amount,
        string $periodicity,
        ?string $note,
        string $source,
        ?string $effectiveTo = null,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $this->deleteGroup($supplierId, $type, $kind, $periodYear);
            $id = $this->insertRow($supplierId, $type, $kind, $periodYear, $effectiveFrom, $effectiveTo, $amount, $periodicity, $note, $source);
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->find($supplierId, $id) ?? [];
    }

    /**
     * Vloží NOVÉ rozhodnutí (bez mazání skupiny — víc rozhodnutí na druh napříč roky, #46).
     * @return array<string,mixed> vložený řádek
     */
    public function insert(
        int $supplierId,
        string $type,
        string $kind,
        int $periodYear,
        string $effectiveFrom,
        ?string $effectiveTo,
        float $amount,
        string $periodicity,
        ?string $note,
        string $source,
    ): array {
        $id = $this->insertRow($supplierId, $type, $kind, $periodYear, $effectiveFrom, $effectiveTo, $amount, $periodicity, $note, $source);
        return $this->find($supplierId, $id) ?? [];
    }

    private function insertRow(
        int $supplierId,
        string $type,
        string $kind,
        int $periodYear,
        string $effectiveFrom,
        ?string $effectiveTo,
        float $amount,
        string $periodicity,
        ?string $note,
        string $source,
    ): int {
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO tax_advance_overrides
                 (supplier_id, taxpayer_type, advance_kind, period_year, effective_from, effective_to, amount, periodicity, note, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $supplierId, $type, $kind, $periodYear, $effectiveFrom,
            $effectiveTo !== null && $effectiveTo !== '' ? $effectiveTo : null,
            round($amount, 2), $periodicity,
            $note !== null && $note !== '' ? $note : null,
            $source,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Upraví existující rozhodnutí (scoping na supplera). @return array<string,mixed>|null
     * upravený řádek nebo null, když id supplerovi nepatří.
     */
    public function updateById(
        int $supplierId,
        int $id,
        int $periodYear,
        string $effectiveFrom,
        ?string $effectiveTo,
        float $amount,
        string $periodicity,
        ?string $note,
        string $source,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tax_advance_overrides
                SET period_year = ?, effective_from = ?, effective_to = ?, amount = ?,
                    periodicity = ?, note = ?, source = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $periodYear, $effectiveFrom,
            $effectiveTo !== null && $effectiveTo !== '' ? $effectiveTo : null,
            round($amount, 2), $periodicity,
            $note !== null && $note !== '' ? $note : null,
            $source, $id, $supplierId,
        ]);
        return $this->find($supplierId, $id);
    }

    /** Smaže jedno rozhodnutí (scoping na supplera). @return bool true = smazáno */
    public function deleteById(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM tax_advance_overrides WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Smaže override skupiny. @return int počet smazaných */
    public function deleteGroup(int $supplierId, string $type, string $kind, int $periodYear): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM tax_advance_overrides
              WHERE supplier_id = ? AND taxpayer_type = ? AND advance_kind = ? AND period_year = ?'
        );
        $stmt->execute([$supplierId, $type, $kind, $periodYear]);
        return $stmt->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM tax_advance_overrides WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function cast(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'supplier_id' => (int) $r['supplier_id'],
            'taxpayer_type' => (string) $r['taxpayer_type'],
            'advance_kind' => (string) $r['advance_kind'],
            'period_year' => (int) $r['period_year'],
            'effective_from' => (string) $r['effective_from'],
            'effective_to' => $r['effective_to'] === null ? null : (string) $r['effective_to'],
            'amount' => round((float) $r['amount'], 2),
            'periodicity' => (string) $r['periodicity'],
            'note' => $r['note'] === null ? null : (string) $r['note'],
            'source' => (string) $r['source'],
            'created_at' => $r['created_at'] ?? null,
            'updated_at' => $r['updated_at'] ?? null,
        ];
    }
}
