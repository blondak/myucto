<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEnforcementFactsRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementPaymentRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementTerminationNoticeRepository;
use MyInvoice\Service\Payroll\Document\PayrollDocumentEmployerSnapshotProvider;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use PDO;

/**
 * Oznámení plátce mzdy soudu / exekutorovi, že u něj povinný přestal pracovat.
 *
 * § 295 odst. 2 o. s. ř.: „Plátce mzdy musí oznámit soudu do jednoho týdne, že
 * u něho přestal povinný pracovat. Zároveň zašle soudu vyúčtování srážek, které
 * ze mzdy povinného provedl a vyplatil oprávněným a oznámí soudu, pro které
 * pohledávky byl nařízen výkon rozhodnutí srážkami ze mzdy a jaké pořadí mají
 * tyto pohledávky." V exekuci soudního exekutora se podle § 52 odst. 1
 * exekučního řádu použije přiměřeně — adresátem je exekutor.
 * (https://www.zakonyprolidi.cz/cs/1963-99#p295)
 *
 * Obsah oznámení se při vystavení ZMRAZÍ (`snapshot_json`) — oznámení je
 * úkon k určitému dni a pozdější platba ani oprava ho nesmí přepsat. Nové
 * vystavení je nová revize.
 */
final class EnforcementTerminationNoticeService
{
    public const SCHEMA = 'payroll-enforcement-termination-notice.v1';

    /** § 295 odst. 2 o. s. ř. — „do jednoho týdne". */
    public const DUE_DAYS = 7;

    private const CHANNELS = ['isds', 'post', 'personal', 'other'];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEnforcementTerminationNoticeRepository $notices,
        private readonly PayrollEnforcementPaymentRepository $payments,
        private readonly PayrollEnforcementFactsRepository $facts,
        private readonly PayrollDocumentEmployerSnapshotProvider $employers,
        private readonly EnforcementTerminationNoticeDocument $document,
        private readonly SubmissionOutboxService $outbox,
    ) {}

    /** @return array{notices:list<array<string,mixed>>,preview:?array<string,mixed>,blocked_reason:?string} */
    public function overview(int $supplierId, int $caseId): array
    {
        $preview = null;
        $blocked = null;
        try {
            $preview = $this->snapshot($supplierId, $caseId, null, null);
        } catch (\DomainException $exception) {
            $blocked = $exception->getMessage();
        }

        return [
            'notices' => $this->notices->listForCase($supplierId, $caseId),
            'preview' => $preview,
            'blocked_reason' => $blocked,
        ];
    }

    /** @return array<string,mixed> */
    public function generate(
        int $supplierId,
        int $caseId,
        ?string $newPayerName,
        ?string $newPayerReference,
        ?int $userId,
    ): array {
        $newPayerName = self::optionalText($newPayerName, 255, 'Nový plátce mzdy');
        $newPayerReference = self::optionalText($newPayerReference, 128, 'Identifikace nového plátce');
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $snapshot = $this->snapshot($supplierId, $caseId, $newPayerName, $newPayerReference);
            $row = $this->notices->insert(
                $supplierId,
                $caseId,
                (int) $snapshot['employee']['id'],
                $snapshot['employment']['id'],
                (string) $snapshot['employment']['ended_on'],
                (string) $snapshot['due_on'],
                $newPayerName,
                $newPayerReference,
                CanonicalJson::encode($snapshot),
                $userId,
            );
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        unset($row['snapshot_json']);

        return $row;
    }

    /** @return array{bytes:string,filename:string,mime:string} */
    public function pdf(int $supplierId, int $noticeId): array
    {
        return $this->document->pdf($supplierId, $noticeId)
            ?? throw new \OutOfBoundsException('Oznámení nebylo nalezeno.');
    }

    /** @return array<string,mixed> */
    public function markSent(
        int $supplierId,
        int $noticeId,
        string $sentOn,
        string $channel,
        ?int $userId,
    ): array {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $sentOn);
        if ($date === false || $date->format('Y-m-d') !== $sentOn) {
            throw new \InvalidArgumentException('Datum odeslání musí být ve tvaru RRRR-MM-DD.');
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException('Neznámý způsob odeslání oznámení.');
        }
        $notice = $this->notices->find($supplierId, $noticeId)
            ?? throw new \OutOfBoundsException('Oznámení nebylo nalezeno.');
        if ($sentOn < (string) $notice['employment_ended_on']) {
            throw new \InvalidArgumentException('Oznámení nelze odeslat před skončením poměru.');
        }
        $this->notices->markSent($supplierId, $noticeId, $sentOn, $channel, null, $userId);
        $updated = $this->notices->find($supplierId, $noticeId) ?? $notice;
        unset($updated['snapshot_json']);

        return $updated;
    }

    /**
     * Zařadí oznámení do fronty datové schránky jako koncept. Odešle ho až
     * člověk v agendě Datová schránka — zařazení samo nic neodesílá.
     *
     * @return array{outbox_id:int,created:bool}
     */
    public function enqueueIsds(
        int $supplierId,
        int $noticeId,
        int $recipientId,
        string $environment,
        ?int $userId,
    ): array {
        $notice = $this->notices->find($supplierId, $noticeId)
            ?? throw new \OutOfBoundsException('Oznámení nebylo nalezeno.');
        $queued = $this->outbox->enqueue(
            $supplierId,
            $environment,
            'isds',
            'EXEKUCE_OZNAMENI',
            'payroll_enforcement_notice',
            $noticeId,
            $recipientId,
            'Oznámení o skončení pracovního poměru povinného (§ 295 odst. 2 o. s. ř.)',
            $userId,
        );
        $outboxId = (int) $queued['row']['id'];
        $this->notices->attachOutbox($supplierId, (int) $notice['id'], $outboxId);

        return ['outbox_id' => $outboxId, 'created' => (bool) $queued['created']];
    }

    /** @return array<string,mixed> */
    private function snapshot(
        int $supplierId,
        int $caseId,
        ?string $newPayerName,
        ?string $newPayerReference,
    ): array {
        $pdo = $this->db->pdo();
        $caseStmt = $pdo->prepare(
            'SELECT c.id, c.employee_id, c.case_kind, c.status, c.effective_from,
                    e.full_name, e.birth_date
               FROM payroll_enforcement_cases c
               JOIN payroll_employees e
                 ON e.supplier_id = c.supplier_id AND e.id = c.employee_id
              WHERE c.supplier_id = ? AND c.id = ?'
        );
        $caseStmt->execute([$supplierId, $caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($case)) {
            throw new \OutOfBoundsException('Exekuční případ nebyl nalezen.');
        }
        $employeeId = (int) $case['employee_id'];
        $employmentStmt = $pdo->prepare(
            "SELECT id, end_date, relation_type,
                    status NOT IN ('ended', 'no_show', 'archived') AS running
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY running DESC, end_date DESC, id DESC"
        );
        $employmentStmt->execute([$supplierId, $employeeId]);
        $employments = $employmentStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($employments as $employment) {
            if ((int) $employment['running'] === 1) {
                throw new \DomainException(
                    'Zaměstnanec u vás stále pracuje v jiném vztahu — srážky pokračují '
                    . 'a oznámení podle § 295 odst. 2 o. s. ř. se nepodává.',
                );
            }
        }
        $ended = $employments[0] ?? null;
        if ($ended === null || $ended['end_date'] === null) {
            throw new \DomainException(
                'Zaměstnanci ještě neskončil pracovní poměr. Oznámení se vystavuje po skončení.',
            );
        }
        $endedOn = (string) $ended['end_date'];

        $claims = [];
        $totals = ['withheld' => 0, 'paid_out' => 0, 'held' => 0, 'administrator' => 0, 'remaining' => 0];
        foreach ($this->payments->settlementForCase($supplierId, $caseId) as $claim) {
            if (!$claim['is_active']) {
                continue;
            }
            $withheld = $claim['withheld_minor'];
            $paidOut = $claim['settled_minor'];
            $row = [
                'claim_id' => $claim['claim_id'],
                'category' => $claim['category'],
                'order_date' => $claim['priority_date'],
                'claim_minor' => $claim['original_minor'],
                'withheld_minor' => $withheld,
                'paid_out_minor' => $paidOut,
                'held_minor' => $claim['held_minor'],
                'administrator_minor' => $claim['administrator_minor'],
                'remaining_minor' => $claim['remaining_minor'],
            ];
            $claims[] = $row;
            $totals['withheld'] += $withheld;
            $totals['paid_out'] += $paidOut;
            $totals['held'] += $claim['held_minor'];
            $totals['administrator'] += $claim['administrator_minor'];
            $totals['remaining'] += $claim['remaining_minor'];
        }
        usort(
            $claims,
            static fn (array $a, array $b): int =>
                [$a['order_date'] ?? '9999-12-31', $a['claim_id']]
                <=> [$b['order_date'] ?? '9999-12-31', $b['claim_id']],
        );

        $parties = [];
        foreach ($this->facts->parties($supplierId, $caseId) as $party) {
            $role = (string) $party['party_role'];
            if (!isset($parties[$role])
                || (int) $party['revision_no'] > $parties[$role]['revision_no']
            ) {
                $parties[$role] = [
                    'revision_no' => (int) $party['revision_no'],
                    'name' => (string) $party['party_name'],
                    'reference' => $party['party_reference'] === null
                        ? null
                        : (string) $party['party_reference'],
                ];
            }
        }
        $authority = $parties['executor'] ?? $parties['court'] ?? null;
        if ($authority === null) {
            throw new \DomainException(
                'Případ nemá doložený soud ani exekutora (Právní fakta → strany). '
                . 'Bez adresáta oznámení vystavit nelze.',
            );
        }

        try {
            $employer = ($this->employers)($supplierId)->toArray();
        } catch (\Throwable $exception) {
            throw new \DomainException(
                'Údaje zaměstnavatele pro mzdové dokumenty nejsou úplné: '
                . $exception->getMessage(),
            );
        }
        $today = (string) $pdo->query('SELECT DATE(NOW())')->fetchColumn();
        $due = (new \DateTimeImmutable($endedOn))
            ->modify('+' . self::DUE_DAYS . ' days')
            ->format('Y-m-d');

        return [
            'schema' => self::SCHEMA,
            'issued_on' => $today,
            'due_on' => $due,
            'employer' => $employer,
            'employee' => [
                'id' => $employeeId,
                'name' => (string) $case['full_name'],
                'birth_date' => $case['birth_date'] === null ? null : (string) $case['birth_date'],
            ],
            'employment' => [
                'id' => (int) $ended['id'],
                'ended_on' => $endedOn,
                'relation_type' => (string) $ended['relation_type'],
            ],
            'case' => [
                'id' => $caseId,
                'kind' => (string) $case['case_kind'],
                'status' => (string) $case['status'],
                'effective_from' => (string) $case['effective_from'],
            ],
            'authority' => [
                'role' => isset($parties['executor']) ? 'executor' : 'court',
                'name' => $authority['name'],
                'reference' => $authority['reference'],
            ],
            'beneficiary' => isset($parties['beneficiary'])
                ? ['name' => $parties['beneficiary']['name'], 'reference' => $parties['beneficiary']['reference']]
                : null,
            'claims' => $claims,
            'totals' => [
                'withheld_minor' => $totals['withheld'],
                'paid_out_minor' => $totals['paid_out'],
                'held_minor' => $totals['held'],
                'administrator_minor' => $totals['administrator'],
                'remaining_minor' => $totals['remaining'],
            ],
            'new_payer' => $newPayerName === null
                ? null
                : ['name' => $newPayerName, 'reference' => $newPayerReference],
        ];
    }

    private static function optionalText(?string $value, int $max, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException("{$label} není platný údaj.");
        }

        return $value;
    }
}
