<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Řádky personálního spisu — metadata dokumentů a zašifrované poznámky.
 *
 * Obsah souborů ani text poznámek tu v čitelné podobě nikdy není; to drží
 * {@see \MyInvoice\Service\Payroll\Personnel\PayrollPersonnelFileService}.
 * Každý dotaz je zúžený na dvojici (firma, osoba), takže cizí id vrátí prázdno.
 */
final class PayrollPersonnelFileRepository
{
    public const CATEGORIES = [
        'employment_contract',
        'contract_amendment',
        'job_description',
        'termination',
        'agreement',
        'certificate',
        'medical',
        'training',
        'other',
    ];

    private const DOCUMENT_COLUMNS = 'd.id, d.employee_id, d.category, d.title, d.document_date,
        d.valid_until, d.note, d.original_name, d.mime_type, d.size_bytes, d.file_sha256,
        d.created_at, d.updated_at, d.uploaded_by, uploader.name AS uploaded_by_name';

    public function __construct(private readonly Connection $db) {}

    public function employeeExists(int $supplierId, int $employeeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_employees WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $employeeId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function listDocuments(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::DOCUMENT_COLUMNS . '
               FROM payroll_personnel_documents d
               LEFT JOIN users uploader ON uploader.id = d.uploaded_by
              WHERE d.supplier_id = ? AND d.employee_id = ?
              ORDER BY COALESCE(d.document_date, DATE(d.created_at)) DESC, d.id DESC',
        );
        $stmt->execute([$supplierId, $employeeId]);

        return array_map(self::document(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function findDocument(int $supplierId, int $employeeId, int $documentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::DOCUMENT_COLUMNS . '
               FROM payroll_personnel_documents d
               LEFT JOIN users uploader ON uploader.id = d.uploaded_by
              WHERE d.supplier_id = ? AND d.employee_id = ? AND d.id = ?',
        );
        $stmt->execute([$supplierId, $employeeId, $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::document($row);
    }

    public function findDocumentIdBySha(int $supplierId, int $employeeId, string $sha256): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_personnel_documents
              WHERE supplier_id = ? AND employee_id = ? AND file_sha256 = ?',
        );
        $stmt->execute([$supplierId, $employeeId, $sha256]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array{category:string,title:string,document_date:?string,valid_until:?string,note:?string} $meta
     * @param array{original_name:string,mime_type:string,size_bytes:int,file_sha256:string} $file
     */
    public function insertDocument(
        int $supplierId,
        int $employeeId,
        array $meta,
        array $file,
        ?int $userId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_personnel_documents
                (supplier_id, employee_id, category, title, document_date, valid_until, note,
                 original_name, mime_type, size_bytes, file_sha256, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $supplierId,
            $employeeId,
            $meta['category'],
            $meta['title'],
            $meta['document_date'],
            $meta['valid_until'],
            $meta['note'],
            $file['original_name'],
            $file['mime_type'],
            $file['size_bytes'],
            $file['file_sha256'],
            $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{category:string,title:string,document_date:?string,valid_until:?string,note:?string} $meta */
    public function updateDocument(
        int $supplierId,
        int $employeeId,
        int $documentId,
        array $meta,
        ?int $userId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_personnel_documents
                SET category = ?, title = ?, document_date = ?, valid_until = ?, note = ?,
                    updated_by = ?
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        );
        $stmt->execute([
            $meta['category'],
            $meta['title'],
            $meta['document_date'],
            $meta['valid_until'],
            $meta['note'],
            $userId,
            $supplierId,
            $employeeId,
            $documentId,
        ]);
    }

    public function deleteDocument(int $supplierId, int $employeeId, int $documentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM payroll_personnel_documents
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $employeeId, $documentId]);
    }

    /** @return list<array<string,mixed>> */
    public function listNotes(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT n.id, n.body_ciphertext, n.pinned, n.created_at, n.updated_at,
                    n.created_by, author.name AS created_by_name,
                    n.updated_by, editor.name AS updated_by_name
               FROM payroll_personnel_notes n
               LEFT JOIN users author ON author.id = n.created_by
               LEFT JOIN users editor ON editor.id = n.updated_by
              WHERE n.supplier_id = ? AND n.employee_id = ?
              ORDER BY n.pinned DESC, n.created_at DESC, n.id DESC',
        );
        $stmt->execute([$supplierId, $employeeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function noteExists(int $supplierId, int $employeeId, int $noteId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_personnel_notes
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $employeeId, $noteId]);

        return $stmt->fetchColumn() !== false;
    }

    public function insertNote(
        int $supplierId,
        int $employeeId,
        string $ciphertext,
        bool $pinned,
        ?int $userId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_personnel_notes
                (supplier_id, employee_id, body_ciphertext, pinned, created_by)
             VALUES (?, ?, ?, ?, ?)',
        );
        $stmt->execute([$supplierId, $employeeId, $ciphertext, $pinned ? 1 : 0, $userId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function updateNote(
        int $supplierId,
        int $employeeId,
        int $noteId,
        string $ciphertext,
        bool $pinned,
        ?int $userId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_personnel_notes
                SET body_ciphertext = ?, pinned = ?, updated_by = ?
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        );
        $stmt->execute([$ciphertext, $pinned ? 1 : 0, $userId, $supplierId, $employeeId, $noteId]);
    }

    public function deleteNote(int $supplierId, int $employeeId, int $noteId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM payroll_personnel_notes
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $employeeId, $noteId]);
    }

    /**
     * Odstraní celý spis osoby (výkon výmazu osobních údajů).
     *
     * @return list<string> otisky smazaných souborů
     */
    public function purge(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT file_sha256 FROM payroll_personnel_documents WHERE supplier_id = ? AND employee_id = ?',
        );
        $stmt->execute([$supplierId, $employeeId]);
        $hashes = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach (['payroll_personnel_documents', 'payroll_personnel_notes'] as $table) {
            $this->db->pdo()->prepare("DELETE FROM {$table} WHERE supplier_id = ? AND employee_id = ?")
                ->execute([$supplierId, $employeeId]);
        }

        return $hashes;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function document(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'category' => (string) $row['category'],
            'title' => (string) $row['title'],
            'document_date' => $row['document_date'] !== null ? (string) $row['document_date'] : null,
            'valid_until' => $row['valid_until'] !== null ? (string) $row['valid_until'] : null,
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'original_name' => (string) $row['original_name'],
            'mime_type' => (string) $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'file_sha256' => (string) $row['file_sha256'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'uploaded_by_name' => $row['uploaded_by_name'] !== null ? (string) $row['uploaded_by_name'] : null,
        ];
    }
}
