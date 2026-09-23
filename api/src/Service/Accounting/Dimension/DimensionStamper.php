<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use PDO;

/**
 * Dimenze zdrojového dokladu → řádky účetního zápisu (Firma → Dimenze).
 *
 * Volá ho {@see \MyInvoice\Service\Accounting\PostingService::postDocument()} pro
 * každý zápis, stejně jako razítko zakázky: zápis vzniká mnoha cestami a dimenze,
 * kterou by razítkovala jen část z nich, by sestavy po dimenzích tiše zkreslila.
 *
 * Pravidla:
 *   • Hlavička dokladu platí pro všechny řádky zápisu. Typ, který hlavička nemá,
 *     doplní výchozí dimenze zakázky, jinak klienta ({@see DimensionDefaults}) —
 *     i u dokladů z importu, vytěžení a automatizací, které editor neviděly.
 *   • Dimenze položky přebíjí hlavičku (typ po typu) na výsledkových řádcích
 *     (náklad, výnos). Nesou-li položky jednoho výsledkového řádku různé dimenze,
 *     řádek se při zaúčtování rozdělí v poměru základu položek — účet, strana
 *     a součet zůstávají, zápis zůstává vyvážený na haléř.
 *   • Dimenze, kterou řádek přinesl sám (ruční zápis), má přednost.
 *   • Hodnota Střediska navázaná na číselník středisek doplní textový
 *     `cost_center`, hodnota Projektu navázaná na zakázku doplní `project_id` —
 *     jen tam, kde řádek žádný nemá (mzdové kódy středisek zůstávají).
 *
 * Přerazítkování už zaúčtovaného dokladu ({@see restamp()}) řádky nedělí: změnilo by
 * to částky řádků, a to smí jen přeúčtování. Když by dělení bylo potřeba, vrátí
 * `needs_repost` a řádek dostane jen hlavičkové dimenze.
 */
final class DimensionStamper
{
    /** Zdroj zápisu v deníku => [typ dokladu v document_dimensions, tabulka položek, sloupec dokladu]. */
    public const SOURCES = [
        'purchase_invoice' => ['purchase_invoice', 'purchase_invoice_items', 'purchase_invoice_id'],
        'invoice' => ['invoice', 'invoice_items', 'invoice_id'],
        'cash' => ['cash_document', null, null],
        'bank' => ['bank_transaction', null, null],
    ];

    /** @var array<int,bool> */
    private array $enabledCache = [];

    private ?DimensionRuleService $rules = null;

    public function __construct(private readonly Connection $db) {}

    public function enabled(int $supplierId): bool
    {
        return $this->enabledCache[$supplierId] ??= (new DimensionRepository($this->db))->enabled($supplierId);
    }

    public function rules(): DimensionRuleService
    {
        return $this->rules ??= new DimensionRuleService($this->db);
    }

    /**
     * @param list<array<string,mixed>> $resolved řádky po překladu účtů (account_id, side, amount, …)
     * @param array<int,int>|null $itemAccounts id položky => id účtu, na který se položka účtuje (je-li známé)
     * @param string|null $entryDate datum účetního případu — podle něj platí pravidla dimenzí (null = pravidla se neuplatní)
     * @return list<array<string,mixed>>
     */
    public function stamp(int $supplierId, string $sourceType, ?int $sourceId, array $resolved, ?array $itemAccounts = null, ?string $entryDate = null): array
    {
        $hasExplicit = false;
        foreach ($resolved as $line) {
            if (!empty($line['dimensions']) || !empty($line['dimension_splits'])) {
                $hasExplicit = true;
                break;
            }
        }
        $enabled = $this->enabled($supplierId);
        if (!$hasExplicit && !$enabled) {
            return $resolved;
        }
        [$header, $items, $splits] = $sourceId !== null && isset(self::SOURCES[$sourceType]) && $enabled
            ? $this->documentContext($supplierId, $sourceType, $sourceId, $itemAccounts)
            : [[], [], []];
        if ($header !== [] || $items !== [] || $hasExplicit) {
            $resolved = self::expandSplits(
                self::assign($resolved, $header, $items, $this->accountTypes($supplierId), true)['lines'],
                $splits,
            );
        }
        if ($enabled && $entryDate !== null) {
            // Výchozí hodnoty pravidel dimenzí až po dokladu — doklad má vždy přednost.
            $resolved = $this->rules()->applyDefaults($supplierId, $sourceType, $sourceId, $resolved, $entryDate);
        }
        return $this->syncLinkedColumns($supplierId, $resolved);
    }

    /**
     * Rozpad dokladu (hlavička nebo položka) jede jádrem {@see assign()} jako zástupná
     * záporná „hodnota" (-1 = první rozpad dokladu): položky se stejným rozpadem se tak
     * seskupí stejně jako položky se stejnou hodnotou. Tady se zástupná hodnota nahradí
     * rozpadem řádku (`dimension_splits`). Rozpad zadaný přímo na řádku (ruční zápis)
     * přebíjí jedinou hodnotu téhož typu.
     *
     * @param list<array<string,mixed>> $lines
     * @param list<array<int,float>> $splits zástupný index => hodnota => podíl
     * @return list<array<string,mixed>>
     */
    public static function expandSplits(array $lines, array $splits): array
    {
        foreach ($lines as $i => $line) {
            $dims = (array) ($line['dimensions'] ?? []);
            $lineSplits = (array) ($line['dimension_splits'] ?? []);
            foreach ($dims as $typeId => $valueId) {
                if ((int) $valueId < 0) {
                    unset($dims[$typeId]);
                    if (!isset($lineSplits[$typeId]) && isset($splits[-(int) $valueId - 1])) {
                        $lineSplits[$typeId] = $splits[-(int) $valueId - 1];
                    }
                } elseif (isset($lineSplits[$typeId])) {
                    unset($dims[$typeId]);
                }
            }
            unset($lines[$i]['dimensions'], $lines[$i]['dimension_splits']);
            if ($dims !== []) {
                $lines[$i]['dimensions'] = $dims;
            }
            if ($lineSplits !== []) {
                ksort($lineSplits);
                $lines[$i]['dimension_splits'] = $lineSplits;
            }
        }
        return array_values($lines);
    }

    /**
     * Dimenze už zaúčtovaného dokladu se promítnou do jeho (nestornovaných) zápisů.
     *
     * @return array{lines:int, needs_repost:bool}
     */
    public function restamp(int $supplierId, string $sourceType, int $sourceId): array
    {
        if (!isset(self::SOURCES[$sourceType]) || !$this->enabled($supplierId)) {
            return ['lines' => 0, 'needs_repost' => false];
        }
        $pdo = $this->db->pdo();
        $entries = $pdo->prepare(
            'SELECT id, entry_date FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL'
        );
        $entries->execute([$supplierId, $sourceType, $sourceId]);
        $entryDates = [];
        foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $entryDates[(int) $e['id']] = (string) $e['entry_date'];
        }
        if ($entryDates === []) {
            return ['lines' => 0, 'needs_repost' => false];
        }
        [$header, $items, $splits] = $this->documentContext($supplierId, $sourceType, $sourceId, null);
        $types = $this->accountTypes($supplierId);
        $assignments = new DimensionAssignmentRepository($this->db);
        $changed = 0;
        $needsRepost = false;
        foreach ($entryDates as $entryId => $entryDate) {
            $stmt = $pdo->prepare(
                'SELECT id, account_id, side, amount, currency_code FROM journal_entry_lines
                  WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id'
            );
            $stmt->execute([$supplierId, $entryId]);
            $current = $assignments->entryLineDimensions($supplierId, $entryId);
            // Rozpad, který řádek už nese, se pro porovnání převede na zástupnou hodnotu
            // téhož rozpadu dokladu — řádek rozdělený při zaúčtování si ho ponechá.
            foreach ($assignments->entryLineSplits($supplierId, $entryId) as $lineId => $byType) {
                foreach ($byType as $typeId => $shares) {
                    foreach ($splits as $k => $docShares) {
                        if (DimensionAssignmentRepository::sameSplits([$typeId => $shares], [$typeId => $docShares])) {
                            $current[$lineId][$typeId] = -($k + 1);
                            break;
                        }
                    }
                }
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $siblings = [];
            foreach ($rows as $l) {
                $key = $l['account_id'] . '|' . $l['side'];
                $siblings[$key] = ($siblings[$key] ?? 0) + 1;
            }
            $lines = array_map(static function (array $l) use ($current, $siblings): array {
                $l['current_dimensions'] = $current[(int) $l['id']] ?? [];
                $l['split_siblings'] = $siblings[$l['account_id'] . '|' . $l['side']];
                return $l;
            }, $rows);
            $result = self::assign($lines, $header, $items, $types, false);
            $needsRepost = $needsRepost || $result['needs_split'];
            $restamped = $this->rules()->applyDefaults(
                $supplierId,
                $sourceType,
                $sourceId,
                self::expandSplits($result['lines'], $splits),
                $entryDate,
            );
            foreach ($restamped as $line) {
                $dimsChanged = $assignments->replaceLineDimensions($supplierId, (int) $line['id'], $line['dimensions'] ?? []);
                $splitsChanged = $assignments->replaceLineSplits($supplierId, (int) $line['id'], $line['dimension_splits'] ?? []);
                if ($dimsChanged || $splitsChanged) {
                    $changed++;
                }
            }
        }
        return ['lines' => $changed, 'needs_repost' => $needsRepost];
    }

    /**
     * Čisté jádro rozpadu (jednotkově testovatelné).
     *
     * @param list<array<string,mixed>> $lines
     * @param array<int,int> $header typ => hodnota
     * @param list<array{dims:array<int,int>, weight:float, account_id:?int}> $items
     * @param array<int,string> $accountTypes id účtu => account_type
     * @return array{lines:list<array<string,mixed>>, needs_split:bool}
     */
    public static function assign(array $lines, array $header, array $items, array $accountTypes, bool $allowSplit): array
    {
        $out = [];
        $needsSplit = false;
        foreach ($lines as $line) {
            $explicit = array_map('intval', (array) ($line['dimensions'] ?? []));
            $type = $accountTypes[(int) $line['account_id']] ?? null;
            $combos = [];
            if ($items !== [] && in_array($type, ['revenue', 'expense'], true)) {
                $relevant = array_values(array_filter(
                    $items,
                    static fn (array $it): bool => $it['account_id'] === null || $it['account_id'] === (int) $line['account_id'],
                ));
                if ($relevant === []) {
                    $relevant = $items;
                }
                foreach ($relevant as $it) {
                    $dims = self::merge($header, $it['dims']);
                    $key = self::key($dims);
                    $combos[$key]['dims'] = $dims;
                    $combos[$key]['weight'] = round(($combos[$key]['weight'] ?? 0.0) + $it['weight'], 2);
                }
            }

            if (count($combos) <= 1) {
                $dims = $combos === [] ? $header : reset($combos)['dims'];
                $out[] = self::withDims($line, self::merge($dims, $explicit));
                continue;
            }

            $weights = array_column($combos, 'weight');
            $splittable = min($weights) > 0.0 && ($line['currency_code'] ?? null) === null;
            // Řádek rozdělený už při zaúčtování nese jednu z kombinací — tu si ponechá.
            // Rozdělený je jen tehdy, když má zápis na tomtéž účtu a straně aspoň tolik
            // řádků, kolik je kombinací; jediný nerozdělený řádek by si jinak ponechal
            // dimenzi jedné položky pro celou částku a zbytek by v sestavách chyběl.
            $current = isset($line['current_dimensions']) ? self::merge([], (array) $line['current_dimensions']) : null;
            $wasSplit = ($line['split_siblings'] ?? PHP_INT_MAX) >= count($combos);
            if (!$allowSplit && $current !== null && $wasSplit && isset($combos[self::key(self::merge($current, $explicit))])) {
                $out[] = self::withDims($line, self::merge($current, $explicit));
                continue;
            }
            if (!$allowSplit || !$splittable) {
                $needsSplit = true;
                $out[] = self::withDims($line, self::merge($header, $explicit));
                continue;
            }
            $cents = self::distributeCents((int) round(((float) $line['amount']) * 100), $weights);
            foreach (array_values($combos) as $i => $combo) {
                if ($cents[$i] === 0) {
                    continue;
                }
                $part = $line;
                $part['amount'] = $cents[$i] / 100;
                $out[] = self::withDims($part, self::merge($combo['dims'], $explicit));
            }
        }
        return ['lines' => $out, 'needs_split' => $needsSplit];
    }

    /**
     * Rozdělí haléře podle vah; zbytek po zaokrouhlení dostane největší váha,
     * takže součet sedí přesně.
     *
     * @param list<float> $weights
     * @return list<int>
     */
    public static function distributeCents(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $out = [];
        $assigned = 0;
        foreach ($weights as $w) {
            $c = (int) round($total * ($w / $sum));
            $out[] = $c;
            $assigned += $c;
        }
        $residual = $total - $assigned;
        if ($residual !== 0) {
            $biggest = array_keys($weights, max($weights), true)[0];
            $out[$biggest] += $residual;
        }
        return $out;
    }

    /**
     * Hlavička, položky a rozpady dokladu. Rozpad se do map typ => hodnota vkládá jako
     * zástupná záporná hodnota, kterou po rozdělení řádků nahradí {@see expandSplits()}.
     *
     * @param array<int,int>|null $itemAccounts
     * @return array{0:array<int,int>, 1:list<array{dims:array<int,int>, weight:float, account_id:?int}>, 2:list<array<int,float>>}
     */
    private function documentContext(int $supplierId, string $sourceType, int $sourceId, ?array $itemAccounts): array
    {
        [$docType, $itemTable, $itemColumn] = self::SOURCES[$sourceType];
        $assignments = new DimensionAssignmentRepository($this->db);
        $dims = $assignments->documentDimensions($supplierId, $docType, $sourceId);
        $splits = [];
        foreach ($assignments->documentSplits($supplierId, $docType, $sourceId) as $itemNo => $byType) {
            foreach ($byType as $typeId => $shares) {
                $splits[] = $shares;
                if ($itemNo === 0) {
                    $dims['header'][$typeId] = -count($splits);
                } else {
                    $dims['items'][$itemNo][$typeId] = -count($splits);
                }
            }
        }
        ksort($dims['header']);
        $items = [];
        if ($itemTable !== null && $dims['items'] !== []) {
            // Pořadí položky = pořadí v editoru (order_index), číslováno od 1 — stejně
            // jako ho ukládá DimensionService::saveDocument().
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, total_without_vat FROM {$itemTable} WHERE {$itemColumn} = ? ORDER BY order_index, id"
            );
            $stmt->execute([$sourceId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $row) {
                $items[] = [
                    'dims' => $dims['items'][$i + 1] ?? [],
                    'weight' => round((float) $row['total_without_vat'], 2),
                    'account_id' => $itemAccounts[(int) $row['id']] ?? null,
                ];
            }
        }
        $header = DimensionDefaults::fill(
            $dims['header'],
            (new DimensionDefaults($this->db))->forSource($supplierId, $sourceType, $sourceId),
        );
        return [$header, $items, $splits];
    }

    /** @return array<int,string> */
    private function accountTypes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, account_type FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = (string) $r['account_type'];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function syncLinkedColumns(int $supplierId, array $lines): array
    {
        $valueIds = [];
        foreach ($lines as $line) {
            foreach ((array) ($line['dimensions'] ?? []) as $valueId) {
                $valueIds[] = (int) $valueId;
            }
        }
        if ($valueIds === []) {
            return $lines;
        }
        $repo = new DimensionRepository($this->db);
        $values = $repo->valuesByIds($supplierId, $valueIds);
        $centers = $repo->costCenterCodes($supplierId, array_keys($values));
        foreach ($lines as $i => $line) {
            foreach ((array) ($line['dimensions'] ?? []) as $valueId) {
                $valueId = (int) $valueId;
                if (($line['cost_center'] ?? null) === null && isset($centers[$valueId])) {
                    $lines[$i]['cost_center'] = $centers[$valueId];
                }
                if (($line['project_id'] ?? null) === null && ($values[$valueId]['project_id'] ?? null) !== null) {
                    $lines[$i]['project_id'] = $values[$valueId]['project_id'];
                }
            }
        }
        return $lines;
    }

    /**
     * @param array<int,int> $base
     * @param array<int,int> $over
     * @return array<int,int>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $typeId => $valueId) {
            $base[(int) $typeId] = (int) $valueId;
        }
        ksort($base);
        return $base;
    }

    /** @param array<int,int> $dims */
    private static function key(array $dims): string
    {
        ksort($dims);
        return json_encode($dims, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $line
     * @param array<int,int> $dims
     * @return array<string,mixed>
     */
    private static function withDims(array $line, array $dims): array
    {
        unset($line['current_dimensions'], $line['split_siblings']);
        if ($dims === []) {
            unset($line['dimensions']);
        } else {
            $line['dimensions'] = $dims;
        }
        return $line;
    }
}
