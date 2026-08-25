<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Přístupové údaje ke kanálu (migrace 1381).
 *
 * ── Jediné pravidlo, na kterém tu všechno stojí ──────────────────────────────
 * Existují DVĚ projekce a nikdy se nesmí prohodit:
 *   {@see PUBLIC_COLUMNS} — nikdy neobsahuje `*_ciphertext`; tohle jde do API,
 *   {@see SECRET_COLUMNS} — obsahuje je; volá se výhradně z odemykání.
 *
 * Je to stejná disciplína jako u `EpoSigningCredentialRepository`: maskování
 * se neřeší filtrováním pole po načtení (což se dá zapomenout), ale tím, že
 * se citlivý sloupec do dotazu vůbec nenapíše. Co se nevybere, to neunikne.
 */
final class SubmissionChannelCredentialRepository
{
    private const TABLE = 'submission_channel_credentials';

    private const PUBLIC_COLUMNS = 'id, supplier_id, environment, channel, label, box_id, auth_mode,
        certificate_fingerprint, certificate_valid_to, last_verified_at,
        inbox_polling_enabled, inbox_polling_enabled_at, inbox_polling_enabled_by,
        created_at, updated_at';

    private const SECRET_COLUMNS = self::PUBLIC_COLUMNS . ', certificate_ciphertext,
        certificate_passphrase_ciphertext';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /**
     * Veřejný pohled — bezpečný pro API odpověď.
     *
     * @return list<array<string,mixed>>
     */
    public function listPublic(int $supplierId): array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? ORDER BY channel ASC, environment ASC'
        );
        $stmt->execute([$supplierId]);
        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null Veřejný pohled. */
    public function findPublic(int $supplierId, string $channel, string $environment): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND channel = ? AND environment = ?'
        );
        $stmt->execute([$supplierId, $channel, $environment]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * ⚠️ Vrací ciphertexty. Volat VÝHRADNĚ z
     * {@see \MyInvoice\Service\Submission\SubmissionCredentialService::unlock()};
     * návratová hodnota nesmí opustit tu metodu.
     *
     * @return array<string,mixed>|null
     */
    public function findWithSecrets(int $supplierId, string $channel, string $environment): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::SECRET_COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND channel = ? AND environment = ?'
        );
        $stmt->execute([$supplierId, $channel, $environment]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @param array{
     *   label:string, box_id:string,
     *   certificate_ciphertext:string, certificate_passphrase_ciphertext:?string,
     *   certificate_fingerprint:?string, certificate_valid_to:?string
     * } $data
     */
    public function save(int $supplierId, string $channel, string $environment, array $data, ?int $userId): void
    {
        $this->assertAvailable();
        // Historické sloupce `inbox_polling_*` se tu nenastavují. Automatické
        // vybírání bylo odstraněno; migrace 1530 případné staré volby vynuluje.
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (supplier_id, environment, channel, label, box_id, auth_mode,
                 certificate_ciphertext, certificate_passphrase_ciphertext,
                 certificate_fingerprint, certificate_valid_to, created_by)
             VALUES (?, ?, ?, ?, ?, \'certificate\', ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label), box_id = VALUES(box_id),
                certificate_ciphertext = VALUES(certificate_ciphertext),
                certificate_passphrase_ciphertext = VALUES(certificate_passphrase_ciphertext),
                certificate_fingerprint = VALUES(certificate_fingerprint),
                certificate_valid_to = VALUES(certificate_valid_to),
                last_verified_at = NULL'
        );
        $stmt->execute([
            $supplierId,
            $environment,
            $channel,
            $data['label'],
            $data['box_id'],
            $data['certificate_ciphertext'],
            $data['certificate_passphrase_ciphertext'],
            $data['certificate_fingerprint'],
            $data['certificate_valid_to'],
            $userId,
        ]);
    }

    public function delete(int $supplierId, string $channel, string $environment): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE supplier_id = ? AND channel = ? AND environment = ?'
        );
        $stmt->execute([$supplierId, $channel, $environment]);
        return $stmt->rowCount() > 0;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException('Trezor přístupů ke kanálům není k dispozici (chybí migrace 1381).');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['inbox_polling_enabled'] = (bool) $row['inbox_polling_enabled'];
        $row['inbox_polling_enabled_by'] = $row['inbox_polling_enabled_by'] !== null
            ? (int) $row['inbox_polling_enabled_by']
            : null;
        return $row;
    }
}
