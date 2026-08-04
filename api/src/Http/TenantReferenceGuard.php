<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Vazba cizích klíčů z TĚLA requestu na aktuálního tenanta (CWE-639 / BOLA).
 *
 * Vzorec chyby, který tenhle guard zavírá: Action ověří RODIČOVSKÝ objekt z URL
 * přes SupplierGuard::owns(), ale `*_id` z těla requestu zapíše bez kontroly,
 * komu patří. Read-back JOIN pak nemá tenant predikát → cizí řádek se vrátí
 * v odpovědi útočníka (externí security report 2026-08, R2).
 *
 * Použití v Action vrstvě:
 *
 *     $bad = $this->tenantRefs->violations(
 *         SupplierGuard::currentId($request),
 *         $body,
 *         ['client_id', 'project_id', 'currency_id'],
 *     );
 *     if ($bad !== []) {
 *         return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($bad), 400);
 *     }
 *
 * ## Proč se seznam sloupců předává explicitně
 *
 * Mapa SCOPES je centrální (jedno místo, kde žije způsob scope-ování a jedno
 * místo, které hlídá schema test), ale KTERÉ sloupce se v daném requestu mají
 * ověřit, říká volající. Důvod je čistě schématický, ne stylový:
 *
 *   - `vendor_id` míří na `clients`, ne na neexistující tabulku `vendors` —
 *     název sloupce význam neurčuje;
 *   - `category_id` je v `trips` FK na `trip_categories`, ale ve `stock_category_i18n`
 *     a `stock_item_categories` FK na `stock_categories`. Jediná globální mapa by
 *     tenhle sloupec musela rozhodnout naslepo a v jednom z modulů by tiše lhala.
 *
 * Slepý sken celého těla by navíc kontroloval i klíče, které Action vůbec
 * nezapisuje (FE posílá celé objekty), a tvořil by z guardu existence oracle
 * na polích, která nikam nejdou. Explicitní výčet je zároveň dokumentace toho,
 * co Action reálně forwarduje do DB.
 */
final class TenantReferenceGuard
{
    /** Tabulka nese vlastní `supplier_id`. */
    public const VIA_SUPPLIER = 'supplier';

    /** Tabulka `supplier_id` NEMÁ — scope-uje se přes `clients.supplier_id`. */
    public const VIA_CLIENT = 'client';

    /**
     * Sloupec → [cílová tabulka, způsob scope-ování].
     *
     * Ověřeno dotazem do `information_schema.KEY_COLUMN_USAGE` /`COLUMNS`, ne
     * odhadem podle názvů (viz TenantReferenceGuardSchemaTest, který mapu drží
     * v souladu se skutečnými FK constrainty).
     *
     * Pozor na dvě věci, které z názvů sloupců vidět nejsou:
     *   - `vendor_id` → `clients` (dodavatelé i odběratelé leží v jedné tabulce,
     *     `vendors` v DB neexistuje; platí pro `purchase_invoices.vendor_id`
     *     i `fuelings.vendor_id`),
     *   - `projects` nemá `supplier_id` a scope-uje se nepřímo přes
     *     `clients.supplier_id` (FK `fk_proj_client`).
     *
     * `revenue_category_id` a `expense_category_id` deklarovaný FK nemají —
     * žijí jen tady a schema test je přeskakuje.
     *
     * @var array<string, array{0:string, 1:string}>
     */
    public const SCOPES = [
        'client_id'                  => ['clients',            self::VIA_SUPPLIER],
        'vendor_id'                  => ['clients',            self::VIA_SUPPLIER],
        'currency_id'                => ['currencies',         self::VIA_SUPPLIER],
        'payment_currency_id'        => ['currencies',         self::VIA_SUPPLIER],
        'revenue_category_id'        => ['revenue_categories', self::VIA_SUPPLIER],
        'expense_category_id'        => ['expense_categories', self::VIA_SUPPLIER],
        'branding_profile_id'        => ['branding_profiles',  self::VIA_SUPPLIER],
        'category_id'                => ['trip_categories',    self::VIA_SUPPLIER],
        'car_id'                     => ['cars',               self::VIA_SUPPLIER],
        'source_purchase_invoice_id' => ['purchase_invoices',  self::VIA_SUPPLIER],
        'project_id'                 => ['projects',           self::VIA_CLIENT],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Vrátí sloupce z $columns, jejichž hodnota v $body ukazuje mimo tenanta.
     *
     * Prázdné pole = vše v pořádku. Chybějící / prázdná / nečíselná / nekladná
     * hodnota se přeskakuje (to řeší validace, ne tenant guard). Jeden SELECT
     * na cílovou tabulku — `currency_id` + `payment_currency_id` se ptají jednou.
     *
     * @param array<string,mixed> $body    tělo requestu
     * @param list<string>        $columns sloupce, které Action zapisuje do DB
     * @return list<string>                podmnožina $columns, v původním pořadí
     */
    public function violations(int $supplierId, array $body, array $columns): array
    {
        /** @var array<string, array<string, array<int, list<string>>>> $byScope */
        $byScope = [];
        $present = [];
        $bad     = [];

        foreach ($columns as $column) {
            if (!isset(self::SCOPES[$column])) {
                throw new \InvalidArgumentException(
                    "TenantReferenceGuard: neznámý sloupec '{$column}' — doplň ho do SCOPES."
                );
            }
            $raw = $body[$column] ?? null;
            // Zlomkové ID odmítáme rovnou: PHP `(int)` ořezává (5.7 → 5), ale MySQL
            // při zápisu do INT sloupce ZAOKROUHLUJE (5.7 → 6). Guard by tedy ověřil
            // vlastnictví jiného řádku, než jaký se reálně uloží — o jedno ID vedle,
            // a to je přesně cizí záznam. Zápisové cesty dnes castují `(int)` samy,
            // takže rozpor nikde nevzniká; nespoléháme ale na to, že to tak zůstane.
            if (self::isFractional($raw)) {
                $present[] = $column;
                $bad[]     = $column;
                continue;
            }
            $id = self::idOrNull($raw);
            if ($id === null) {
                continue;
            }
            $present[] = $column;
            [$table, $via] = self::SCOPES[$column];
            $byScope[$via][$table][$id][] = $column;
        }

        if ($present === []) {
            return [];
        }
        // Bez supplier kontextu nemůže nic patřit volajícímu → fail-closed.
        if ($supplierId <= 0) {
            return self::inOrder($columns, $present);
        }

        foreach ($byScope as $via => $tables) {
            foreach ($tables as $table => $idsToColumns) {
                $ids   = array_map('intval', array_keys($idsToColumns));
                $owned = $via === self::VIA_CLIENT
                    ? $this->ownedViaClient($table, $ids, $supplierId)
                    : $this->ownedDirect($table, $ids, $supplierId);

                foreach ($idsToColumns as $id => $cols) {
                    if (in_array((int) $id, $owned, true)) {
                        continue;
                    }
                    foreach ($cols as $col) {
                        $bad[] = $col;
                    }
                }
            }
        }

        return self::inOrder($columns, $bad);
    }

    /** Chybová hláška pro Json::error(..., 'invalid_reference', ...). */
    public static function message(array $violations): string
    {
        return 'Neplatná vazba na záznam mimo vaši firmu: ' . implode(', ', $violations) . '.';
    }

    /**
     * Tabulka s vlastním `supplier_id`.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function ownedDirect(string $table, array $ids, int $supplierId): array
    {
        // $table pochází výhradně z konstanty SCOPES, nikdy z requestu.
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->pdo()->prepare(
            "SELECT id FROM `{$table}` WHERE id IN ({$place}) AND supplier_id = ?"
        );
        $stmt->execute([...$ids, $supplierId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Tabulka bez `supplier_id` — vlastnictví se odvozuje z `clients.supplier_id`
     * (dnes jen `projects`, FK `fk_proj_client`).
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function ownedViaClient(string $table, array $ids, int $supplierId): array
    {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->pdo()->prepare(
            "SELECT t.id FROM `{$table}` t
               JOIN clients c ON c.id = t.client_id
              WHERE t.id IN ({$place}) AND c.supplier_id = ?"
        );
        $stmt->execute([...$ids, $supplierId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Číselná hodnota s desetinnou částí (`5.7`, `"5.7"`) — viz `violations()`.
     *
     * `0`, `''`, `null` ani nečíselné hodnoty sem nespadají: znamenají „nevyplněno"
     * (u nepovinných vazeb jako `project_id` je `0` legitimní „bez projektu")
     * a řeší je validace. Zaokrouhlením se z nich cizí ID stát nemůže.
     */
    private static function isFractional(mixed $value): bool
    {
        if (is_bool($value) || is_array($value) || $value === null || $value === '') {
            return false;
        }
        if (!is_numeric($value)) {
            return false;
        }

        return (float) $value !== floor((float) $value);
    }

    /** Kladné celé číslo, nebo null (prázdné / nečíselné / ≤ 0 se neověřuje). */
    private static function idOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_array($value) || is_bool($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * Deduplikace + zachování pořadí podle $columns (stabilní hláška i testy).
     *
     * @param list<string> $columns
     * @param list<string> $subset
     * @return list<string>
     */
    private static function inOrder(array $columns, array $subset): array
    {
        $wanted = array_flip($subset);
        $out    = [];
        foreach ($columns as $column) {
            if (isset($wanted[$column]) && !in_array($column, $out, true)) {
                $out[] = $column;
            }
        }

        return $out;
    }
}
