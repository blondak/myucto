<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Výchozí firma uživatele (`users.default_supplier_id`).
 *
 * Používá ji {@see SupplierAccessResolver}, když požadavek firmu nevybírá
 * (bez `X-Supplier-Id`: nový prohlížeč, jiné zařízení, API klient). Dřív tam
 * server otevíral firmu s nejnižším id, což u účetní s mnoha firmami bývá ta
 * nejméně používaná.
 *
 * Pravidla:
 *   - uložená výchozí firma, ke které má uživatel přístup, platí vždy; nic se
 *     nepřepočítává
 *   - bez uložené volby (NULL, nebo firma, ke které uživatel mezitím ztratil
 *     přístup) se JEDNOU zvolí přístupná firma s nejvíc doklady a uloží se;
 *     při shodě vyhrává nejnižší id, bez dokladů tedy platí dřívější chování
 *   - uživatel s jedinou firmou dostane tu firmu, taky uloženou
 *   - pozdější volba uživatele (přepínač firem → {@see self::store()}) má
 *     přednost; zápis výběru se podmiňuje stavem, který výběr viděl, takže
 *     souběžný požadavek ji nepřepíše
 *
 * „Doklady" = vydané faktury (`invoices`) + přijaté doklady (`purchase_invoices`)
 * ve všech stavech. Jsou to agendy, ve kterých se v aplikaci pracuje denně, a obě
 * tabulky mají index na `supplier_id`, takže počet je index-only scan. Stav se
 * záměrně nefiltruje: i koncept je stopa práce ve firmě a filtr by si vynutil
 * čtení řádků místo indexu. Pokladní a bankovní doklady se nepočítají: firma
 * bez fakturace je vzácná, a banka navíc má legacy výpisy bez `supplier_id`,
 * které by šly přiřadit jen přes číslo účtu (viz PortfolioVolumeCounter).
 *
 * Firmy nemají příznak neaktivní/archivovaná (tabulka `supplier` žádný nenese),
 * vybírá se proto ze všech přístupných. Pokud takový příznak přibude, patří
 * jeho filtr do {@see self::mostActive()}.
 */
final class DefaultSupplierService
{
    public const COLUMN = 'default_supplier_id';

    public function __construct(private readonly Connection $db) {}

    /**
     * Výchozí firma uživatele mezi přístupnými firmami; bez uložené volby ji
     * zvolí a uloží. 0 = uživatel nemá žádnou přístupnou firmu.
     *
     * @param list<int>|null $membershipIds přístupné firmy; null = všechny (superadmin).
     *        U superadmina se seznam firem načte, jen když se volí — uložená
     *        volba vždy existuje díky cizímu klíči s ON DELETE SET NULL.
     */
    public function resolve(int $userId, ?array $membershipIds): int
    {
        $accessibleIds = $membershipIds !== null ? self::normalize($membershipIds) : null;
        if ($accessibleIds === []) {
            return 0;
        }
        // Před migrací sloupce (aktualizace kódu dřív než DB) platí dřívější chování.
        if ($userId <= 0 || !$this->db->hasColumn('users', self::COLUMN)) {
            return $this->lowest($accessibleIds);
        }

        $stmt = $this->db->pdo()->prepare('SELECT default_supplier_id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $stored = $stmt->fetchColumn();
        if ($stored === false) {
            return $this->lowest($accessibleIds);
        }
        $stored = $stored !== null ? (int) $stored : null;
        if ($stored !== null && ($accessibleIds === null || in_array($stored, $accessibleIds, true))) {
            return $stored;
        }

        $accessibleIds ??= $this->allSupplierIds();
        if ($accessibleIds === []) {
            return 0;
        }
        $chosen = count($accessibleIds) === 1 ? $accessibleIds[0] : $this->mostActive($accessibleIds);

        // Podmínka na původní hodnotu: souběžné první požadavky (dashboard
        // pouští několik volání naráz) zapíšou nejvýš jednou a ruční volba
        // uložená mezitím zůstane.
        $update = $this->db->pdo()->prepare(
            'UPDATE users SET default_supplier_id = ? WHERE id = ? AND default_supplier_id <=> ?'
        );
        $update->execute([$chosen, $userId, $stored]);

        return $chosen;
    }

    /**
     * Uloží výchozí firmu zvolenou uživatelem. Volající ručí za přístup
     * (viz {@see self::isAccessible()}).
     */
    public function store(int $userId, int $supplierId): void
    {
        if ($userId <= 0 || $supplierId <= 0 || !$this->db->hasColumn('users', self::COLUMN)) {
            return;
        }
        $this->db->pdo()
            ->prepare('UPDATE users SET default_supplier_id = ? WHERE id = ?')
            ->execute([$supplierId, $userId]);
    }

    /**
     * Smí uživatel mít tuto firmu jako výchozí? Superadmin kteroukoli
     * existující, ostatní jen firmu ze svého membershipu.
     *
     * @param list<int> $membershipIds
     */
    public function isAccessible(int $supplierId, bool $isSuperadmin, array $membershipIds): bool
    {
        if ($supplierId <= 0) {
            return false;
        }
        if (!$isSuperadmin) {
            return in_array($supplierId, self::normalize($membershipIds), true);
        }
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<int> */
    private function allSupplierIds(): array
    {
        $ids = $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        return self::normalize(array_map('intval', $ids));
    }

    /** @param list<int>|null $accessibleIds */
    private function lowest(?array $accessibleIds): int
    {
        $accessibleIds ??= $this->allSupplierIds();
        return $accessibleIds[0] ?? 0;
    }

    /**
     * Přístupná firma s nejvyšším počtem dokladů; při shodě nejnižší id.
     *
     * Jeden dotaz: každá větev UNION ALL agreguje přes index `supplier_id`
     * jen v přístupných firmách, vnější GROUP BY je sečte. Firmy bez dokladů
     * ve výsledku chybí, a proto je fallbackem nejnižší přístupné id.
     *
     * @param list<int> $accessibleIds seřazené, neprázdné
     */
    private function mostActive(array $accessibleIds): int
    {
        $in = implode(',', array_fill(0, count($accessibleIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT supplier_id, SUM(cnt) AS documents
               FROM (
                     SELECT supplier_id, COUNT(*) AS cnt FROM invoices
                      WHERE supplier_id IN ({$in}) GROUP BY supplier_id
                     UNION ALL
                     SELECT supplier_id, COUNT(*) AS cnt FROM purchase_invoices
                      WHERE supplier_id IN ({$in}) GROUP BY supplier_id
                    ) d
           GROUP BY supplier_id
           ORDER BY documents DESC, supplier_id ASC
              LIMIT 1"
        );
        $stmt->execute([...$accessibleIds, ...$accessibleIds]);
        $best = $stmt->fetchColumn();

        return $best !== false ? (int) $best : $accessibleIds[0];
    }

    /**
     * @param array<int|string, int> $ids
     * @return list<int>
     */
    private static function normalize(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }
}
