<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use PDO;

/**
 * Okruhy párování řádků deníku (migrace 1895) a výpočet otevřených položek účtu.
 *
 * Položka okruhu drží řádek přes (entry_id, line_no) a účet v okamžiku spárování.
 * Řádek se k ní připojuje vždy přes všechny tři hodnoty, takže položka, jejíž
 * řádek po přeúčtování sedí na jiném účtu, se nikde nezapočte; úklid takových
 * položek obstarává {@see releaseStale()}.
 *
 * Okno otevřených položek je shodné s počátečním stavem opisu účtu
 * ({@see LedgerReportRepository::accountOpening()}): rozvahové účty od kotvy
 * posledního otevření knih, výsledkové od začátku období, bez uzávěrkových
 * zápisů. Otevírací zápis tak v okně leží jako běžný řádek a dá se párovat.
 */
final class JournalLinePairingRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Řádky deníku daného tenanta podle id, i s tím, co potřebuje validace okruhu.
     *
     * @param list<int> $lineIds
     * @return array<int,array{line_id:int, entry_id:int, line_no:int, account_id:int, parent_id:?int, side:string, amount:float, posted:bool, pairing_id:?int}>
     */
    public function lineRefs(int $supplierId, array $lineIds): array
    {
        $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $id): bool => $id > 0)));
        if ($lineIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($lineIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.id, l.entry_id, l.line_no, l.account_id, ca.parent_id, l.side, l.amount,
                    e.posted_at, p.pairing_id
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
          LEFT JOIN journal_line_pairing_items p
                 ON p.supplier_id = l.supplier_id AND p.entry_id = l.entry_id
                AND p.line_no = l.line_no AND p.account_id = l.account_id
                AND " . self::liveItem('p') . "
              WHERE l.supplier_id = ? AND l.id IN ({$in})"
        );
        $stmt->execute([$supplierId, ...$lineIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'line_id'    => (int) $r['id'],
                'entry_id'   => (int) $r['entry_id'],
                'line_no'    => (int) $r['line_no'],
                'account_id' => (int) $r['account_id'],
                'parent_id'  => $r['parent_id'] === null ? null : (int) $r['parent_id'],
                'side'       => (string) $r['side'],
                'amount'     => round((float) $r['amount'], 2),
                'posted'     => $r['posted_at'] !== null,
                'pairing_id' => $r['pairing_id'] === null ? null : (int) $r['pairing_id'],
            ];
        }
        return $out;
    }

    /**
     * Okruhy řádků na stránce — klíč "entry_id:line_no:account_id".
     *
     * @param list<array{entry_id:int, line_no:int, account_id:int}> $lines
     * @return array<string,int>
     */
    public function pairingsForLines(int $supplierId, array $lines): array
    {
        $entryIds = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['entry_id'], $lines)));
        if ($entryIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.entry_id, i.line_no, i.account_id, i.pairing_id
               FROM journal_line_pairing_items i
              WHERE i.supplier_id = ? AND i.entry_id IN ({$in})
                AND " . self::liveItem('i')
        );
        $stmt->execute([$supplierId, ...$entryIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['entry_id'] . ':' . $r['line_no'] . ':' . $r['account_id']] = (int) $r['pairing_id'];
        }
        return $out;
    }

    /**
     * @param list<array{entry_id:int, line_no:int, account_id:int}> $lines
     */
    public function create(int $supplierId, int $accountId, array $lines, ?string $note, string $origin, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO journal_line_pairings (supplier_id, account_id, note, origin, created_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$supplierId, $accountId, $note, $origin, $userId]);
        $id = (int) $pdo->lastInsertId();
        $this->addItems($supplierId, $id, $lines);
        return $id;
    }

    /**
     * @param list<array{entry_id:int, line_no:int, account_id:int}> $lines
     */
    public function addItems(int $supplierId, int $pairingId, array $lines): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO journal_line_pairing_items (supplier_id, entry_id, line_no, pairing_id, account_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($lines as $l) {
            $stmt->execute([$supplierId, $l['entry_id'], $l['line_no'], $pairingId, $l['account_id']]);
        }
    }

    public function removeItem(int $supplierId, int $pairingId, int $entryId, int $lineNo): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM journal_line_pairing_items
              WHERE supplier_id = ? AND pairing_id = ? AND entry_id = ? AND line_no = ?'
        );
        $stmt->execute([$supplierId, $pairingId, $entryId, $lineNo]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $supplierId, int $pairingId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM journal_line_pairings WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $pairingId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{id:int, account_id:int, account_code:string, account_name:string, note:?string, origin:string, created_by:?int, created_at:string}|null
     */
    public function find(int $supplierId, int $pairingId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.account_id, ca.account_code, ca.name AS account_name, p.note, p.origin,
                    p.created_by, p.created_at
               FROM journal_line_pairings p
               JOIN chart_of_accounts ca ON ca.id = p.account_id AND ca.supplier_id = p.supplier_id
              WHERE p.supplier_id = ? AND p.id = ?'
        );
        $stmt->execute([$supplierId, $pairingId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return [
            'id'           => (int) $r['id'],
            'account_id'   => (int) $r['account_id'],
            'account_code' => (string) $r['account_code'],
            'account_name' => (string) $r['account_name'],
            'note'         => $r['note'] === null ? null : (string) $r['note'],
            'origin'       => (string) $r['origin'],
            'created_by'   => $r['created_by'] === null ? null : (int) $r['created_by'],
            'created_at'   => (string) $r['created_at'],
        ];
    }

    /**
     * Položky okruhu i s řádkem deníku. Položka, jejíž řádek už v deníku není
     * (nebo sedí na jiném účtu), se vrátí s `line_id` NULL, ať ji uživatel vidí.
     *
     * @return list<array<string,mixed>>
     */
    public function items(int $supplierId, int $pairingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.entry_id, i.line_no, i.account_id, l.id AS line_id, l.side, l.amount, l.is_red_storno,
                    e.entry_date, e.document_no, e.description, e.source_type, e.source_id, e.posted_at
               FROM journal_line_pairing_items i
               JOIN journal_entries e ON e.id = i.entry_id AND e.supplier_id = i.supplier_id
          LEFT JOIN journal_entry_lines l
                 ON l.supplier_id = i.supplier_id AND l.entry_id = i.entry_id
                AND l.line_no = i.line_no AND l.account_id = i.account_id
              WHERE i.supplier_id = ? AND i.pairing_id = ?
              ORDER BY e.entry_date, i.entry_id, i.line_no'
        );
        $stmt->execute([$supplierId, $pairingId]);
        return array_map(static fn (array $r): array => [
            'entry_id'    => (int) $r['entry_id'],
            'line_no'     => (int) $r['line_no'],
            'account_id'  => (int) $r['account_id'],
            'line_id'     => $r['line_id'] === null ? null : (int) $r['line_id'],
            'side'        => $r['side'] === null ? null : (string) $r['side'],
            'amount'      => $r['amount'] === null ? null : round((float) $r['amount'], 2),
            'is_red_storno' => (bool) ($r['is_red_storno'] ?? false),
            'entry_date'  => (string) $r['entry_date'],
            'document_no' => $r['document_no'],
            'description' => $r['description'],
            'source_type' => (string) $r['source_type'],
            'source_id'   => $r['source_id'] === null ? null : (int) $r['source_id'],
            'posted'      => $r['posted_at'] !== null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Zruší okruhy, ve kterých zůstal nejvýš jeden řádek. Okruh o jednom řádku nic
     * nepáruje, jen by řádek blokoval pro návrhy i pro nový okruh.
     *
     * @param list<int>|null $pairingIds NULL = všechny okruhy firmy (úklid po kaskádě smazaného zápisu)
     * @return list<array{pairing_id:int, entry_id:int, line_no:int}> položky, které tím z okruhu odešly
     */
    public function dissolveDegenerate(int $supplierId, ?array $pairingIds = null, ?int $keep = null): array
    {
        $pdo = $this->db->pdo();
        $params = [$supplierId];
        $filter = '';
        if ($pairingIds !== null) {
            $pairingIds = array_values(array_unique(array_map('intval', $pairingIds)));
            if ($pairingIds === []) {
                return [];
            }
            $filter .= ' AND p.id IN (' . implode(',', array_fill(0, count($pairingIds), '?')) . ')';
            $params = [...$params, ...$pairingIds];
        }
        if ($keep !== null) {
            $filter .= ' AND p.id <> ?';
            $params[] = $keep;
        }
        $stmt = $pdo->prepare(
            "SELECT p.id
               FROM journal_line_pairings p
              WHERE p.supplier_id = ?{$filter}
                AND (SELECT COUNT(*) FROM journal_line_pairing_items i
                      WHERE i.supplier_id = p.supplier_id AND i.pairing_id = p.id) < 2"
        );
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $left = $pdo->prepare(
            "SELECT pairing_id, entry_id, line_no FROM journal_line_pairing_items
              WHERE supplier_id = ? AND pairing_id IN ({$in})"
        );
        $left->execute([$supplierId, ...$ids]);
        $released = array_map(static fn (array $r): array => [
            'pairing_id' => (int) $r['pairing_id'],
            'entry_id'   => (int) $r['entry_id'],
            'line_no'    => (int) $r['line_no'],
        ], $left->fetchAll(PDO::FETCH_ASSOC));
        $pdo->prepare("DELETE FROM journal_line_pairings WHERE supplier_id = ? AND id IN ({$in})")
            ->execute([$supplierId, ...$ids]);
        return $released;
    }

    /**
     * Podmínka „položka `$alias` leží v okruhu, kde je ještě jiný řádek". Okruh
     * o jednom řádku (zbytek po smazání zápisu kaskádou) se tak čte jako nespárovaný,
     * dokud ho zápisová akce neuklidí přes {@see dissolveDegenerate()}.
     */
    private static function liveItem(string $alias): string
    {
        return "EXISTS (SELECT 1 FROM journal_line_pairing_items o
                         WHERE o.supplier_id = {$alias}.supplier_id AND o.pairing_id = {$alias}.pairing_id
                           AND (o.entry_id <> {$alias}.entry_id OR o.line_no <> {$alias}.line_no))";
    }

    /**
     * Uvolní z okruhů všechny řádky zápisu (storno: originál s protizápisem se
     * vyruší sám, jeho dřívější spárování s úhradou by jinak zůstalo viset).
     *
     * @return list<array{pairing_id:int, entry_id:int, line_no:int}> uvolněné položky
     */
    public function releaseEntry(int $supplierId, int $entryId): array
    {
        return $this->release($supplierId, $entryId, false);
    }

    /**
     * Uvolní položky zápisu, jejichž řádek po přeúčtování na stejném pořadí
     * neexistuje nebo sedí na jiném účtu.
     *
     * @return list<array{pairing_id:int, entry_id:int, line_no:int}> uvolněné položky
     */
    public function releaseStale(int $supplierId, int $entryId): array
    {
        return $this->release($supplierId, $entryId, true);
    }

    /** @return list<array{pairing_id:int, entry_id:int, line_no:int}> */
    private function release(int $supplierId, int $entryId, bool $onlyStale): array
    {
        $pdo = $this->db->pdo();
        $staleSql = $onlyStale
            ? ' AND NOT EXISTS (SELECT 1 FROM journal_entry_lines l
                                 WHERE l.supplier_id = i.supplier_id AND l.entry_id = i.entry_id
                                   AND l.line_no = i.line_no AND l.account_id = i.account_id)'
            : '';
        $stmt = $pdo->prepare(
            "SELECT i.pairing_id, i.entry_id, i.line_no
               FROM journal_line_pairing_items i
              WHERE i.supplier_id = ? AND i.entry_id = ?{$staleSql}"
        );
        $stmt->execute([$supplierId, $entryId]);
        $released = array_map(static fn (array $r): array => [
            'pairing_id' => (int) $r['pairing_id'],
            'entry_id'   => (int) $r['entry_id'],
            'line_no'    => (int) $r['line_no'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($released === []) {
            return [];
        }
        $delete = $pdo->prepare(
            'DELETE FROM journal_line_pairing_items WHERE supplier_id = ? AND entry_id = ? AND line_no = ?'
        );
        foreach ($released as $r) {
            $delete->execute([$supplierId, $r['entry_id'], $r['line_no']]);
        }
        return [...$released, ...$this->dissolveDegenerate($supplierId, array_column($released, 'pairing_id'))];
    }

    /**
     * Řádky účtu (vč. analytik pod syntetikou) k datu s otevřenou částkou.
     *
     * Otevřená částka řádku: nespárovaný řádek je otevřený celý. V okruhu se
     * rozdíl (Σ MD − Σ D členů k datu) nechá otevřený na převažující straně a
     * vyrovnání se na ní rozpouští od nejstaršího řádku, takže otevřený zůstane
     * nejnovější pohyb. Σ otevřených částek je tak vždy zůstatek účtu k datu.
     *
     * @return list<array<string,mixed>>
     */
    public function openItemLines(int $supplierId, int $accountId, string $asOf, string $periodStart, ?string $anchor, bool $onlyOpen, int $limit, int $offset): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        [$sql, $params] = $this->openItemsCte($supplierId, $accountId, $asOf, $periodStart, $anchor);
        // Drill-down na prvotní doklad: stejná sada sloupců jako opis účtu
        // (LedgerReportRepository::accountLines), dotažená jen pro řádky stránky.
        $stmt = $this->db->pdo()->prepare(
            $sql . " SELECT pg.*,
                            bt.statement_id AS source_statement_id,
                            cd.doc_number AS source_doc_number,
                            cd.register_id AS source_register_id,
                            ast.id AS source_asset_id,
                            stl.doc_type AS source_settlement_doc_type,
                            stl.doc_id AS source_settlement_doc_id
                       FROM (SELECT * FROM opened
                              WHERE (? = 0 OR open_amount <> 0)
                              ORDER BY entry_date, entry_id, line_no
                              LIMIT {$limit} OFFSET {$offset}) pg
                  LEFT JOIN bank_transactions bt ON pg.source_type = 'bank' AND bt.id = pg.source_id
                  LEFT JOIN cash_documents cd ON pg.source_type = 'cash' AND cd.id = pg.source_id AND cd.supplier_id = ?
                  LEFT JOIN invoice_settlements stl ON pg.source_type = 'settlement' AND stl.id = pg.source_id AND stl.supplier_id = ?
                  LEFT JOIN depreciation_entries dep ON pg.source_type = 'depreciation' AND dep.id = pg.source_id AND dep.supplier_id = ?
                  LEFT JOIN assets ast ON ast.supplier_id = ?
                         AND ast.id = CASE
                             WHEN pg.source_type IN ('asset', 'asset_disposal') THEN pg.source_id
                             WHEN pg.source_type = 'depreciation' THEN dep.asset_id
                             ELSE NULL
                         END
                   ORDER BY pg.entry_date, pg.entry_id, pg.line_no"
        );
        $stmt->execute([...$params, $onlyOpen ? 1 : 0, $supplierId, $supplierId, $supplierId, $supplierId]);
        return array_map(static function (array $r): array {
            foreach (['line_id', 'entry_id', 'line_no', 'account_id'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            foreach (['source_statement_id', 'source_register_id', 'source_asset_id', 'source_settlement_doc_id'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
            $r['pairing_id'] = $r['pairing_id'] === null ? null : (int) $r['pairing_id'];
            $r['reversed_by'] = $r['reversed_by'] === null ? null : (int) $r['reversed_by'];
            foreach (['amount', 'open_amount', 'running_balance', 'running_open'] as $k) {
                $r[$k] = round((float) $r[$k], 2);
            }
            $r['is_red_storno'] = (bool) $r['is_red_storno'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{total:int, open_count:int, balance:float, open_total:float, open_md:float, open_d:float}
     */
    public function openItemTotals(int $supplierId, int $accountId, string $asOf, string $periodStart, ?string $anchor): array
    {
        [$sql, $params] = $this->openItemsCte($supplierId, $accountId, $asOf, $periodStart, $anchor);
        $stmt = $this->db->pdo()->prepare(
            $sql . " SELECT COUNT(*) AS total,
                            COALESCE(SUM(open_amount <> 0), 0) AS open_count,
                            COALESCE(SUM(signed), 0) AS balance,
                            COALESCE(SUM(open_signed), 0) AS open_total,
                            COALESCE(SUM(CASE WHEN side = 'debit' THEN IF(is_red_storno = 1, -open_amount, open_amount) ELSE 0 END), 0) AS open_md,
                            COALESCE(SUM(CASE WHEN side = 'credit' THEN IF(is_red_storno = 1, -open_amount, open_amount) ELSE 0 END), 0) AS open_d
                       FROM opened"
        );
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total'      => (int) ($r['total'] ?? 0),
            'open_count' => (int) ($r['open_count'] ?? 0),
            'balance'    => round((float) ($r['balance'] ?? 0), 2),
            'open_total' => round((float) ($r['open_total'] ?? 0), 2),
            'open_md'    => round((float) ($r['open_md'] ?? 0), 2),
            'open_d'     => round((float) ($r['open_d'] ?? 0), 2),
        ];
    }

    /**
     * Nespárované řádky účtu k datu — podklad pro návrhy párování.
     *
     * @return list<array<string,mixed>>
     */
    public function unpairedLines(int $supplierId, int $accountId, string $asOf, string $periodStart, ?string $anchor): array
    {
        [$sql, $params] = $this->openItemsCte($supplierId, $accountId, $asOf, $periodStart, $anchor);
        $stmt = $this->db->pdo()->prepare(
            $sql . ' SELECT line_id, entry_id, line_no, account_id, side, effective_side, amount, entry_date,
                            document_no, description, reversed_by
                       FROM opened
                      WHERE pairing_id IS NULL
                      ORDER BY entry_date, entry_id, line_no'
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'line_id'     => (int) $r['line_id'],
            'entry_id'    => (int) $r['entry_id'],
            'line_no'     => (int) $r['line_no'],
            'account_id'  => (int) $r['account_id'],
            'side'        => (string) $r['side'],
            'effective_side' => (string) $r['effective_side'],
            'amount'      => round((float) $r['amount'], 2),
            'entry_date'  => (string) $r['entry_date'],
            'document_no' => $r['document_no'],
            'description' => $r['description'],
            'reversed_by' => $r['reversed_by'] === null ? null : (int) $r['reversed_by'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Společné CTE otevřených položek; končí CTE `opened`.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function openItemsCte(int $supplierId, int $accountId, string $asOf, string $periodStart, ?string $anchor): array
    {
        $sql = "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . ",
            base AS (
                SELECT l.id AS line_id, l.entry_id, l.line_no, l.account_id, l.side, l.amount, l.is_red_storno,
                       IF(l.is_red_storno = 1, IF(l.side = 'debit', 'credit', 'debit'), l.side) AS effective_side,
                       l.currency_code, l.amount_foreign,
                       CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END AS signed,
                       e.entry_date, e.document_no, e.description, e.source_type, e.source_id,
                       e.reversed_by, p.pairing_id
                  FROM journal_entry_lines l
                  JOIN journal_entries e ON e.id = l.entry_id
                  " . JournalTaxOrigin::join() . "
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
             LEFT JOIN journal_line_pairing_items p
                    ON p.supplier_id = l.supplier_id AND p.entry_id = l.entry_id
                   AND p.line_no = l.line_no AND p.account_id = l.account_id
                   AND " . self::liveItem('p') . "
                 WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                   AND (l.account_id = ? OR ca.parent_id = ?)
                   AND e.entry_date <= ?
                   AND (ca.account_type NOT IN ('revenue','expense') OR e.entry_date >= ?)
                   AND (ca.account_type NOT IN ('asset','liability','equity') OR ? IS NULL OR e.entry_date >= ?)
                   AND " . JournalTaxOrigin::includedSql() . "
            ),
            grp AS (
                SELECT pairing_id,
                       SUM(signed) AS net,
                       SUM(CASE WHEN effective_side = 'debit' THEN amount ELSE 0 END) AS md,
                       SUM(CASE WHEN effective_side = 'credit' THEN amount ELSE 0 END) AS d
                  FROM base
                 WHERE pairing_id IS NOT NULL
                 GROUP BY pairing_id
            ),
            calc AS (
                SELECT b.*,
                       CASE
                         WHEN b.pairing_id IS NULL THEN b.amount
                         WHEN g.net = 0 THEN 0
                         WHEN (g.net > 0 AND b.effective_side = 'debit') OR (g.net < 0 AND b.effective_side = 'credit') THEN
                           LEAST(b.amount, GREATEST(0,
                             SUM(b.amount) OVER (PARTITION BY b.pairing_id, b.effective_side
                                                 ORDER BY b.entry_date, b.entry_id, b.line_no
                                                 ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
                             - CASE WHEN g.net > 0 THEN g.d ELSE g.md END))
                         ELSE 0
                       END AS open_amount
                  FROM base b
             LEFT JOIN grp g ON g.pairing_id = b.pairing_id
            ),
            opened AS (
                SELECT c.*,
                       CASE WHEN c.effective_side = 'debit' THEN c.open_amount ELSE -c.open_amount END AS open_signed,
                       SUM(c.signed) OVER (ORDER BY c.entry_date, c.entry_id, c.line_no
                                           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_balance,
                       SUM(CASE WHEN c.effective_side = 'debit' THEN c.open_amount ELSE -c.open_amount END)
                           OVER (ORDER BY c.entry_date, c.entry_id, c.line_no
                                 ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_open
                  FROM calc c
            )";
        return [$sql, [
            $supplierId, $accountId, $accountId, $asOf, $periodStart, $anchor, $anchor,
            ClosingSourceId::STOCK_SLOT_BASE,
        ]];
    }
}
