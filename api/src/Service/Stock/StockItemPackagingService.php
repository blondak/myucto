<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockPackagingUnitRepository;
use MyInvoice\Repository\StockTrackingRepository;
use PDO;

/**
 * Balení skladové karty (issue #17) — čtení a zápis převodů „1 KT = 8 ks" včetně
 * EAN balení a výchozí prodejní jednotky.
 *
 * Balení jsou řádky `stock_item_units` s `is_sales_unit = 1`; převodní jednotky
 * šarží (`is_sales_unit = 0`, editor sledování) tahle služba nečte, nemaže ani
 * nemění a jejich kód pro balení použít nejde (`packaging_unit_code_clash`).
 *
 * Tvrdé pravidlo: kód balení, který nese JAKÝKOLI řádek vydané nebo přijaté
 * faktury této karty (i draft), nejde odebrat ani mu změnit poměr — množství
 * toho řádku by se pod rukama přepočítalo jinak, než s jakým byl pořízen.
 */
final class StockItemPackagingService
{
    private const MAX_UNITS = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly StockTrackingRepository $tracking,
        private readonly StockPackagingUnitRepository $codebook,
    ) {}

    /** @return array{base_unit:string, default_sale_unit:?string, units:list<array<string,mixed>>} */
    public function get(int $supplierId, int $itemId): array
    {
        $item = $this->requireItem($supplierId, $itemId);
        $names = $this->codebook->byLowerCode($supplierId);
        $used = $this->usedCodes($supplierId, $itemId);
        $units = [];
        foreach ($this->tracking->units($supplierId, $itemId, true) as $u) {
            $lower = mb_strtolower((string) $u['unit_code']);
            $units[] = self::unitPayload($u, $names[$lower]['name'] ?? null) + ['in_use' => isset($used[$lower])];
        }
        return [
            'base_unit'         => (string) $item['unit'],
            'default_sale_unit' => $item['default_sale_unit'] !== null ? (string) $item['default_sale_unit'] : null,
            'units'             => $units,
        ];
    }

    /**
     * @param array<string,mixed> $body {default_sale_unit: ?string, units: list<{unit_code, factor, ean}>}
     * @return array{base_unit:string, default_sale_unit:?string, units:list<array<string,mixed>>}
     */
    public function save(int $supplierId, int $itemId, array $body): array
    {
        $item = $this->requireItem($supplierId, $itemId);
        $raw = $body['units'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_UNITS) {
            throw new StockException('validation_failed', 'units musí být seznam nejvýše 100 balení.', 422);
        }

        $existing = [];
        foreach ($this->tracking->units($supplierId, $itemId, true) as $u) {
            $existing[mb_strtolower((string) $u['unit_code'])] = $u;
        }
        $trackingCodes = [];
        foreach ($this->tracking->units($supplierId, $itemId, false) as $u) {
            $trackingCodes[mb_strtolower((string) $u['unit_code'])] = true;
        }
        $codebook = $this->codebook->byLowerCode($supplierId);
        $baseLower = mb_strtolower((string) $item['unit']);

        $units = [];
        $seen = [];
        $eans = [];
        foreach ($raw as $index => $entry) {
            if (!is_array($entry)) {
                throw new StockException('validation_failed', 'Neplatné balení.', 422, ['index' => $index]);
            }
            $code = trim((string) ($entry['unit_code'] ?? ''));
            $lower = mb_strtolower($code);
            if ($code === '' || mb_strlen($code) > 20) {
                throw new StockException('validation_failed', 'Kód balení je povinný (max 20 znaků).', 422, ['index' => $index]);
            }
            if ($lower === $baseLower) {
                throw new StockException('validation_failed', 'Balení nesmí mít kód základní jednotky karty.', 422, ['index' => $index, 'unit_code' => $code]);
            }
            if (isset($seen[$lower])) {
                throw new StockException('validation_failed', 'Balení se na kartě opakuje.', 422, ['index' => $index, 'unit_code' => $code]);
            }
            $seen[$lower] = true;
            if (isset($trackingCodes[$lower])) {
                throw new StockException('packaging_unit_code_clash', 'Kód už používá převodní jednotka šarží této karty.', 422, ['index' => $index, 'unit_code' => $code]);
            }

            // Kód musí být v aktivním číselníku firmy; balení, které už na kartě je
            // (i s mezitím deaktivovaným kódem v číselníku), se zachová.
            $fromCodebook = $codebook[$lower] ?? null;
            if ($fromCodebook !== null && $fromCodebook['is_active']) {
                $canonical = (string) $fromCodebook['code'];
            } elseif (isset($existing[$lower])) {
                $canonical = (string) $existing[$lower]['unit_code'];
            } else {
                throw new StockException('packaging_unit_unknown', 'Kód balení není v aktivním číselníku balení.', 422, ['index' => $index, 'unit_code' => $code]);
            }

            [$num, $den] = $this->ratioOf($entry, $existing[$lower] ?? null);

            $ean = trim((string) ($entry['ean'] ?? ''));
            if ($ean !== '' && (mb_strlen($ean) > 20 || preg_match('/\s/u', $ean) === 1)) {
                throw new StockException('validation_failed', 'EAN balení má nejvýše 20 znaků bez mezer.', 422, ['index' => $index]);
            }
            if ($ean !== '') {
                if (isset($eans[$ean])) {
                    throw new StockException('ean_duplicate', 'EAN balení se opakuje.', 422, ['ean' => $ean, 'unit_code' => $canonical]);
                }
                $eans[$ean] = $canonical;
            }
            $units[] = ['unit_code' => $canonical, 'numerator' => $num, 'denominator' => $den, 'ean' => $ean !== '' ? $ean : null];
        }

        $default = trim((string) ($body['default_sale_unit'] ?? ''));
        $defaultCanonical = null;
        if ($default !== '') {
            foreach ($units as $u) {
                if (mb_strtolower($u['unit_code']) === mb_strtolower($default)) {
                    $defaultCanonical = $u['unit_code'];
                }
            }
            if ($defaultCanonical === null) {
                throw new StockException('validation_failed', 'Výchozí prodejní jednotka musí být základní jednotka nebo některé balení karty.', 422, ['default_sale_unit' => $default]);
            }
        }

        $this->assertEansFree($supplierId, $itemId, $eans);
        $this->assertChangeAllowed($supplierId, $itemId, $units);

        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $this->tracking->replaceSalesUnits($supplierId, $itemId, $units);
            $pdo->prepare('UPDATE stock_items SET default_sale_unit = ? WHERE supplier_id = ? AND id = ?')
                ->execute([$defaultCanonical, $supplierId, $itemId]);
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->get($supplierId, $itemId);
    }

    /**
     * Pojistka proti přepsání historie: odebrat balení nebo změnit jeho poměr lze
     * jen tehdy, když ho nenese žádný řádek faktury této karty (ani draft).
     *
     * @param list<array{unit_code:string, numerator:int, denominator:int}> $newUnits
     */
    public function assertChangeAllowed(int $supplierId, int $itemId, array $newUnits): void
    {
        $new = [];
        foreach ($newUnits as $u) {
            $new[mb_strtolower((string) $u['unit_code'])] = $u;
        }
        $used = null;
        foreach ($this->tracking->units($supplierId, $itemId, true) as $old) {
            $lower = mb_strtolower((string) $old['unit_code']);
            $next = $new[$lower] ?? null;
            $changed = $next === null
                || (int) $next['numerator'] * (int) $old['denominator'] !== (int) $old['numerator'] * (int) $next['denominator'];
            if (!$changed) {
                continue;
            }
            $used ??= $this->usedCodes($supplierId, $itemId);
            if (isset($used[$lower])) {
                throw new StockException(
                    'packaging_unit_in_use',
                    'Balení ' . $old['unit_code'] . ' už nesou řádky faktur — nelze ho odebrat ani změnit jeho poměr.',
                    422,
                    ['unit_code' => (string) $old['unit_code']],
                );
            }
        }
    }

    /**
     * Kódy jednotek (malými písmeny), které nesou řádky vydaných i přijatých
     * faktur karty — včetně draftů.
     *
     * @return array<string,true>
     */
    public function usedCodes(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.unit AS unit
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id AND i.supplier_id = ?
              WHERE ii.stock_item_id = ?
             UNION
             SELECT pii.unit AS unit
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id AND pi.supplier_id = ?
              WHERE pii.stock_item_id = ?'
        );
        $stmt->execute([$supplierId, $itemId, $supplierId, $itemId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $unit) {
            $out[mb_strtolower(trim((string) $unit))] = true;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $unit řádek stock_item_units
     * @return array{unit_code:string, name:?string, factor:string, numerator:int, denominator:int, ean:?string}
     */
    public static function unitPayload(array $unit, ?string $name): array
    {
        return [
            'unit_code'   => (string) $unit['unit_code'],
            'name'        => $name,
            'factor'      => StockUnitConverter::formatFactor((int) $unit['numerator'], (int) $unit['denominator']),
            'numerator'   => (int) $unit['numerator'],
            'denominator' => (int) $unit['denominator'],
            'ean'         => isset($unit['ean']) && $unit['ean'] !== '' ? (string) $unit['ean'] : null,
        ];
    }

    /**
     * Poměr řádku: přesný zlomek, pokud ho klient poslal; jinak desetinný `factor`.
     * Když `factor` jen vrací zaokrouhlené zobrazení stávajícího poměru (1/3 → „0.333"),
     * stávající zlomek se zachová — jinak by uložení beze změny vypadalo jako změna
     * poměru a narazilo na pojistku vystavených dokladů.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed>|null $existing
     * @return array{0:int, 1:int}
     */
    private function ratioOf(array $entry, ?array $existing): array
    {
        if (isset($entry['numerator'], $entry['denominator']) && !isset($entry['factor'])) {
            return ExactUnitConversion::reduce((int) $entry['numerator'], (int) $entry['denominator']);
        }
        if (!array_key_exists('factor', $entry) || $entry['factor'] === null || $entry['factor'] === '') {
            throw new StockException('invalid_unit_ratio', 'Poměr balení je povinný.', 422, ['unit_code' => (string) ($entry['unit_code'] ?? '')]);
        }
        [$num, $den] = StockUnitConverter::parseFactor($entry['factor']);
        if ($existing !== null) {
            $shown = StockUnitConverter::formatFactor((int) $existing['numerator'], (int) $existing['denominator']);
            if ($shown === StockUnitConverter::formatFactor($num, $den)) {
                return [(int) $existing['numerator'], (int) $existing['denominator']];
            }
        }
        return [$num, $den];
    }

    /**
     * EAN balení je unikátní v rámci firmy — proti EAN karet i proti EAN jiných balení.
     *
     * @param array<string,string> $eans ean => kód balení
     */
    private function assertEansFree(int $supplierId, int $itemId, array $eans): void
    {
        if ($eans === []) {
            return;
        }
        $list = array_map('strval', array_keys($eans));
        $in = implode(',', array_fill(0, count($list), '?'));
        $stmt = $this->db->pdo()->prepare('SELECT ean FROM stock_items WHERE supplier_id = ? AND ean IN (' . $in . ') LIMIT 1');
        $stmt->execute(array_merge([$supplierId], $list));
        $hit = $stmt->fetchColumn();
        if ($hit !== false) {
            throw new StockException('ean_duplicate', 'EAN balení už nese jiná skladová karta.', 422, ['ean' => (string) $hit, 'unit_code' => $eans[(string) $hit] ?? null]);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ean, stock_item_id, unit_code FROM stock_item_units WHERE supplier_id = ? AND ean IN (' . $in . ')'
        );
        $stmt->execute(array_merge([$supplierId], $list));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $mine = (int) $r['stock_item_id'] === $itemId
                && mb_strtolower((string) $r['unit_code']) === mb_strtolower($eans[(string) $r['ean']] ?? '');
            if (!$mine) {
                throw new StockException('ean_duplicate', 'EAN balení už nese jiné balení.', 422, ['ean' => (string) $r['ean'], 'unit_code' => $eans[(string) $r['ean']] ?? null]);
            }
        }
    }

    /** @return array<string,mixed> */
    private function requireItem(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, unit, default_sale_unit FROM stock_items WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new StockException('not_found', 'Skladová karta nenalezena.', 404);
        }
        return $row;
    }
}
