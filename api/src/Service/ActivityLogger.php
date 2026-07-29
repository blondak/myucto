<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Loguje akce do tabulky activity_log. Citlivá pole se redaktují
 * (LoggerSanitizer). Pro auth/security události samostatný kanál.
 *
 * Není `final` (EP-4): účetní služby zapisují audit ve STEJNÉ transakci jako
 * účetní mutaci, takže integrační testy potřebují injektovat testovacího dvojníka
 * loggeru, který zápis auditu záměrně shodí (ověření rollbacku mutace). log() je
 * proto přepsatelný; produkční chování zůstává beze změny.
 */
class ActivityLogger
{
    private const REDACT_KEYS = [
        'password', 'password_confirm', 'current_password', 'new_password',
        'token', 'csrf_token', 'cf_turnstile_response', 'secret_key',
        'private_key', 'pass',
        'flow_token', 'step_up_token', 'challenge', 'credential', 'rawid',
        'raw_id', 'signature', 'authenticator_data', 'client_data_json',
        'attestation_object', 'public_key',
    ];

    public function __construct(
        private readonly Connection $db,
        // § 33a — zřetězení auditní stopy hashem. Volitelná, aby testovací dvojníci
        // loggeru (viz docblock třídy) nemuseli řetěz řešit.
        private readonly ?ActivityLogHashChain $hashChain = null,
    ) {}

    /** @param array<array-key,mixed>|null $payload */
    public function log(
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $payload = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?int $supplierId = null,
    ): void {
        // Auto-resolve supplier_id z entity (invoice/client/project) když nebylo zadáno
        if ($supplierId === null && $entityId !== null && $entityType !== null) {
            $supplierId = $this->resolveSupplierId($entityType, $entityId);
        }

        $sql = 'INSERT INTO activity_log
                (supplier_id, user_id, action, entity_type, entity_id, payload, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $supplierId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $payload === null ? null : json_encode($this->redact($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $ip !== null ? (@inet_pton($ip) ?: null) : null,
            $userAgent !== null ? substr($userAgent, 0, 255) : null,
        ]);

        // Zapečetění hned po vložení, uvnitř TÉŽE transakce jako zapisovaná mutace —
        // jinak by při rollbacku zůstal v řetězu článek bez odpovídající změny.
        $this->hashChain?->seal((int) $this->db->pdo()->lastInsertId());
    }

    /** Auto-resolve supplier_id z entity podle entity_type. NULL pro cross-cutting akce. */
    private function resolveSupplierId(string $entityType, int $entityId): ?int
    {
        $pdo = $this->db->pdo();
        $sql = match ($entityType) {
            'invoice'  => 'SELECT supplier_id FROM invoices WHERE id = ?',
            'client'   => 'SELECT supplier_id FROM clients  WHERE id = ?',
            'project'  => 'SELECT c.supplier_id FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ?',
            'supplier' => 'SELECT id FROM supplier WHERE id = ?',
            default    => null,
        };
        if ($sql === null) return null;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$entityId]);
        $sid = $stmt->fetchColumn();
        return $sid !== false && $sid !== null ? (int) $sid : null;
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<array-key,mixed>
     */
    public function redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT_KEYS, true)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->redact($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
