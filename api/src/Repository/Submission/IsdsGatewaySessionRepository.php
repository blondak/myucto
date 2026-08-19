<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Relace odesílací brány ISDS (migrace 1412).
 *
 * ── Proč jsou přechody podmíněné UPDATE, a ne čtení + zápis ─────────────────
 * Uživatel se z ISDS vrací přesměrováním v prohlížeči. Dvojklik, obnovení
 * stránky nebo návrat ze dvou zařízení znamenají dvě souběžná volání téhož
 * callbacku. Kdyby se stav nejdřív četl a pak zapisoval, obě by prošla a
 * z jednoho schválení by vznikla dvě odeslání. Každý přechod je proto jeden
 * `UPDATE … WHERE state = <očekávaný>` a `rowCount() !== 1` znamená
 * „někdo byl rychlejší" — což je legitimní odpověď, ne chyba.
 */
final class IsdsGatewaySessionRepository
{
    private const TABLE = 'isds_gateway_sessions';

    private const COLUMNS = 'id, supplier_id, environment, outbox_id, user_id, app_token, state,
        concept_id, concept_dm_id, concept_status_code, concept_status_message,
        payload_sha256, correlation_reference, error_code, error_message,
        expires_at, started_at, concept_pushed_at, finished_at, row_version, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /**
     * Založí relaci ve stavu `awaiting_login`.
     *
     * Kolize na `uq_isds_gateway_sessions_active` znamená, že pro tohle podání
     * už jedna živá relace je — vrací se `null` a volající ji dohledá. Je to
     * ochrana proti dvojímu kliknutí na úrovni databáze, ne jen aplikace.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function open(array $data): ?array
    {
        $this->assertAvailable();
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (supplier_id, environment, outbox_id, user_id, app_token,
                     payload_sha256, correlation_reference, expires_at, started_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                $data['supplier_id'],
                $data['environment'],
                $data['outbox_id'],
                $data['user_id'],
                $data['app_token'],
                $data['payload_sha256'],
                $data['correlation_reference'],
                $data['expires_at'],
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return null;
        }

        return $this->find((int) $data['supplier_id'], (int) $this->db->pdo()->lastInsertId());
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        return $this->fetchOne('supplier_id = ? AND id = ?', [$supplierId, $id]);
    }

    /**
     * Vyhledá relaci podle `appToken`, který přišel z přesměrování.
     *
     * ⚠️ **Tohle NENÍ autorizace.** Token přišel z prohlížeče a sám o sobě
     * neopravňuje k ničemu. Volající MUSÍ ověřit, že `supplier_id` i `user_id`
     * relace odpovídají přihlášené relaci — viz
     * {@see \MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayDispatchService}.
     * Dotaz proto vědomě NENÍ omezený na tenanta: kdyby byl, cizí token by
     * vypadal jako neexistující a nešlo by ho odlišit od skutečného pokusu
     * o záměnu, který patří do auditní stopy.
     *
     * @return array<string,mixed>|null
     */
    public function findByAppToken(string $appToken): ?array
    {
        return $this->fetchOne('app_token = ?', [$appToken]);
    }

    /** Živá relace pro dané podání, pokud nějaká je. @return array<string,mixed>|null */
    public function findActiveForOutbox(int $supplierId, int $outboxId): ?array
    {
        return $this->fetchOne(
            'supplier_id = ? AND outbox_id = ? AND state IN (\'awaiting_login\',\'awaiting_approval\')',
            [$supplierId, $outboxId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function listForOutbox(int $supplierId, int $outboxId): array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND outbox_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$supplierId, $outboxId]);

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * `awaiting_login` → `awaiting_approval`. Zapíše dmID vloženého konceptu.
     *
     * @return array<string,mixed>|null null, když relace už `awaiting_login` není
     */
    public function markConceptPushed(int $supplierId, int $id, string $conceptId): ?array
    {
        return $this->transition(
            $supplierId,
            $id,
            'awaiting_login',
            'state = \'awaiting_approval\', concept_id = ?, concept_pushed_at = UTC_TIMESTAMP()',
            [$conceptId],
        );
    }

    /**
     * `awaiting_approval` → `approved`. Zapíše skutečné dmID odeslané zprávy.
     *
     * @return array<string,mixed>|null
     */
    public function markApproved(
        int $supplierId,
        int $id,
        string $messageId,
        string $statusCode,
        ?string $statusMessage,
    ): ?array {
        return $this->transition(
            $supplierId,
            $id,
            'awaiting_approval',
            'state = \'approved\', concept_dm_id = ?, concept_status_code = ?, concept_status_message = ?,
             finished_at = UTC_TIMESTAMP()',
            [$messageId, $statusCode, $statusMessage !== null ? mb_substr($statusMessage, 0, 500) : null],
        );
    }

    /**
     * Ukončení bez odeslání. `$state` je `rejected`, `failed`, `uncertain`
     * nebo `expired` — rozdíl mezi nimi je zásadní a nesmí se slít:
     * `rejected`/`failed` znamenají „nic neodešlo", `uncertain` znamená „nevíme".
     *
     * @return array<string,mixed>|null
     */
    public function close(
        int $supplierId,
        int $id,
        string $fromState,
        string $state,
        string $errorCode,
        string $errorMessage,
        ?string $statusCode = null,
        ?string $statusMessage = null,
    ): ?array {
        return $this->transition(
            $supplierId,
            $id,
            $fromState,
            'state = ?, error_code = ?, error_message = ?, concept_status_code = COALESCE(?, concept_status_code),
             concept_status_message = COALESCE(?, concept_status_message), finished_at = UTC_TIMESTAMP()',
            [
                $state,
                $errorCode,
                mb_substr($errorMessage, 0, 500),
                $statusCode,
                $statusMessage !== null ? mb_substr($statusMessage, 0, 500) : null,
            ],
        );
    }

    /**
     * Uzavře relace, ke kterým se uživatel nevrátil.
     *
     * Nemaže se nic: relace je auditní stopa a trigger mazání zakazuje.
     */
    public function expireStale(): int
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET state = \'expired\', error_code = \'isds_gateway_expired\',
                    error_message = \'Uživatel se z datové schránky nevrátil včas.\',
                    finished_at = UTC_TIMESTAMP(), row_version = row_version + 1
              WHERE state IN (\'awaiting_login\',\'awaiting_approval\') AND expires_at < UTC_TIMESTAMP()'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    // ───────────────────────── interní ─────────────────────────

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    private function transition(
        int $supplierId,
        int $id,
        string $fromState,
        string $set,
        array $params,
    ): ?array {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET ' . $set . ', row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND state = ?'
        );
        $stmt->execute([...$params, $supplierId, $id, $fromState]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        return $this->find($supplierId, $id);
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    private function fetchOne(string $where, array $params): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['id', 'supplier_id', 'outbox_id', 'user_id', 'row_version'] as $column) {
            $row[$column] = (int) $row[$column];
        }

        return $row;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Tabulka ' . self::TABLE . ' neexistuje — spusťte migrace.');
        }
    }
}
