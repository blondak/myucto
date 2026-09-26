<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/**
 * Historie podání ČSSZ, která podal předchozí mzdový program (`payroll_external_jmhz_submissions`,
 * migrace 1901). Jediná cesta zápisu pro obě zdroje: převod z PAMICA i import XML hlášení.
 *
 * Podání se ukládá jako kopie dokladu: hlavička (období, typ, GUID, stav a časy odeslání)
 * v sloupcích, úplný obsah po atributech zapečetěný jako citlivá mzdová hodnota. Formuláře
 * osob mají vlastní řádky s vazbou na vztah v MyÚčtu.
 *
 * Opakovaný import téhož podání (stejný zdroj a `source_key`) řádek přepíše, nezaloží druhý;
 * když se nezměnilo nic, nesahá se ani na něj.
 */
final class JmhzExternalSubmissionStore
{
    public const SOURCE_PAMICA = 'pamica';
    public const SOURCE_JMHZ_XML = 'jmhz_xml';
    public const STATUS_SENT = 'sent';
    public const STATUS_NOT_SENT = 'not_sent';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSensitiveData $sensitive,
    ) {}

    /**
     * @param array{source_key:string,document_kind:string,period:?string,submission_type:?string,
     *   submission_guid:?string,corrected_source_key:?string,status:string,filled_at:?string,
     *   submitted_at:?string,accepted_at:?string,program:?string,file_name:?string,payload:array<string,mixed>} $submission
     * @param list<array{position:int,form_guid:?string,form_type:?string,source_relation_ref:?string,
     *   employee_id:?int,employment_id:?int,payload:array<string,mixed>}> $forms
     * @return array{id:int,status:string}
     */
    public function store(int $supplierId, string $environment, string $source, array $submission, array $forms, ?int $userId): array
    {
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException('Prostředí převzatého podání musí být production nebo test.');
        }
        if (!in_array($source, [self::SOURCE_PAMICA, self::SOURCE_JMHZ_XML], true)) {
            throw new \InvalidArgumentException("Zdroj převzatého podání {$source} neznám.");
        }
        $payload = CanonicalJson::encode($submission['payload']);
        $meta = [
            'document_kind' => $submission['document_kind'],
            'period' => $submission['period'],
            'submission_type' => $submission['submission_type'],
            'submission_guid' => $submission['submission_guid'] === null ? null : strtoupper($submission['submission_guid']),
            'corrected_source_key' => $submission['corrected_source_key'],
            'status' => $submission['status'],
            'filled_at' => self::dateTime($submission['filled_at']),
            'submitted_at' => self::dateTime($submission['submitted_at']),
            'accepted_at' => self::dateTime($submission['accepted_at']),
            'form_count' => count($forms),
            'program' => $submission['program'] === null ? null : mb_substr($submission['program'], 0, 100),
            'file_name' => $submission['file_name'] === null ? null : mb_substr($submission['file_name'], 0, 255),
            'payload_sha256' => hash('sha256', $payload),
        ];
        $formRows = [];
        foreach ($forms as $form) {
            $json = CanonicalJson::encode($form['payload']);
            $formRows[] = [
                'position' => $form['position'],
                'form_guid' => $form['form_guid'] === null ? null : strtoupper($form['form_guid']),
                'form_type' => $form['form_type'],
                'source_relation_ref' => $form['source_relation_ref'],
                'employee_id' => $form['employee_id'],
                'employment_id' => $form['employment_id'],
                'payload' => $json,
                'payload_sha256' => hash('sha256', $json),
            ];
        }

        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $find = $pdo->prepare(
                'SELECT * FROM payroll_external_jmhz_submissions
                  WHERE supplier_id = ? AND environment = ? AND source = ? AND source_key = ? FOR UPDATE'
            );
            $find->execute([$supplierId, $environment, $source, $submission['source_key']]);
            $existing = $find->fetch(\PDO::FETCH_ASSOC);
            if ($existing === false) {
                $insert = $pdo->prepare(
                    'INSERT INTO payroll_external_jmhz_submissions
                       (supplier_id, environment, source, source_key, document_kind, period, submission_type, submission_guid,
                        corrected_source_key, status, filled_at, submitted_at, accepted_at, form_count, program, file_name,
                        payload_ciphertext, payload_hash, payload_sha256, imported_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $supplierId, $environment, $source, $submission['source_key'], $meta['document_kind'], $meta['period'],
                    $meta['submission_type'], $meta['submission_guid'], $meta['corrected_source_key'], $meta['status'],
                    $meta['filled_at'], $meta['submitted_at'], $meta['accepted_at'], $meta['form_count'], $meta['program'],
                    $meta['file_name'], 'pending', str_repeat("\0", 32), $meta['payload_sha256'], $userId,
                ]);
                $id = (int) $pdo->lastInsertId();
                $this->sealSubmission($supplierId, $id, $payload);
                $this->insertForms($supplierId, $id, $formRows);
                $status = 'created';
            } else {
                $id = (int) $existing['id'];
                $changed = false;
                foreach ($meta as $column => $value) {
                    if ((string) ($existing[$column] ?? '') !== (string) ($value ?? '')) {
                        $changed = true;
                        break;
                    }
                }
                if (!$changed && !$this->sameForms($supplierId, $id, $formRows)) {
                    $changed = true;
                }
                if ($changed) {
                    $set = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($meta)));
                    $update = $pdo->prepare(
                        "UPDATE payroll_external_jmhz_submissions SET {$set}, imported_by = ? WHERE supplier_id = ? AND id = ?"
                    );
                    $update->execute([...array_values($meta), $userId, $supplierId, $id]);
                    $this->sealSubmission($supplierId, $id, $payload);
                    $pdo->prepare('DELETE FROM payroll_external_jmhz_submission_forms WHERE supplier_id = ? AND submission_id = ?')
                        ->execute([$supplierId, $id]);
                    $this->insertForms($supplierId, $id, $formRows);
                }
                $status = $changed ? 'updated' : 'unchanged';
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['id' => $id, 'status' => $status];
    }

    /**
     * Přehled převzatých podání pro obrazovku podání (bez obsahu).
     *
     * @return list<array<string,mixed>>
     */
    public function overview(int $supplierId, string $environment): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.source, s.document_kind, s.period, s.submission_type, s.submission_guid, s.status,
                    s.filled_at, s.submitted_at, s.accepted_at, s.form_count, s.program, s.file_name, s.updated_at,
                    (SELECT COUNT(*) FROM payroll_external_jmhz_submission_forms f
                      WHERE f.supplier_id = s.supplier_id AND f.submission_id = s.id AND f.employment_id IS NOT NULL) AS matched_forms
               FROM payroll_external_jmhz_submissions s
              WHERE s.supplier_id = ? AND s.environment = ?
              ORDER BY s.document_kind, s.period DESC, COALESCE(s.submitted_at, s.filled_at) DESC, s.id DESC'
        );
        $stmt->execute([$supplierId, $environment]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'source' => (string) $row['source'],
                'document_kind' => (string) $row['document_kind'],
                'period' => $row['period'],
                'submission_type' => $row['submission_type'],
                'submission_guid' => $row['submission_guid'],
                'status' => (string) $row['status'],
                'filled_at' => $row['filled_at'],
                'submitted_at' => $row['submitted_at'],
                'accepted_at' => $row['accepted_at'],
                'form_count' => (int) $row['form_count'],
                'matched_forms' => (int) $row['matched_forms'],
                'program' => $row['program'],
                'file_name' => $row['file_name'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $out;
    }

    /**
     * Odeslané měsíční hlášení předchozího programu za období, nejnovější podle odeslání;
     * `null`, když za období žádné neodešlo.
     *
     * @return array{id:int,source:string,submission_type:?string,submission_guid:?string,submitted_at:?string,program:?string}|null
     */
    public function sentMonthly(int $supplierId, string $environment, string $period): ?array
    {
        if (!$this->db->hasTable('payroll_external_jmhz_submissions')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, source, submission_type, submission_guid, submitted_at, program
               FROM payroll_external_jmhz_submissions
              WHERE supplier_id = ? AND environment = ? AND document_kind = 'monthly' AND period = ?
                AND status = 'sent' AND (submission_type IS NULL OR submission_type <> 'S')
              ORDER BY COALESCE(submitted_at, filled_at) DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $environment, $period]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'source' => (string) $row['source'],
            'submission_type' => $row['submission_type'],
            'submission_guid' => $row['submission_guid'],
            'submitted_at' => $row['submitted_at'],
            'program' => $row['program'],
        ];
    }

    /**
     * Odebere převzaté podání z historie (i s formuláři) - když záznam neodpovídá
     * skutečnosti (hlášení ve skutečnosti neodešlo, nahrané omylem). Vrací, jestli
     * v téhle firmě a prostředí nějaké bylo.
     */
    public function delete(int $supplierId, string $environment, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM payroll_external_jmhz_submissions WHERE supplier_id = ? AND environment = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $environment, $id]);

        return $stmt->rowCount() > 0;
    }

    private function sealSubmission(int $supplierId, int $id, string $payload): void
    {
        $sealed = $this->sensitive->seal($payload, PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD, $supplierId, $id);
        $this->db->pdo()->prepare(
            'UPDATE payroll_external_jmhz_submissions SET payload_ciphertext = ?, payload_hash = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$sealed->ciphertext, $sealed->lookupHash, $supplierId, $id]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function insertForms(int $supplierId, int $submissionId, array $rows): void
    {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare(
            'INSERT INTO payroll_external_jmhz_submission_forms
               (supplier_id, submission_id, position, form_guid, form_type, source_relation_ref, employee_id, employment_id,
                payload_ciphertext, payload_hash, payload_sha256)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $seal = $pdo->prepare(
            'UPDATE payroll_external_jmhz_submission_forms SET payload_ciphertext = ?, payload_hash = ? WHERE supplier_id = ? AND id = ?'
        );
        foreach ($rows as $row) {
            $insert->execute([
                $supplierId, $submissionId, $row['position'], $row['form_guid'], $row['form_type'], $row['source_relation_ref'],
                $row['employee_id'], $row['employment_id'], 'pending', str_repeat("\0", 32), $row['payload_sha256'],
            ]);
            $id = (int) $pdo->lastInsertId();
            $sealed = $this->sensitive->seal($row['payload'], PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD, $supplierId, $id);
            $seal->execute([$sealed->ciphertext, $sealed->lookupHash, $supplierId, $id]);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function sameForms(int $supplierId, int $submissionId, array $rows): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT position, form_guid, form_type, source_relation_ref, employee_id, employment_id, payload_sha256
               FROM payroll_external_jmhz_submission_forms WHERE supplier_id = ? AND submission_id = ? ORDER BY position'
        );
        $stmt->execute([$supplierId, $submissionId]);
        $stored = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (count($stored) !== count($rows)) {
            return false;
        }
        usort($rows, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        foreach ($rows as $index => $row) {
            foreach (['position', 'form_guid', 'form_type', 'source_relation_ref', 'employee_id', 'employment_id', 'payload_sha256'] as $column) {
                if ((string) ($stored[$index][$column] ?? '') !== (string) ($row[$column] ?? '')) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function dateTime(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}(?::\d{2})?))?/', $value, $m) !== 1) {
            return null;
        }
        $time = $m[2] ?? '00:00:00';

        return $m[1] . ' ' . (strlen($time) === 5 ? $time . ':00' : $time);
    }
}
