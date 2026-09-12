<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use PDO;

final class IntegrationConnectionService
{
    private const STATUSES = ['draft', 'active', 'paused', 'error'];
    private const FREE_FIELD_PATTERN = '/^[a-z][a-z0-9_.]{0,99}$/D';
    private const MAX_REMOTE_VALUE = 190;
    private const MAX_CREDENTIAL_VALUE = 4000;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $encryption,
        private readonly ConnectorDefinitions $definitions,
        private readonly IntegrationLocalLookups $lookups,
        private readonly IntegrationConnectionDefaults $defaults,
    ) {}

    /**
     * Ukázkové napojení pro firmu, která zatím nic nenastavila: vlastní webhook
     * s výchozím nastavením, vždy jako Koncept a bez přístupových údajů.
     * Vzniká jen na výslovnou žádost uživatele, nikdy při otevření stránky.
     */
    public function createSample(int $supplierId, ?int $createdBy): array
    {
        $definition = $this->definitions->find(ConnectorDefinitions::CUSTOM_WEBHOOK)
            ?? throw new \LogicException('Chybí definice vlastního napojení.');
        $stmt = $this->db->pdo()->prepare('SELECT name FROM integration_connections WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $taken = array_map('mb_strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $name = IntegrationConnectionDefaults::SAMPLE_NAME;
        for ($i = 2; in_array(mb_strtolower($name), $taken, true); $i++) {
            $name = IntegrationConnectionDefaults::SAMPLE_NAME . ' ' . $i;
        }
        return $this->create($supplierId, ['connector_key' => $definition['key'], 'name' => $name]
            + $this->defaults->for($definition, $supplierId), $createdBy);
    }

    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM integration_connections WHERE supplier_id = ? ORDER BY name, id');
        $stmt->execute([$supplierId]);
        return array_map($this->present(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM integration_connections WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->present($row);
    }

    public function findRawByUuid(string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM integration_connections WHERE connection_uuid = ?');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(int $supplierId, array $input, ?int $createdBy): array
    {
        $values = $this->normalize($supplierId, $input, null);
        if ($values['status'] === 'error') {
            throw new IntegrationValidationException('Nové připojení nemůže začít ve stavu Chyba.', 'status');
        }
        $uuid = self::uuid();
        $stmt = $this->db->pdo()->prepare('INSERT INTO integration_connections
            (connection_uuid, supplier_id, connector_key, name, status, mappings_json,
             field_ownership_json, rate_limit_per_minute, retention_days, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$uuid, $supplierId, $values['connector_key'], $values['name'], $values['status'],
            $values['mappings_json'], $values['field_ownership_json'],
            $values['rate_limit_per_minute'], $values['retention_days'], $createdBy]);
        return $this->find($supplierId, (int) $this->db->pdo()->lastInsertId())
            ?? throw new \RuntimeException('Připojení se nepodařilo vytvořit.');
    }

    public function update(int $supplierId, int $id, array $input): ?array
    {
        $current = $this->find($supplierId, $id);
        if ($current === null) {
            return null;
        }
        $values = $this->normalize($supplierId, array_replace($current, $input), (string) $current['connector_key']);
        $stmt = $this->db->pdo()->prepare('UPDATE integration_connections SET connector_key = ?, name = ?,
            status = ?, mappings_json = ?, field_ownership_json = ?, rate_limit_per_minute = ?, retention_days = ?
            WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$values['connector_key'], $values['name'], $values['status'],
            $values['mappings_json'], $values['field_ownership_json'],
            $values['rate_limit_per_minute'], $values['retention_days'], $supplierId, $id]);
        return $this->find($supplierId, $id);
    }

    /**
     * Přístupové údaje známého konektoru se ukládají po polích: prázdná hodnota
     * ponechá uloženou, `$clear` odebere nepovinná pole. Připojení s konektorem,
     * který definice nezná, zachovává původní chování (celý objekt se nahradí).
     *
     * @param list<mixed> $clear
     */
    /** Smaže připojení i s jeho frontami a propojenými identitami (FK ON DELETE CASCADE). */
    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM integration_connections WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    public function setCredentials(int $supplierId, int $id, array $credentials, array $clear = []): ?array
    {
        $row = $this->raw($supplierId, $id);
        if ($row === null) {
            return null;
        }
        $definition = $this->definitions->find((string) $row['connector_key']);
        $clean = $definition === null
            ? $this->legacyCredentials($credentials)
            : $this->definedCredentials($definition, $row, $credentials, $clear);
        $encrypted = $clean === [] ? null : $this->encryption->encryptFor(
            json_encode($clean, JSON_THROW_ON_ERROR),
            $this->context($supplierId, (string) $row['connection_uuid']),
        );
        $this->db->pdo()->prepare('UPDATE integration_connections SET credentials_enc = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$encrypted, $supplierId, $id]);
        return $this->find($supplierId, $id);
    }

    public function credentials(int $supplierId, int $id): ?array
    {
        $row = $this->raw($supplierId, $id);
        if ($row === null || $row['credentials_enc'] === null) {
            return null;
        }
        return $this->decryptCredentials($row);
    }

    public function rotateWebhookSecret(int $supplierId, int $id): ?array
    {
        $row = $this->raw($supplierId, $id);
        if ($row === null) {
            return null;
        }
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $encrypted = $this->encryption->encryptFor($secret, $this->context($supplierId, (string) $row['connection_uuid']) . ':webhook');
        $this->db->pdo()->prepare('UPDATE integration_connections SET webhook_secret_enc = ?, webhook_secret_hash = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$encrypted, hash('sha256', $secret), $supplierId, $id]);
        return ['connection_uuid' => $row['connection_uuid'], 'secret' => $secret];
    }

    public function webhookSecret(array $row): ?string
    {
        if (($row['webhook_secret_enc'] ?? null) === null) {
            return null;
        }
        return $this->encryption->decryptFor((string) $row['webhook_secret_enc'],
            $this->context((int) $row['supplier_id'], (string) $row['connection_uuid']) . ':webhook');
    }

    private function raw(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM integration_connections WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array{connector_key:string,name:string,status:string,mappings_json:string,
     *     field_ownership_json:string,rate_limit_per_minute:int,retention_days:int}
     */
    private function normalize(int $supplierId, array $input, ?string $currentConnector): array
    {
        $connector = trim((string) ($input['connector_key'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $status = (string) ($input['status'] ?? 'draft');
        if ($name === '') {
            throw new IntegrationValidationException('Vyplňte název připojení.', 'name');
        }
        if (mb_strlen($name) > 150) {
            throw new IntegrationValidationException('Název připojení může mít nejvýše 150 znaků.', 'name');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new IntegrationValidationException('Neplatný stav připojení.', 'status');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{1,79}$/D', $connector) !== 1) {
            throw new IntegrationValidationException('Vyberte konektor ze seznamu.', 'connector_key');
        }
        $definition = $this->definitions->find($connector);
        if ($definition === null && $connector !== $currentConnector) {
            throw new IntegrationValidationException(
                sprintf('Konektor „%s“ neexistuje. Vyberte konektor ze seznamu.', $connector), 'connector_key');
        }
        if ($definition !== null && !$definition['available'] && $connector !== $currentConnector) {
            throw new IntegrationValidationException(
                sprintf('Konektor „%s“ zatím není k dispozici.', $definition['name']), 'connector_key');
        }

        if ($definition === null) {
            // Připojení vzniklé před zavedením definic: zůstává upravitelné
            // a jeho mapování i vlastnictví se kontroluje jen tvarem jako dřív.
            [$mappings, $ownership] = $this->legacyMappingAndOwnership($input);
        } else {
            $mappings = $this->definedMappings($definition, $supplierId, $input['mappings'] ?? []);
            $ownership = $this->definedOwnership($definition, $input['field_ownership'] ?? []);
        }

        $rate = (int) ($input['rate_limit_per_minute'] ?? 60);
        $retention = (int) ($input['retention_days'] ?? 30);
        if ($rate < 1 || $rate > 6000) {
            throw new IntegrationValidationException('Limit požadavků musí být 1 až 6000 za minutu.', 'rate_limit_per_minute');
        }
        if ($retention < 1 || $retention > 365) {
            throw new IntegrationValidationException('Doba uchování záznamů musí být 1 až 365 dní.', 'retention_days');
        }
        return ['connector_key' => $connector, 'name' => $name, 'status' => $status,
            'mappings_json' => $mappings, 'field_ownership_json' => $ownership,
            'rate_limit_per_minute' => $rate, 'retention_days' => $retention];
    }

    /** @return array{0:string,1:string} */
    private function legacyMappingAndOwnership(array $input): array
    {
        $mappings = $this->objectMap($input['mappings'] ?? []);
        if ($mappings === null) {
            throw new IntegrationValidationException('Mapování musí být JSON objekt.', 'mappings');
        }
        $ownership = $this->objectMap($input['field_ownership'] ?? []);
        if ($ownership === null) {
            throw new IntegrationValidationException('Vlastnictví polí musí být JSON objekt.', 'field_ownership');
        }
        foreach ($ownership as $field => $owner) {
            if (!is_string($field) || preg_match(self::FREE_FIELD_PATTERN, $field) !== 1
                || !is_string($owner) || !in_array($owner, ConnectorDefinitions::OWNERS, true)) {
                throw new IntegrationValidationException(
                    sprintf('Pole „%s“ má neplatné vlastnictví. Povolené hodnoty jsou local, remote a manual.', (string) $field),
                    'field_ownership');
            }
        }
        return [json_encode((object) $mappings, JSON_THROW_ON_ERROR), json_encode((object) $ownership, JSON_THROW_ON_ERROR)];
    }

    /**
     * Uložený tvar: `{"warehouses": {"HLAVNI": "main-store"}, "currencies": {"CZK": "CZK"}}`,
     * tedy typ → místní hodnota → hodnota v externím systému.
     */
    private function definedMappings(array $definition, int $supplierId, mixed $raw): string
    {
        $map = $this->objectMap($raw);
        if ($map === null) {
            throw new IntegrationValidationException('Mapování musí být objekt rozdělený podle typů (sklady, měny, jazyky, sazby DPH).', 'mappings');
        }
        $allowed = array_column($definition['mappings'], null, 'type');
        $out = [];
        foreach ($map as $type => $pairs) {
            $type = (string) $type;
            if (!isset($allowed[$type])) {
                throw new IntegrationValidationException(
                    sprintf('Konektor „%s“ neumí mapovat „%s“.', $definition['name'], $type), 'mappings.' . $type);
            }
            $meta = ConnectorDefinitions::MAPPING_TYPES[$type];
            if (!is_array($pairs) && !$pairs instanceof \stdClass) {
                throw new IntegrationValidationException(
                    sprintf('Mapování „%s“ musí být objekt místní hodnota → hodnota v externím systému.', $meta['label']),
                    'mappings.' . $type);
            }
            $known = $this->lookups->values($supplierId, $meta['source']);
            $clean = [];
            foreach ($pairs as $local => $remote) {
                $local = (string) $local;
                if (!in_array($local, $known, true)) {
                    throw new IntegrationValidationException(
                        sprintf('Mapování %s: %s „%s“ ve firmě neexistuje.', $meta['label'], $meta['item'], $local),
                        'mappings.' . $type);
                }
                $remote = is_scalar($remote) ? trim((string) $remote) : '';
                if ($remote === '') {
                    throw new IntegrationValidationException(
                        sprintf('Mapování %s: vyplňte hodnotu v externím systému pro „%s“.', $meta['label'], $local),
                        'mappings.' . $type);
                }
                if (mb_strlen($remote) > self::MAX_REMOTE_VALUE) {
                    throw new IntegrationValidationException(
                        sprintf('Mapování %s: hodnota pro „%s“ může mít nejvýše %d znaků.', $meta['label'], $local, self::MAX_REMOTE_VALUE),
                        'mappings.' . $type);
                }
                $clean[$local] = $remote;
            }
            if ($clean !== []) {
                $out[$type] = (object) $clean;
            }
        }
        return json_encode((object) $out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function definedOwnership(array $definition, mixed $raw): string
    {
        $ownership = $this->objectMap($raw);
        if ($ownership === null) {
            throw new IntegrationValidationException('Vlastnictví polí musí být objekt pole → vlastník.', 'field_ownership');
        }
        $known = array_column($definition['fields'], null, 'key');
        foreach ($ownership as $field => $owner) {
            $field = (string) $field;
            $label = $known[$field]['label'] ?? $field;
            if (!isset($known[$field])) {
                if (!$definition['free_fields'] || preg_match(self::FREE_FIELD_PATTERN, $field) !== 1) {
                    throw new IntegrationValidationException(
                        sprintf('Konektor „%s“ nezná pole „%s“.', $definition['name'], $field), 'field_ownership.' . $field);
                }
            }
            if (!is_string($owner) || !in_array($owner, ConnectorDefinitions::OWNERS, true)) {
                throw new IntegrationValidationException(
                    sprintf('Pole „%s“ má neplatného vlastníka. Vyberte Místní, Externí nebo Ruční.', $label),
                    'field_ownership.' . $field);
            }
        }
        return json_encode((object) $ownership, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,string> */
    private function legacyCredentials(array $credentials): array
    {
        $clean = [];
        foreach ($credentials as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $key) !== 1 || !is_scalar($value)) {
                throw new IntegrationValidationException('Přístupové údaje musí být objekt klíč → text.', 'credentials');
            }
            $clean[$key] = (string) $value;
        }
        if ($clean === []) {
            throw new IntegrationValidationException('Přístupové údaje nesmí být prázdné.', 'credentials');
        }
        return $clean;
    }

    /**
     * @param list<mixed> $clear
     * @return array<string,string>
     */
    private function definedCredentials(array $definition, array $row, array $credentials, array $clear): array
    {
        $fields = array_column($definition['credentials'], null, 'key');
        $stored = $row['credentials_enc'] === null ? [] : $this->decryptCredentials($row);
        $changed = false;
        foreach ($credentials as $key => $value) {
            $key = (string) $key;
            if (!isset($fields[$key])) {
                throw new IntegrationValidationException(
                    sprintf('Konektor „%s“ nezná přístupový údaj „%s“.', $definition['name'], $key), 'credentials.' . $key);
            }
            $label = $fields[$key]['label'];
            if (!is_scalar($value)) {
                throw new IntegrationValidationException(sprintf('Přístupový údaj „%s“ musí být text.', $label), 'credentials.' . $key);
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > self::MAX_CREDENTIAL_VALUE) {
                throw new IntegrationValidationException(
                    sprintf('Přístupový údaj „%s“ může mít nejvýše %d znaků.', $label, self::MAX_CREDENTIAL_VALUE), 'credentials.' . $key);
            }
            if ($fields[$key]['type'] === 'url' && !self::isHttpsUrl($value)) {
                throw new IntegrationValidationException(
                    sprintf('Přístupový údaj „%s“ musí být úplná adresa začínající https://.', $label), 'credentials.' . $key);
            }
            $stored[$key] = $value;
            $changed = true;
        }
        foreach ($clear as $key) {
            $key = is_scalar($key) ? (string) $key : '';
            if (!isset($fields[$key])) {
                throw new IntegrationValidationException(
                    sprintf('Konektor „%s“ nezná přístupový údaj „%s“.', $definition['name'], $key), 'credentials.' . $key);
            }
            if ($fields[$key]['required']) {
                throw new IntegrationValidationException(
                    sprintf('Povinný přístupový údaj „%s“ nelze odebrat, jen přepsat.', $fields[$key]['label']), 'credentials.' . $key);
            }
            unset($stored[$key]);
            $changed = true;
        }
        if (!$changed) {
            throw new IntegrationValidationException('Vyplňte alespoň jeden přístupový údaj.', 'credentials');
        }
        foreach ($fields as $key => $field) {
            if ($field['required'] && !isset($stored[$key])) {
                throw new IntegrationValidationException(
                    sprintf('Vyplňte povinný přístupový údaj „%s“.', $field['label']), 'credentials.' . $key);
            }
        }
        // Uložené hodnoty mimo definici (např. z doby před jejím zavedením) se zahodí.
        return array_intersect_key($stored, $fields);
    }

    /** @return array<string,string> */
    private function decryptCredentials(array $row): array
    {
        $decoded = json_decode($this->encryption->decryptFor(
            (string) $row['credentials_enc'],
            $this->context((int) $row['supplier_id'], (string) $row['connection_uuid']),
        ), true, 64, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function present(array $row): array
    {
        foreach (['id', 'supplier_id', 'rate_limit_per_minute', 'retention_days'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        // Mapování zůstává objektem na všech úrovních: kód skladu „0" by se po
        // dekódování do pole změnil v seznam a editor by ho neuměl přečíst.
        $mappings = json_decode($row['mappings_json'], false, 64, JSON_THROW_ON_ERROR);
        $row['mappings'] = $mappings instanceof \stdClass ? $mappings : new \stdClass();
        $row['field_ownership'] = (object) json_decode($row['field_ownership_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['credentials_configured'] = $row['credentials_enc'] !== null;
        // Jen NÁZVY uložených polí, aby editor ukázal „uloženo". Hodnoty nikdy.
        $row['credentials_fields'] = $this->storedCredentialKeys($row);
        $row['webhook_configured'] = $row['webhook_secret_enc'] !== null;
        unset($row['mappings_json'], $row['field_ownership_json'], $row['credentials_enc'],
            $row['webhook_secret_enc'], $row['webhook_secret_hash']);
        return $row;
    }

    /** @return list<string> */
    private function storedCredentialKeys(array $row): array
    {
        if ($row['credentials_enc'] === null) {
            return [];
        }
        try {
            return array_values(array_map('strval', array_keys($this->decryptCredentials($row))));
        } catch (\Throwable) {
            return [];
        }
    }

    private function objectMap(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }
        return $value;
    }

    private static function isHttpsUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($value, PHP_URL_HOST) !== '';
    }

    private function context(int $supplierId, string $uuid): string
    {
        return 'integration-connection:' . $supplierId . ':' . $uuid;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
