<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Epo;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čte stav EPO pokusu z `tax_submission_attempts` (migrace 1142).
 *
 * Projekce schválně nevybírá `*_ciphertext` sloupce — stejná disciplína jako
 * u trezoru certifikátů: co se nevybere, to neunikne.
 */
final class EpoAttemptStatusRepository implements EpoAttemptStatusReader
{
    private const TABLE = 'tax_submission_attempts';

    public function __construct(private readonly Connection $db) {}

    public function findAttempt(int $supplierId, string $attemptReference): ?array
    {
        if (!$this->db->hasTable(self::TABLE)) {
            return null;
        }
        $attemptId = filter_var($attemptReference, FILTER_VALIDATE_INT);
        if ($attemptId === false || $attemptId <= 0) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT status, remote_submission_ref, confirmed_at, resolved_at, error_message
               FROM ' . self::TABLE . '
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$attemptId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'status' => (string) $row['status'],
            'submission_ref' => $row['remote_submission_ref'] !== null
                ? (string) $row['remote_submission_ref']
                : null,
            'decided_at' => $row['confirmed_at'] !== null
                ? (string) $row['confirmed_at']
                : ($row['resolved_at'] !== null ? (string) $row['resolved_at'] : null),
            'error_message' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
        ];
    }

    public function confirmation(int $supplierId, string $attemptReference): ?array
    {
        // Opis podání z EPO je uložený zašifrovaně a jeho vydání má vlastní
        // cestu se step-up ověřením (EpoDirectSubmissionAction). Fronta podání
        // ho tudy nevydává — jinak by se dal citlivý dokument vytáhnout
        // obejitím té kontroly.
        return null;
    }
}
