<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
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
    /** Účetní potvrdila, že řádné hlášení podal předchozí program nebo portál ČSSZ (migrace 1951). */
    public const SOURCE_MANUAL_ATTESTATION = 'manual_attestation';
    public const STATUS_SENT = 'sent';
    public const STATUS_NOT_SENT = 'not_sent';

    /** Věta registrace ({@see PohodaPayrollJmhzWriter}) => akce REGZEC. */
    private const REGISTRATION_ACTIONS = ['start' => 'A1', 'end' => 'A2', 'existing' => 'A3'];

    /** Kolik osob měsíčního hlášení ukáže přehled; celý seznam je v detailu. */
    private const PEOPLE_PREVIEW = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSensitiveData $sensitive,
    ) {}

    /**
     * @param array{source_key:string,document_kind:string,period:?string,submission_type:?string,
     *   submission_guid:?string,corrected_source_key:?string,status:string,filled_at:?string,
     *   submitted_at:?string,accepted_at:?string,program:?string,file_name:?string,note?:?string,payload:array<string,mixed>} $submission
     * @param list<array{position:int,form_guid:?string,form_type:?string,source_relation_ref:?string,
     *   employee_id:?int,employment_id:?int,payload:array<string,mixed>}> $forms
     * @return array{id:int,status:string}
     */
    public function store(int $supplierId, string $environment, string $source, array $submission, array $forms, ?int $userId): array
    {
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException('Prostředí převzatého podání musí být production nebo test.');
        }
        if (!in_array($source, [self::SOURCE_PAMICA, self::SOURCE_JMHZ_XML, self::SOURCE_MANUAL_ATTESTATION], true)) {
            throw new \InvalidArgumentException("Zdroj převzatého podání {$source} neznám.");
        }
        $payload = CanonicalJson::encode($submission['payload']);
        $note = isset($submission['note']) && is_string($submission['note']) && trim($submission['note']) !== ''
            ? mb_substr(trim($submission['note']), 0, 500)
            : null;
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
            'note' => $note,
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
                        note, payload_ciphertext, payload_hash, payload_sha256, imported_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $supplierId, $environment, $source, $submission['source_key'], $meta['document_kind'], $meta['period'],
                    $meta['submission_type'], $meta['submission_guid'], $meta['corrected_source_key'], $meta['status'],
                    $meta['filled_at'], $meta['submitted_at'], $meta['accepted_at'], $meta['form_count'], $meta['program'],
                    $meta['file_name'], $meta['note'], 'pending', str_repeat("\0", 32), $meta['payload_sha256'], $userId,
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
                    s.filled_at, s.submitted_at, s.accepted_at, s.form_count, s.program, s.file_name, s.note, s.updated_at,
                    (SELECT COUNT(*) FROM payroll_external_jmhz_submission_forms f
                      WHERE f.supplier_id = s.supplier_id AND f.submission_id = s.id AND f.employment_id IS NOT NULL) AS matched_forms
               FROM payroll_external_jmhz_submissions s
              WHERE s.supplier_id = ? AND s.environment = ?
              ORDER BY s.document_kind, s.period DESC, COALESCE(s.submitted_at, s.filled_at) DESC, s.id DESC'
        );
        $stmt->execute([$supplierId, $environment]);
        $forms = $this->formSummaries($supplierId, $environment, null);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->withForms($row, $forms[(int) $row['id']] ?? [], self::PEOPLE_PREVIEW);
        }

        return $out;
    }

    /**
     * Jedno převzaté podání k přečtení: hlavička, výsledek a všechny formuláře
     * s osobou, akcí a dnem účinnosti. Obsah dokladu se neposílá celý, jen
     * údaje, podle kterých uživatel pozná, co se podalo (rodná čísla ani částky
     * z podání neodcházejí).
     *
     * @return array<string,mixed>|null
     */
    public function detail(int $supplierId, string $environment, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.source, s.document_kind, s.period, s.submission_type, s.submission_guid, s.status,
                    s.filled_at, s.submitted_at, s.accepted_at, s.form_count, s.program, s.file_name, s.note, s.updated_at,
                    s.corrected_source_key, s.source_key,
                    (SELECT COUNT(*) FROM payroll_external_jmhz_submission_forms f
                      WHERE f.supplier_id = s.supplier_id AND f.submission_id = s.id AND f.employment_id IS NOT NULL) AS matched_forms
               FROM payroll_external_jmhz_submissions s
              WHERE s.supplier_id = ? AND s.environment = ? AND s.id = ?'
        );
        $stmt->execute([$supplierId, $environment, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $forms = $this->formSummaries($supplierId, $environment, $id)[$id] ?? [];
        $out = $this->withForms($row, $forms, null);
        $out['forms'] = $forms;
        $out['corrects'] = null;
        if ($row['corrected_source_key'] !== null) {
            $corrected = $this->db->pdo()->prepare(
                'SELECT id, period, submission_type, submitted_at FROM payroll_external_jmhz_submissions
                  WHERE supplier_id = ? AND environment = ? AND source = ? AND source_key = ?'
            );
            $corrected->execute([$supplierId, $environment, $row['source'], $row['corrected_source_key']]);
            $target = $corrected->fetch(\PDO::FETCH_ASSOC);
            if ($target !== false) {
                $out['corrects'] = [
                    'id' => (int) $target['id'],
                    'period' => $target['period'],
                    'submission_type' => $target['submission_type'],
                    'submitted_at' => $target['submitted_at'],
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $forms
     * @return array<string,mixed>
     */
    private function withForms(array $row, array $forms, ?int $preview): array
    {
        $actions = [];
        $effective = [];
        foreach ($forms as $form) {
            $action = (string) ($form['action'] ?? '?');
            $actions[$action] = ($actions[$action] ?? 0) + 1;
            if (is_string($form['effective_on'])) {
                $effective[] = $form['effective_on'];
            }
        }
        ksort($actions);
        sort($effective);
        $people = $row['document_kind'] === 'registration' || $preview === null
            ? $forms
            : array_slice($forms, 0, $preview);

        return [
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
            'note' => $row['note'] ?? null,
            'updated_at' => $row['updated_at'],
            'actions' => $actions,
            'people' => array_map(static fn (array $form): array => [
                'employee_id' => $form['employee_id'],
                'employment_id' => $form['employment_id'],
                'name' => $form['name'],
                'code' => $form['code'],
                'action' => $form['action'],
                'effective_on' => $form['effective_on'],
            ], $people),
            'effective_from' => $effective[0] ?? null,
            'effective_to' => $effective === [] ? null : $effective[count($effective) - 1],
        ];
    }

    /**
     * Formuláře podání s osobou, akcí a dnem účinnosti, podle podání. Obsah se
     * odpečetí jen u registrací (den účinnosti je uvnitř); měsíční hlášení mají
     * akci v hlavičce formuláře.
     *
     * @return array<int,list<array{position:int,employee_id:?int,employment_id:?int,name:?string,code:?string,
     *   source_relation_ref:?string,action:?string,form_type:?string,effective_on:?string,unreadable:bool}>>
     */
    private function formSummaries(int $supplierId, string $environment, ?int $submissionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT f.id, f.submission_id, f.position, f.form_type, f.employee_id, f.employment_id, f.source_relation_ref,
                    s.document_kind, employee.full_name, employment.code,
                    CASE WHEN s.document_kind = "registration" OR s.id = ? THEN f.payload_ciphertext END AS payload
               FROM payroll_external_jmhz_submission_forms f
               JOIN payroll_external_jmhz_submissions s
                 ON s.supplier_id = f.supplier_id AND s.id = f.submission_id
               LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = f.supplier_id AND employee.id = f.employee_id
               LEFT JOIN payroll_employments employment
                 ON employment.supplier_id = f.supplier_id AND employment.id = f.employment_id
              WHERE f.supplier_id = ? AND s.environment = ? AND (? IS NULL OR s.id = ?)
              ORDER BY f.submission_id, f.position'
        );
        $stmt->execute([$submissionId ?? 0, $supplierId, $environment, $submissionId, $submissionId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $payload = [];
            if (is_string($row['payload']) && str_starts_with($row['payload'], 'enc:v2:')) {
                try {
                    $payload = json_decode($this->sensitive->reveal($row['payload'], PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD,
                        $supplierId, (int) $row['id'], PayrollRevealPurpose::SUBMISSION_CSSZ_REGISTRATION), true) ?: [];
                } catch (\Throwable) {
                    $payload = [];
                }
            }
            $formType = $row['form_type'] === null ? null : (string) $row['form_type'];
            $registration = $row['document_kind'] === 'registration';
            $out[(int) $row['submission_id']][] = [
                'position' => (int) $row['position'],
                'employee_id' => $row['employee_id'] === null ? null : (int) $row['employee_id'],
                'employment_id' => $row['employment_id'] === null ? null : (int) $row['employment_id'],
                'name' => $row['full_name'] === null ? null : (string) $row['full_name'],
                'code' => $row['code'] === null ? null : (string) $row['code'],
                'source_relation_ref' => $row['source_relation_ref'],
                'action' => $registration ? (self::REGISTRATION_ACTIONS[$formType] ?? $formType) : $formType,
                'form_type' => $formType,
                'effective_on' => $registration ? self::effectiveOn($formType, $payload['attributes'] ?? []) : null,
                'unreadable' => is_string($payload['error'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Den účinnosti registrace z obsahu věty: nástup (10223) u přihlášky,
     * skončení (10224) u odhlášky, jinak „platnost od" (10009).
     *
     * @param mixed $attributes
     */
    private static function effectiveOn(?string $formType, mixed $attributes): ?string
    {
        if (!is_array($attributes)) {
            return null;
        }
        $values = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || !isset($attribute['id'], $attribute['value']) || (int) ($attribute['order'] ?? 0) !== 0) {
                continue;
            }
            $values[(int) $attribute['id']] ??= (string) $attribute['value'];
        }
        $order = match ($formType) {
            'start' => [10223, 10009],
            'end' => [10224, 10009],
            default => [10009],
        };
        foreach ($order as $id) {
            $value = trim($values[$id] ?? '');
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) === 1) {
                return $m[1];
            }
            // PAMICA ukládá atributy v českém zápisu.
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/D', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            }
        }

        return null;
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

    /**
     * Potvrzení, že řádné měsíční hlášení za období podal předchozí program nebo
     * portál ČSSZ mimo MyÚčto. Zapisuje se do téže historie jako převzatá podání
     * (zdroj `manual_attestation`), takže zákaz druhého řádného hlášení, hlídač
     * převzatých měsíců i přehled podání ho berou jako podané. Opakované potvrzení
     * téhož měsíce záznam přepíše.
     *
     * @return array{id:int,status:string}
     */
    public function attestMonthly(
        int $supplierId,
        string $environment,
        string $period,
        string $submittedOn,
        ?string $note,
        ?int $userId,
    ): array {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Období musí mít tvar RRRR-MM.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $submittedOn);
        if ($date === false || $date->format('Y-m-d') !== $submittedOn) {
            throw new \InvalidArgumentException('Datum podání musí mít tvar RRRR-MM-DD.');
        }

        return $this->store($supplierId, $environment, self::SOURCE_MANUAL_ATTESTATION, [
            'source_key' => 'manual:' . $period,
            'document_kind' => 'monthly',
            'period' => $period,
            'submission_type' => 'R',
            'submission_guid' => null,
            'corrected_source_key' => null,
            'status' => self::STATUS_SENT,
            'filled_at' => null,
            'submitted_at' => $submittedOn,
            'accepted_at' => null,
            'program' => null,
            'file_name' => null,
            'note' => $note,
            'payload' => [
                'kind' => 'manual_attestation',
                'period' => $period,
                'submitted_on' => $submittedOn,
                'attested_by' => $userId,
            ],
        ], [], $userId);
    }

    /**
     * Vezme potvrzení podání mimo MyÚčto zpět. Maže jen záznam se zdrojem
     * `manual_attestation`; převzatý doklad (PAMICA, XML) tudy smazat nejde.
     *
     * @return array{period:string,submitted_at:?string,note:?string}|null null, když takové potvrzení není
     */
    public function revokeAttestation(int $supplierId, string $environment, int $id): ?array
    {
        $find = $this->db->pdo()->prepare(
            'SELECT period, submitted_at, note FROM payroll_external_jmhz_submissions
              WHERE supplier_id = ? AND environment = ? AND id = ? AND source = ?'
        );
        $find->execute([$supplierId, $environment, $id, self::SOURCE_MANUAL_ATTESTATION]);
        $row = $find->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_external_jmhz_submissions
              WHERE supplier_id = ? AND environment = ? AND id = ? AND source = ?'
        )->execute([$supplierId, $environment, $id, self::SOURCE_MANUAL_ATTESTATION]);

        return [
            'period' => (string) $row['period'],
            'submitted_at' => $row['submitted_at'],
            'note' => $row['note'],
        ];
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
