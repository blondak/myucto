<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Porovnatelný obraz OSS podání za jedno čtvrtletí — jediný tvar, ve kterém se archiv
 * a dnešní náhled dají postavit vedle sebe.
 *
 * ── Proč to není „prostě diff XML" ──────────────────────────────────────────────────
 * Archivované XML nese jen AGREGÁTY (VetaR sdružuje řádky přes stát × typ plnění × typ
 * sazby × procento) a k tomu formátování a pořadí atributů. Diff dvou XML by tedy hlásil
 * rozdíl i tam, kde se nezměnilo nic věcného, a hlavně by neuměl odpovědět na otázku,
 * kvůli které rekonciliace existuje: KTERÝ DOKLAD se po podání změnil. Snapshot proto
 * drží obojí — agregáty v přesně té struktuře, jakou má podání, a vedle nich seznam
 * dokladů, ze kterých agregáty vznikly.
 *
 * ── Otisk dokladu, ne jen `updated_at` ──────────────────────────────────────────────
 * Vzor přebírá {@see \MyInvoice\Service\Report\VatPostFilingChangesService} u DPH: pouhé
 * `updated_at > generated_at` je slabý test — dotkne se ho i editace, která na podání
 * nemá vliv (poznámka, splatnost), a naopak ho vůbec nevyvolá doklad, který z období
 * ZMIZEL (storno, přesun DUZP), protože ten se v aktuální projekci už nenajde. Snapshot
 * proto ukládá i věcné hodnoty řádku (stát, sazba, základ, daň, opravované období) a
 * rekonciliace porovnává je; `updated_at` slouží jen jako doplňková informace „kdy".
 *
 * ── Verze schématu ──────────────────────────────────────────────────────────────────
 * `schema` je v datech schválně: archivy vzniklé před touhle epikou snapshot NEMAJÍ
 * a rekonciliace to musí umět přiznat (`snapshot_available=false`) místo aby mlčky
 * porovnala prázdno s dneškem a vyhlásila shodu. Tichá nula je přesně ta třída chyby,
 * kterou si vynutil audit účetního jádra.
 */
final class OssFilingSnapshot
{
    public const SCHEMA = 'oss-filing-snapshot.v1';

    /** Tolerance porovnání částek — obě strany jsou zaokrouhlené na haléře/centy. */
    private const AMOUNT_EPSILON = 0.005;

    public function __construct(private readonly Connection $db) {}

    /**
     * Snapshot z náhledu ({@see OssLedgerService::preview()}).
     *
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    public function fromPreview(int $supplierId, array $preview): array
    {
        $rows = [];
        $documents = [];

        foreach (($preview['countries'] ?? []) as $country) {
            foreach (($country['rows'] ?? []) as $row) {
                self::addAggregate($rows, [
                    'country'     => strtoupper((string) ($country['country'] ?? '')),
                    'supply_type' => $row['supply_type'] !== null ? (string) $row['supply_type'] : null,
                    'rate_type'   => $row['rate_type'] !== null ? (string) $row['rate_type'] : null,
                    'rate'        => (float) ($row['vat_rate'] ?? 0.0),
                ], (float) ($row['base_return'] ?? 0.0), (float) ($row['vat_return'] ?? 0.0));
                $documents[] = self::documentRef($row, strtoupper((string) ($country['country'] ?? '')), null);
            }
        }

        $corrections = [];
        foreach (($preview['corrections'] ?? []) as $correction) {
            // Klíče se čtou obranně: náhled je vstup z jiné vrstvy a chybějící klíč tu
            // nesmí shodit archivaci podání, které se právě generuje.
            $period = (string) ($correction['period'] ?? '');
            $country = strtoupper((string) ($correction['state_consumption'] ?? ''));
            $corrections[$period . '|' . $country] = [
                'period'  => $period,
                'country' => $country,
                'amount'  => round((float) ($correction['correction'] ?? 0.0), 2),
            ];
            foreach (($correction['rows'] ?? []) as $row) {
                $documents[] = self::documentRef($row, $country, $period);
            }
        }

        ksort($corrections);
        usort($documents, static fn (array $a, array $b): int => [$a['invoice_id'], $a['item_id']] <=> [$b['invoice_id'], $b['item_id']]);

        $summary = (array) ($preview['summary'] ?? []);

        return [
            'schema'          => self::SCHEMA,
            'return_currency' => (string) ($summary['return_currency'] ?? 'EUR'),
            'totals'          => [
                'base'        => round((float) ($summary['total_base'] ?? 0.0), 2),
                'vat'         => round((float) ($summary['total_vat'] ?? 0.0), 2),
                'corrections' => round((float) ($summary['total_corrections'] ?? 0.0), 2),
                'payable'     => round((float) ($summary['total_payable'] ?? 0.0), 2),
            ],
            'rows'            => array_values($rows),
            'corrections'     => array_values($corrections),
            'documents'       => $this->withUpdatedAt($supplierId, $documents),
        ];
    }

    /**
     * Rozdíl mezi PODANÝM a DNEŠNÍM snapshotem. Čistá funkce — celá rekonciliace stojí
     * na ní, takže musí jít otestovat bez databáze.
     *
     * Pořadí kategorií odpovídá tomu, jak se rozdíl vysvětluje: nejdřív součty (co se
     * změnilo na odváděné dani), pak řádky podání (kde), pak konkrétní doklady (čím).
     *
     * @param array<string,mixed> $filed   snapshot uložený u archivovaného podání
     * @param array<string,mixed> $current snapshot z dnešního náhledu
     * @return array{in_sync:bool, totals:list<array<string,mixed>>, rows:list<array<string,mixed>>,
     *               corrections:list<array<string,mixed>>, documents:list<array<string,mixed>>}
     */
    public static function diff(array $filed, array $current): array
    {
        $totals = [];
        foreach (['base', 'vat', 'corrections', 'payable'] as $key) {
            $before = round((float) ($filed['totals'][$key] ?? 0.0), 2);
            $after = round((float) ($current['totals'][$key] ?? 0.0), 2);
            if (abs($after - $before) > self::AMOUNT_EPSILON) {
                $totals[] = ['key' => $key, 'filed' => $before, 'current' => $after, 'delta' => round($after - $before, 2)];
            }
        }

        $rows = self::diffKeyed(
            self::index($filed['rows'] ?? [], static fn (array $r): string => self::aggregateKey($r)),
            self::index($current['rows'] ?? [], static fn (array $r): string => self::aggregateKey($r)),
            ['base', 'vat'],
        );

        $corrections = self::diffKeyed(
            self::index($filed['corrections'] ?? [], static fn (array $r): string => $r['period'] . '|' . $r['country']),
            self::index($current['corrections'] ?? [], static fn (array $r): string => $r['period'] . '|' . $r['country']),
            ['amount'],
        );

        $documents = self::diffDocuments($filed['documents'] ?? [], $current['documents'] ?? []);

        return [
            'in_sync'     => $totals === [] && $rows === [] && $corrections === [] && $documents === [],
            'totals'      => $totals,
            'rows'        => $rows,
            'corrections' => $corrections,
            'documents'   => $documents,
        ];
    }

    /**
     * Je snapshot použitelný k porovnání? Archivy z doby před touhle epikou ho nemají
     * a starší verze schématu by se porovnala na jiné významy klíčů.
     *
     * @param mixed $snapshot
     */
    public static function isUsable(mixed $snapshot): bool
    {
        return is_array($snapshot) && ($snapshot['schema'] ?? null) === self::SCHEMA;
    }

    /**
     * Otisk snapshotu pro rychlé „změnilo se vůbec něco". Kanonizuje se přes JSON
     * s pevným pořadím klíčů, protože pořadí v poli je už zaručené řazením výš.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode([
            $snapshot['schema'] ?? '',
            $snapshot['return_currency'] ?? '',
            $snapshot['totals'] ?? [],
            $snapshot['rows'] ?? [],
            $snapshot['corrections'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '');
    }

    /**
     * Doplní `updated_at` dokladů. Bez něj by rekonciliace uměla říct JEN „liší se",
     * ale ne „kdy se to změnilo" — a to je první otázka, kterou účetní položí.
     *
     * @param list<array<string,mixed>> $documents
     * @return list<array<string,mixed>>
     */
    private function withUpdatedAt(int $supplierId, array $documents): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $d): int => $d['invoice_id'], $documents)));
        if ($ids === []) {
            return $documents;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, status, updated_at FROM invoices WHERE supplier_id = ? AND id IN ({$ph})"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $meta = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $meta[(int) $row['id']] = [
                'status'     => (string) $row['status'],
                'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            ];
        }
        foreach ($documents as &$doc) {
            $doc['status'] = $meta[$doc['invoice_id']]['status'] ?? null;
            $doc['updated_at'] = $meta[$doc['invoice_id']]['updated_at'] ?? null;
        }
        unset($doc);

        return $documents;
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @param array{country:string, supply_type:?string, rate_type:?string, rate:float} $key
     */
    private static function addAggregate(array &$rows, array $key, float $base, float $vat): void
    {
        $k = self::aggregateKey($key);
        $rows[$k] ??= $key + ['base' => 0.0, 'vat' => 0.0];
        $rows[$k]['base'] = round($rows[$k]['base'] + $base, 2);
        $rows[$k]['vat'] = round($rows[$k]['vat'] + $vat, 2);
        ksort($rows);
    }

    /** @param array<string,mixed> $row */
    private static function aggregateKey(array $row): string
    {
        return implode('|', [
            (string) ($row['country'] ?? ''),
            (string) ($row['supply_type'] ?? ''),
            (string) ($row['rate_type'] ?? ''),
            number_format((float) ($row['rate'] ?? 0.0), 2, '.', ''),
        ]);
    }

    /**
     * @param array<string,mixed> $row položka náhledu (běžná i opravná — mají stejný tvar)
     * @return array<string,mixed>
     */
    private static function documentRef(array $row, string $country, ?string $adjustedPeriod): array
    {
        return [
            'invoice_id'      => (int) ($row['invoice_id'] ?? 0),
            'item_id'         => (int) ($row['item_id'] ?? 0),
            'doc_number'      => isset($row['doc_number']) ? (string) $row['doc_number'] : null,
            'country'         => $country,
            'tax_date'        => isset($row['tax_date']) ? (string) $row['tax_date'] : null,
            'rate'            => round((float) ($row['vat_rate'] ?? 0.0), 2),
            'base'            => round((float) ($row['base_return'] ?? 0.0), 2),
            'vat'             => round((float) ($row['vat_return'] ?? 0.0), 2),
            'adjusted_period' => $adjustedPeriod,
            // Doplní withUpdatedAt() — tvar odpovědi ale musí být hotový už tady, ať se
            // klíč nikde nedohledává podmíněně.
            'status'          => null,
            'updated_at'      => null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):string $key
     * @return array<string,array<string,mixed>>
     */
    private static function index(array $rows, callable $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$key($row)] = $row;
        }
        ksort($out);
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $filed
     * @param array<string,array<string,mixed>> $current
     * @param list<string> $amountKeys
     * @return list<array<string,mixed>>
     */
    private static function diffKeyed(array $filed, array $current, array $amountKeys): array
    {
        $out = [];
        foreach ($filed as $key => $row) {
            if (!isset($current[$key])) {
                $out[] = ['change' => 'removed', 'key' => $key, 'filed' => $row, 'current' => null];
                continue;
            }
            $changes = [];
            foreach ($amountKeys as $amountKey) {
                $before = round((float) ($row[$amountKey] ?? 0.0), 2);
                $after = round((float) ($current[$key][$amountKey] ?? 0.0), 2);
                if (abs($after - $before) > self::AMOUNT_EPSILON) {
                    $changes[$amountKey] = ['filed' => $before, 'current' => $after, 'delta' => round($after - $before, 2)];
                }
            }
            if ($changes !== []) {
                $out[] = ['change' => 'changed', 'key' => $key, 'filed' => $row, 'current' => $current[$key], 'amounts' => $changes];
            }
        }
        foreach ($current as $key => $row) {
            if (!isset($filed[$key])) {
                $out[] = ['change' => 'added', 'key' => $key, 'filed' => null, 'current' => $row];
            }
        }
        return $out;
    }

    /**
     * Doklady se párují přes `item_id`, ne přes `invoice_id`: jedna faktura může nést
     * víc OSS položek do různých států a změna jedné z nich se musí dát pojmenovat.
     * Položka smazaná z faktury se tím projeví jako `removed`, ne jako tichý pokles
     * součtu.
     *
     * @param list<array<string,mixed>> $filed
     * @param list<array<string,mixed>> $current
     * @return list<array<string,mixed>>
     */
    private static function diffDocuments(array $filed, array $current): array
    {
        $filedIdx = self::index($filed, static fn (array $r): string => (string) $r['item_id']);
        $currentIdx = self::index($current, static fn (array $r): string => (string) $r['item_id']);

        $out = [];
        foreach ($filedIdx as $key => $row) {
            if (!isset($currentIdx[$key])) {
                $out[] = ['change' => 'removed'] + $row;
                continue;
            }
            $now = $currentIdx[$key];
            $changed = (string) ($row['country'] ?? '') !== (string) ($now['country'] ?? '')
                || (string) ($row['tax_date'] ?? '') !== (string) ($now['tax_date'] ?? '')
                || (string) ($row['adjusted_period'] ?? '') !== (string) ($now['adjusted_period'] ?? '')
                || abs((float) $row['rate'] - (float) $now['rate']) > self::AMOUNT_EPSILON
                || abs((float) $row['base'] - (float) $now['base']) > self::AMOUNT_EPSILON
                || abs((float) $row['vat'] - (float) $now['vat']) > self::AMOUNT_EPSILON;
            if ($changed) {
                $out[] = ['change' => 'changed'] + $now + ['filed' => $row];
            }
            unset($currentIdx[$key]);
        }
        foreach ($currentIdx as $row) {
            $out[] = ['change' => 'added'] + $row;
        }

        return $out;
    }
}
