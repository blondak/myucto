<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * JEDINÉ místo, které převádí množství řádku dokladu na ZÁKLADNÍ jednotku skladové
 * karty (issue #17 — balení).
 *
 * Balení karty drží `stock_item_units` jako přesný zlomek: 1 alternativní jednotka
 * = numerator/denominator základních jednotek, tedy base = qty × numerator / denominator.
 *
 * Pravidlo převodu:
 *  - firma nemá zapnutý sklad → vždy 1:1 (bez sklad modulu se nic nepřepočítává),
 *  - jednotka je prázdná nebo shodná se základní jednotkou karty (bez ohledu na
 *    velikost písmen) → 1:1,
 *  - jednotka je kód BALENÍ karty (`is_sales_unit = 1`, bez ohledu na velikost
 *    písmen) → × poměr,
 *  - jiná jednotka — neznámá, nebo převodní jednotka šarží (`is_sales_unit = 0`)
 *    → 1:1. To je dnešní chování všech dokladů, které vznikly před balením,
 *    a nesmí se změnit.
 *
 * Kdo čte `quantity` skladového řádku faktury (výdej, vratka, kontrola dostupnosti,
 * příjem z přijaté faktury, rezervace, čerpání akce, Intrastat), převádí přes
 * {@see ratios()} / {@see applyRatio()}; SQL agregace přes {@see sqlUnitJoin()}
 * a {@see sqlToBase()}, aby pravidlo žilo na jednom místě i v dotazech.
 */
final class StockUnitConverter
{
    public const QTY_SCALE = 3;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{unit:string, base_unit:string, numerator:int, denominator:int, is_base:bool}
     */
    public function ratio(int $supplierId, int $itemId, ?string $unitCode): array
    {
        return $this->ratios($supplierId, [['stock_item_id' => $itemId, 'unit' => $unitCode]])[0];
    }

    /** Množství v zadané jednotce → množství v základní jednotce (string, 3 desetinná místa). */
    public function toBase(int $supplierId, int $itemId, ?string $unitCode, string $qty): string
    {
        $r = $this->ratio($supplierId, $itemId, $unitCode);
        return self::applyRatio($qty, $r['numerator'], $r['denominator']);
    }

    /**
     * Dávková varianta pro celé doklady — dva dotazy bez ohledu na počet řádků.
     * Klíče vstupu se zachovají.
     *
     * `unit` ve výsledku = jednotka, ve které se řádek skutečně počítá: kód balení
     * v kanonickém zápisu karty, jinak základní jednotka.
     *
     * @param array<int|string, array{stock_item_id:int, unit:?string}> $lines
     * @return array<int|string, array{unit:string, base_unit:string, numerator:int, denominator:int, is_base:bool}>
     */
    public function ratios(int $supplierId, array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $id = (int) $line['stock_item_id'];
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $baseUnits = [];
        $units = [];
        if ($ids !== [] && !$this->stockEnabled($supplierId)) {
            $out = [];
            foreach ($lines as $key => $line) {
                $unit = trim((string) ($line['unit'] ?? ''));
                $out[$key] = ['unit' => $unit, 'base_unit' => $unit, 'numerator' => 1, 'denominator' => 1, 'is_base' => true];
            }
            return $out;
        }
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$supplierId], array_values($ids));
            $stmt = $this->db->pdo()->prepare('SELECT id, unit FROM stock_items WHERE supplier_id = ? AND id IN (' . $in . ')');
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $baseUnits[(int) $r['id']] = (string) $r['unit'];
            }
            $stmt = $this->db->pdo()->prepare(
                'SELECT stock_item_id, unit_code, numerator, denominator FROM stock_item_units
                  WHERE supplier_id = ? AND is_sales_unit = 1 AND stock_item_id IN (' . $in . ')'
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $units[(int) $r['stock_item_id']][mb_strtolower((string) $r['unit_code'])] = [
                    'unit_code'   => (string) $r['unit_code'],
                    'numerator'   => (int) $r['numerator'],
                    'denominator' => (int) $r['denominator'],
                ];
            }
        }

        $out = [];
        foreach ($lines as $key => $line) {
            $itemId = (int) $line['stock_item_id'];
            $base = $baseUnits[$itemId] ?? '';
            $unit = trim((string) ($line['unit'] ?? ''));
            $lower = mb_strtolower($unit);
            $alt = ($unit === '' || $lower === mb_strtolower($base)) ? null : ($units[$itemId][$lower] ?? null);
            $out[$key] = $alt === null
                ? ['unit' => $base, 'base_unit' => $base, 'numerator' => 1, 'denominator' => 1, 'is_base' => true]
                : ['unit' => $alt['unit_code'], 'base_unit' => $base, 'numerator' => $alt['numerator'], 'denominator' => $alt['denominator'], 'is_base' => false];
        }
        return $out;
    }

    /**
     * qty × numerator / denominator, zaokrouhleno half-up (od nuly) na $scale míst.
     * Znaménko se zachová — záporný řádek faktury zůstane záporný.
     */
    public static function applyRatio(string $qty, int $numerator, int $denominator, int $scale = self::QTY_SCALE): string
    {
        $q = self::normalizeNumber($qty);
        if ($numerator === $denominator) {
            return self::roundHalfUp($q, $scale);
        }
        $raw = bcdiv(bcmul($q, (string) $numerator, 12), (string) $denominator, 12);
        return self::roundHalfUp($raw, $scale);
    }

    /**
     * Částka za alternativní jednotku → částka za základní jednotku (÷ poměr).
     * Používá příjem z přijaté faktury: hodnota řádku se nemění, jen se rozloží
     * na víc základních jednotek.
     */
    public static function perBase(string $amount, int $numerator, int $denominator, int $scale = 6): string
    {
        $a = self::normalizeNumber($amount);
        $raw = bcdiv(bcmul($a, (string) $denominator, 12), (string) $numerator, 12);
        return self::roundHalfUp($raw, $scale);
    }

    /** Poměr jako desetinné číslo pro UI: nejvýš 3 desetinná místa, bez koncových nul. */
    public static function formatFactor(int $numerator, int $denominator): string
    {
        $v = self::roundHalfUp(bcdiv((string) $numerator, (string) $denominator, 12), 3);
        $v = rtrim(rtrim($v, '0'), '.');
        return $v === '' ? '0' : $v;
    }

    /**
     * Desetinný poměr z UI („8", „0.5", „1.25") → přesný zlomek. Kladný, nejvýš
     * 3 desetinná místa; mez zlomku hlídá {@see ExactUnitConversion::reduce()}.
     *
     * @return array{0:int, 1:int} [numerator, denominator]
     */
    public static function parseFactor(mixed $factor): array
    {
        if (is_int($factor)) {
            $factor = (string) $factor;
        } elseif (is_float($factor)) {
            $factor = rtrim(rtrim(number_format($factor, 3, '.', ''), '0'), '.');
        }
        $s = str_replace(',', '.', trim((string) $factor));
        if (!preg_match('/^([0-9]{1,7})(?:\.([0-9]{1,3}))?$/D', $s, $m)) {
            throw new StockException('invalid_unit_ratio', 'Poměr balení musí být kladné číslo s nejvýše třemi desetinnými místy.', 422);
        }
        $decimals = $m[2] ?? '';
        $num = (int) ($m[1] . $decimals);
        $den = 10 ** strlen($decimals);
        if ($num <= 0) {
            throw new StockException('invalid_unit_ratio', 'Poměr balení musí být větší než nula.', 422);
        }
        $a = $num;
        $b = $den;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return ExactUnitConversion::reduce(intdiv($num, $a), intdiv($den, $a));
    }

    /**
     * LEFT JOIN na balení karty pro SQL agregace nad řádky dokladů. Páruje se jen
     * balení (`is_sales_unit = 1`) firmy se zapnutým skladem; jednotka shodná se
     * základní jednotkou karty se nepáruje (1:1), stejně jako v {@see ratios()}.
     * Porovnání kódů je case-insensitive díky collation `utf8mb4_unicode_ci`.
     */
    public static function sqlUnitJoin(string $alias, string $supplierExpr, string $itemExpr, string $unitExpr): string
    {
        return " LEFT JOIN supplier {$alias}_sup
                        ON {$alias}_sup.id = {$supplierExpr} AND {$alias}_sup.stock_enabled = 1
                 LEFT JOIN stock_items {$alias}_si
                        ON {$alias}_si.id = {$itemExpr} AND {$alias}_si.supplier_id = {$alias}_sup.id
                 LEFT JOIN stock_item_units {$alias}
                        ON {$alias}.supplier_id = {$alias}_si.supplier_id
                       AND {$alias}.stock_item_id = {$alias}_si.id
                       AND {$alias}.is_sales_unit = 1
                       AND {$alias}.unit_code = {$unitExpr}
                       AND {$alias}.unit_code <> {$alias}_si.unit ";
    }

    /**
     * Výraz „množství v základní jednotce" nad joinem z {@see sqlUnitJoin()}.
     * Řádek bez balení vrací PŮVODNÍ výraz beze změny (stejná hodnota i měřítko
     * jako před balením); jen balení se přepočte a zaokrouhlí na 3 místa.
     */
    public static function sqlToBase(string $qtyExpr, string $alias): string
    {
        return "(CASE WHEN {$alias}.id IS NULL THEN {$qtyExpr}"
            . " ELSE ROUND({$qtyExpr} * {$alias}.numerator / {$alias}.denominator, " . self::QTY_SCALE . ') END)';
    }

    /** Výraz „částka za základní jednotku" nad joinem z {@see sqlUnitJoin()}. */
    public static function sqlPerBase(string $amountExpr, string $alias): string
    {
        return "({$amountExpr} * COALESCE({$alias}.denominator, 1) / COALESCE({$alias}.numerator, 1))";
    }

    private function stockEnabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private static function normalizeNumber(string $v): string
    {
        $s = str_replace(',', '.', trim($v));
        if ($s === '' || !is_numeric($s)) {
            return '0';
        }
        // bcmath neumí exponent ani „+"; is_numeric je propustí, proto přes sprintf.
        if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/D', $s) !== 1) {
            $s = rtrim(rtrim(sprintf('%.6F', (float) $s), '0'), '.');
        }
        return $s;
    }

    private static function roundHalfUp(string $v, int $scale): string
    {
        $shift = '0.' . str_repeat('0', $scale) . '5';
        return str_starts_with($v, '-')
            ? bcsub($v, $shift, $scale)
            : bcadd($v, $shift, $scale);
    }
}
