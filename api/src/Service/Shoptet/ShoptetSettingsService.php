<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use PDO;

/**
 * Nastavení napojení Shoptetu pro jednu firmu.
 *
 * Dvě tajemství a každé jinak:
 *   - odkaz na export objednávek nese hash partnera Shoptetu, se kterým kdokoli stáhne
 *     osobní údaje zákazníků. MyÚčto ho ale samo potřebuje číst (cron), proto se ukládá
 *     ŠIFROVANĚ s kontextem firmy a ven jde jen maskovaná podoba;
 *   - token veřejného feedu naopak MyÚčto zpátky nepotřebuje, jen ho ověřuje. V DB je
 *     proto jen SHA-256 a plný token se ukáže jedinkrát při vygenerování.
 */
final class ShoptetSettingsService
{
    public const DOCUMENTS_MYUCTO = 'myucto';
    public const DOCUMENTS_SHOPTET = 'shoptet';

    private const INTERVALS = [15, 30, 60, 120, 240, 720, 1440];

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $encryption,
    ) {}

    /** @return array<string,mixed> */
    public function get(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM shoptet_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? self::defaults($supplierId) : $row;
    }

    /** Veřejná podoba nastavení — bez tajemství. @return array<string,mixed> */
    public function present(int $supplierId): array
    {
        $row = $this->get($supplierId);

        return [
            'documents_issuer' => (string) $row['documents_issuer'],
            'order_url_set' => ($row['order_url_enc'] ?? null) !== null,
            'order_url_hint' => $row['order_url_hint'] ?? null,
            'auto_fetch' => (bool) $row['auto_fetch'],
            'fetch_interval_minutes' => (int) $row['fetch_interval_minutes'],
            'fetch_cursor' => $row['fetch_cursor'] ?? null,
            'last_fetch_at' => $row['last_fetch_at'] ?? null,
            'last_fetch_status' => $row['last_fetch_status'] ?? null,
            'last_fetch_message' => $row['last_fetch_message'] ?? null,
            'default_warehouse_id' => isset($row['default_warehouse_id']) ? (int) $row['default_warehouse_id'] : null,
            'confirm_orders' => (bool) $row['confirm_orders'],
            'feed_enabled' => ($row['feed_token_hash'] ?? null) !== null,
            'feed_token_created_at' => $row['feed_token_created_at'] ?? null,
            'feed_warehouse_id' => isset($row['feed_warehouse_id']) ? (int) $row['feed_warehouse_id'] : null,
            'feed_include_price' => (bool) $row['feed_include_price'],
            'feed_scope' => (string) $row['feed_scope'],
            'feed_changed_at' => $row['feed_changed_at'] ?? null,
            'feed_last_served_at' => $row['feed_last_served_at'] ?? null,
            'intervals' => self::INTERVALS,
        ];
    }

    /**
     * Uloží běžná nastavení (bez tajemství). Neznámé klíče se ignorují.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(int $supplierId, array $input, ?int $userId): array
    {
        $row = $this->get($supplierId);
        $issuer = (string) ($input['documents_issuer'] ?? $row['documents_issuer']);
        if (!in_array($issuer, [self::DOCUMENTS_MYUCTO, self::DOCUMENTS_SHOPTET], true)) {
            throw new ShoptetImportException('shoptet_settings_invalid', 'Neznámá volba, kdo vystavuje doklady.');
        }
        $interval = (int) ($input['fetch_interval_minutes'] ?? $row['fetch_interval_minutes']);
        if (!in_array($interval, self::INTERVALS, true)) {
            throw new ShoptetImportException('shoptet_settings_invalid', 'Neplatný interval stahování.');
        }
        $scope = (string) ($input['feed_scope'] ?? $row['feed_scope']);
        if (!in_array($scope, ['eshop', 'active'], true)) {
            throw new ShoptetImportException('shoptet_settings_invalid', 'Neplatný výběr produktů pro feed.');
        }
        $autoFetch = array_key_exists('auto_fetch', $input) ? (bool) $input['auto_fetch'] : (bool) $row['auto_fetch'];
        if ($autoFetch && ($row['order_url_enc'] ?? null) === null) {
            throw new ShoptetImportException(
                'shoptet_order_url_missing',
                'Automatické stahování potřebuje uložený odkaz na export objednávek.'
            );
        }
        $defaultWarehouse = $this->warehouseOrNull($supplierId, $input['default_warehouse_id'] ?? $row['default_warehouse_id']);
        $feedWarehouse = array_key_exists('feed_warehouse_id', $input)
            ? $this->warehouseOrNull($supplierId, $input['feed_warehouse_id'])
            : (isset($row['feed_warehouse_id']) ? (int) $row['feed_warehouse_id'] : null);

        $this->upsert($supplierId, [
            'documents_issuer' => $issuer,
            'auto_fetch' => $autoFetch ? 1 : 0,
            'fetch_interval_minutes' => $interval,
            'default_warehouse_id' => $defaultWarehouse,
            'confirm_orders' => (array_key_exists('confirm_orders', $input) ? (bool) $input['confirm_orders'] : (bool) $row['confirm_orders']) ? 1 : 0,
            'feed_warehouse_id' => $feedWarehouse,
            'feed_include_price' => (array_key_exists('feed_include_price', $input) ? (bool) $input['feed_include_price'] : (bool) $row['feed_include_price']) ? 1 : 0,
            'feed_scope' => $scope,
            'updated_by' => $userId,
        ]);

        return $this->present($supplierId);
    }

    /** Uloží odkaz na export objednávek. Změna odkazu maže kurzor stahování. */
    public function setOrderUrl(int $supplierId, string $url, ?int $userId): array
    {
        $url = ShoptetOrderUrl::normalize($url);
        $this->upsert($supplierId, [
            'order_url_enc' => $this->encryption->encryptFor($url, self::context($supplierId)),
            'order_url_hint' => ShoptetOrderUrl::mask($url),
            'fetch_cursor' => null,
            'last_fetch_status' => null,
            'last_fetch_message' => null,
            'updated_by' => $userId,
        ]);

        return $this->present($supplierId);
    }

    public function clearOrderUrl(int $supplierId, ?int $userId): array
    {
        $this->upsert($supplierId, [
            'order_url_enc' => null,
            'order_url_hint' => null,
            'auto_fetch' => 0,
            'fetch_cursor' => null,
            'updated_by' => $userId,
        ]);

        return $this->present($supplierId);
    }

    public function orderUrl(int $supplierId): ?string
    {
        $enc = $this->get($supplierId)['order_url_enc'] ?? null;
        if ($enc === null || $enc === '') {
            return null;
        }

        return $this->encryption->decryptFor((string) $enc, self::context($supplierId));
    }

    /**
     * Vygeneruje nový token feedu (starý tím přestane platit) a vrátí ho — jediná
     * chvíle, kdy plný token opustí server.
     */
    public function rotateFeedToken(int $supplierId, ?int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->upsert($supplierId, [
            'feed_token_hash' => hash('sha256', $token),
            'feed_token_created_at' => date('Y-m-d H:i:s'),
            'feed_etag' => null,
            'feed_changed_at' => null,
            'updated_by' => $userId,
        ]);

        return $token;
    }

    public function disableFeed(int $supplierId, ?int $userId): void
    {
        $this->upsert($supplierId, [
            'feed_token_hash' => null,
            'feed_token_created_at' => null,
            'feed_etag' => null,
            'updated_by' => $userId,
        ]);
    }

    /** Nastavení podle tokenu feedu — jediný dotaz bez predikátu firmy; firmu nese nalezený řádek. */
    public function findByFeedToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM shoptet_settings WHERE feed_token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !hash_equals((string) $row['feed_token_hash'], hash('sha256', $token))) {
            return null;
        }

        return $row;
    }

    public function rememberFeedVersion(int $supplierId, string $etag): string
    {
        $row = $this->get($supplierId);
        $now = date('Y-m-d H:i:s');
        if (($row['feed_etag'] ?? null) !== $etag || ($row['feed_changed_at'] ?? null) === null) {
            $this->upsert($supplierId, ['feed_etag' => $etag, 'feed_changed_at' => $now, 'feed_last_served_at' => $now]);

            return $now;
        }
        $this->upsert($supplierId, ['feed_last_served_at' => $now]);

        return (string) $row['feed_changed_at'];
    }

    /** @param array<string,mixed> $values */
    public function recordFetch(int $supplierId, array $values): void
    {
        $allowed = array_intersect_key($values, array_flip([
            'fetch_cursor', 'last_fetch_at', 'last_full_fetch_at', 'last_fetch_status', 'last_fetch_message',
        ]));
        if (isset($allowed['last_fetch_message'])) {
            $allowed['last_fetch_message'] = mb_substr((string) $allowed['last_fetch_message'], 0, 255);
        }
        $this->upsert($supplierId, $allowed);
    }

    /** @return list<int> firmy, kterým je čas stáhnout objednávky */
    public function dueForFetch(\DateTimeImmutable $now): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.supplier_id FROM shoptet_settings s
               JOIN supplier su ON su.id = s.supplier_id AND su.stock_enabled = 1
              WHERE s.auto_fetch = 1 AND s.order_url_enc IS NOT NULL
                AND (s.last_fetch_at IS NULL OR s.last_fetch_at <= DATE_SUB(?, INTERVAL s.fetch_interval_minutes MINUTE))
           ORDER BY s.last_fetch_at IS NOT NULL, s.last_fetch_at'
        );
        $stmt->execute([$now->format('Y-m-d H:i:s')]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function documentsIssuer(int $supplierId): string
    {
        return (string) $this->get($supplierId)['documents_issuer'];
    }

    private static function context(int $supplierId): string
    {
        return 'shoptet-order-url:' . $supplierId;
    }

    private function warehouseOrNull(int $supplierId, mixed $value): ?int
    {
        $id = (int) ($value ?? 0);
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id FROM warehouses WHERE supplier_id = ? AND id = ? AND is_active = 1');
        $stmt->execute([$supplierId, $id]);
        if ($stmt->fetchColumn() === false) {
            throw new ShoptetImportException('shoptet_warehouse_invalid', 'Sklad neexistuje nebo není aktivní.', 404);
        }

        return $id;
    }

    /** @param array<string,mixed> $values */
    private function upsert(int $supplierId, array $values): void
    {
        if ($values === []) {
            return;
        }
        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns) + 1, '?'));
        $updates = implode(', ', array_map(static fn (string $c): string => "`{$c}` = VALUES(`{$c}`)", $columns));
        $this->db->pdo()->prepare(
            'INSERT INTO shoptet_settings (supplier_id, ' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)) . ')
             VALUES (' . $placeholders . ') ON DUPLICATE KEY UPDATE ' . $updates
        )->execute([$supplierId, ...array_values($values)]);
    }

    /** @return array<string,mixed> */
    private static function defaults(int $supplierId): array
    {
        return [
            'supplier_id' => $supplierId,
            'documents_issuer' => self::DOCUMENTS_MYUCTO,
            'order_url_enc' => null,
            'order_url_hint' => null,
            'auto_fetch' => 0,
            'fetch_interval_minutes' => 60,
            'fetch_cursor' => null,
            'last_fetch_at' => null,
            'last_full_fetch_at' => null,
            'last_fetch_status' => null,
            'last_fetch_message' => null,
            'default_warehouse_id' => null,
            'confirm_orders' => 0,
            'feed_token_hash' => null,
            'feed_token_created_at' => null,
            'feed_warehouse_id' => null,
            'feed_include_price' => 1,
            'feed_scope' => 'eshop',
            'feed_etag' => null,
            'feed_changed_at' => null,
            'feed_last_served_at' => null,
        ];
    }
}
