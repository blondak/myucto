<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Poslední úplné měření {@see PayrollKeyRotationService::status()}.
 *
 * Plný průchod čte všechny šifrované sloupce a hlavičky souborů exportů;
 * pouštět ho při každém otevření Diagnostiky nejde. Výsledek se proto drží
 * v `app_meta` (globální, přežije flush Redisu) i s časem měření a Diagnostika
 * ukazuje jeho stáří. Za rotace se měření obnoví po {@see self::ROTATION_TTL_SECONDS},
 * mimo ni jen ručně tlačítkem.
 */
final class PayrollKeyRotationStatusCache
{
    public const KEY = 'payroll_key_rotation_status';

    /** Za rozpracované rotace se plný průchod opakuje nejvýš jednou za hodinu. */
    public const ROTATION_TTL_SECONDS = 3600;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{measured_at:string,status:array<string,mixed>}|null
     */
    public function get(): ?array
    {
        if (!$this->db->hasTable('app_meta')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT v FROM app_meta WHERE k = ?');
        $stmt->execute([self::KEY]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || !is_string($decoded['measured_at'] ?? null)
            || !is_array($decoded['status'] ?? null)
        ) {
            return null;
        }

        return ['measured_at' => $decoded['measured_at'], 'status' => $decoded['status']];
    }

    /**
     * @param array<string,mixed> $status
     * @return array{measured_at:string,status:array<string,mixed>}
     */
    public function put(array $status, ?\DateTimeImmutable $measuredAt = null): array
    {
        $entry = [
            'measured_at' => ($measuredAt ?? new \DateTimeImmutable())->format(DATE_ATOM),
            'status' => $status,
        ];
        $this->db->pdo()->prepare(
            'INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
        )->execute([self::KEY, json_encode($entry, JSON_THROW_ON_ERROR)]);

        return $entry;
    }

    /** @param array{measured_at:string,status:array<string,mixed>} $entry */
    public static function isFresh(array $entry, \DateTimeImmutable $now): bool
    {
        $measured = \DateTimeImmutable::createFromFormat(DATE_ATOM, $entry['measured_at']);

        return $measured instanceof \DateTimeImmutable
            && $now->getTimestamp() - $measured->getTimestamp() < self::ROTATION_TTL_SECONDS;
    }
}
