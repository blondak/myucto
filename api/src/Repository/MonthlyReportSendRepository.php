<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Log odeslání "Měsíčního přehledu" klientovi (Fáze F, audit 2026-07).
 *
 * Tenant izolace: čtecí metody vždy filtrují supplier_id = ?.
 */
final class MonthlyReportSendRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<string> $sentTo
     * @param list<string> $cc
     */
    public function insert(
        int $supplierId,
        int $year,
        int $month,
        array $sentTo,
        array $cc,
        ?string $comment,
        ?int $documentId,
        ?string $smtpResponse,
        ?int $userId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO monthly_report_sends
               (supplier_id, report_year, report_month, sent_to, cc, comment, document_id, smtp_response, sent_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $year,
            $month,
            json_encode($sentTo, JSON_UNESCAPED_UNICODE),
            $cc !== [] ? json_encode($cc, JSON_UNESCAPED_UNICODE) : null,
            $comment,
            $documentId,
            $smtpResponse,
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Historie odeslaných přehledů, nejnovější první.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $supplierId, int $limit = 30): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT mrs.*, u.name AS sent_by_name
               FROM monthly_report_sends mrs
               LEFT JOIN users u ON u.id = mrs.sent_by_user_id
              WHERE mrs.supplier_id = ?
           ORDER BY mrs.created_at DESC, mrs.id DESC
              LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']            = (int) $r['id'];
            $r['report_year']   = (int) $r['report_year'];
            $r['report_month']  = (int) $r['report_month'];
            $r['document_id']   = $r['document_id'] !== null ? (int) $r['document_id'] : null;
            $r['sent_to']       = json_decode((string) $r['sent_to'], true) ?: [];
            $r['cc']            = $r['cc'] !== null ? (json_decode((string) $r['cc'], true) ?: []) : [];
        }
        return $rows;
    }
}
