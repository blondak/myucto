<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice\Approval;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceApprovalRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceReceiver;

/**
 * Schvalování přijatých dokladů manažerem střediska (F6) — jediné místo pravidel.
 *
 * Každá cesta, která převádí přijatý doklad z konceptu do stavu `received`, se
 * nejdřív zeptá {@see gateReceive()}. Vyžaduje-li doklad schválení, které ještě
 * nemá, brána místo přijetí založí kolo (e-mail schvalovatelům) a doklad zůstane
 * konceptem — mimo platební příkazy, náklady, evidenci DPH i účtování. Po
 * schválení všemi ho {@see decide()} přijme přes {@see PurchaseInvoiceReceiver},
 * tedy stejnými kroky jako ruční přechod (auto-zaúčtování, karta, majetek,
 * pokladna).
 *
 * Kolo a změny: schválení platí pro hodnotu dimenze a částku. Změní-li se po
 * schválení středisko nebo částka, další pokus o přijetí založí nové kolo jen pro
 * dotčenou hodnotu; ostatní schválení zůstávají.
 *
 * Bez typu dimenze s `requires_approval` vrací brána vždy „přijmout" a nic nezapisuje.
 */
final class PurchaseInvoiceApprovalService
{
    public const TOKEN_TTL_DAYS = 14;
    public const COMMENT_MAX = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceApprovalRepository $approvals,
        private readonly PurchaseApprovalRequirements $requirements,
        private readonly PurchaseInvoiceReceiver $receiver,
        private readonly PurchaseApprovalMailer $mailer,
        private readonly ActivityLogger $logger,
    ) {}

    public function enabledFor(int $supplierId): bool
    {
        return $this->requirements->approvalTypes($supplierId) !== [];
    }

    /**
     * Brána přechodu koncept → přijato.
     *
     * @return array{approval_requested:true, approval_status:string, approvals:list<array<string,mixed>>}|null
     *         null = doklad smí být přijat (schválení nevyžaduje nebo ho už má)
     * @throws PurchaseApprovalException approval_no_approver — hodnota bez schvalovatele
     */
    public function gateReceive(int $supplierId, int $invoiceId, ?int $userId, ?string $ip = null, ?string $userAgent = null): ?array
    {
        $result = $this->evaluate($supplierId, $invoiceId, $userId, $ip, $userAgent);
        if ($result['state'] === 'clear') {
            return null;
        }
        return [
            'approval_requested' => true,
            'approval_status' => 'pending',
            'approvals' => array_map([self::class, 'serialize'], $this->activeRows($supplierId, $invoiceId)),
        ];
    }

    /**
     * Ruční odeslání ke schválení (po zamítnutí nebo úpravě). Doklad nepřijímá.
     *
     * @return array<string,mixed> tvar {@see overview()}
     */
    public function request(int $supplierId, int $invoiceId, ?int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $this->requireDraft($supplierId, $invoiceId);
        $result = $this->evaluate($supplierId, $invoiceId, $userId, $ip, $userAgent);
        if ($result['state'] === 'clear' && $result['required'] === []) {
            throw new PurchaseApprovalException('approval_not_required', 'Doklad schválení nevyžaduje.', 422);
        }
        return $this->overview($supplierId, $invoiceId);
    }

    /**
     * Zruší čekající kolo. Doklad zůstane konceptem se stavem `none`.
     *
     * @return array<string,mixed> tvar {@see overview()}
     */
    public function cancel(int $supplierId, int $invoiceId, ?int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $this->requireDraft($supplierId, $invoiceId);
        $cancelled = $this->approvals->cancelPending($supplierId, $invoiceId);
        $this->approvals->setInvoiceStatus($supplierId, $invoiceId, 'none');
        if ($cancelled > 0) {
            $this->logger->log('purchase_invoice.approval_cancelled', $userId, 'purchase_invoice', $invoiceId,
                ['cancelled' => $cancelled], $ip, $userAgent);
        }
        return $this->overview($supplierId, $invoiceId);
    }

    /**
     * Doklad odchází z konceptu jinudy (storno, smazání) — čekající schválení
     * ztrácí smysl. Bez schvalování nic nedělá.
     */
    public function onInvoiceLeftDraft(int $supplierId, int $invoiceId, ?int $userId, string $reason): void
    {
        $cancelled = $this->approvals->cancelPending($supplierId, $invoiceId);
        if ($cancelled > 0) {
            $this->approvals->setInvoiceStatus($supplierId, $invoiceId, 'none');
            $this->logger->log('purchase_invoice.approval_cancelled', $userId, 'purchase_invoice', $invoiceId,
                ['cancelled' => $cancelled, 'reason' => $reason]);
        }
    }

    /**
     * Rozhodnutí schvalovatele (v aplikaci i z e-mailu).
     *
     * @param 'approve'|'reject' $decision
     * @param 'app'|'email' $via
     * @param int|null $actorUserId u `app` přihlášený uživatel — musí být schvalovatelem řádku
     * @return array<string,mixed> ApprovalRow + invoice_approval_status
     */
    public function decide(
        int $supplierId,
        int $approvalId,
        string $decision,
        ?string $comment,
        string $via,
        ?int $actorUserId,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new PurchaseApprovalException('invalid_decision', 'Rozhodnutí musí být approve nebo reject.', 422);
        }
        $comment = $comment !== null ? trim($comment) : '';
        if ($decision === 'reject' && $comment === '') {
            throw new PurchaseApprovalException('reason_required', 'Důvod zamítnutí je povinný.', 422);
        }
        if (mb_strlen($comment) > self::COMMENT_MAX) {
            $comment = mb_substr($comment, 0, self::COMMENT_MAX);
        }

        $row = $this->approvals->find($supplierId, $approvalId);
        if ($row === null) {
            throw new PurchaseApprovalException('not_found', 'Schválení nenalezeno.', 404);
        }
        if ($via === 'app' && (int) $row['approver_user_id'] !== (int) $actorUserId) {
            // Cizí schválení — vědomě 404, ať se z odpovědi nedá poznat, že existuje.
            throw new PurchaseApprovalException('not_found', 'Schválení nenalezeno.', 404);
        }
        if ((string) $row['status'] !== 'pending') {
            throw new PurchaseApprovalException('approval_already_decided', 'O tomto schválení už bylo rozhodnuto.', 409);
        }
        if ((string) $row['invoice_status'] !== 'draft') {
            throw new PurchaseApprovalException('approval_closed', 'Doklad už není koncept — schválení není potřeba.', 409);
        }

        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $invoiceId = (int) $row['purchase_invoice_id'];
        if (!$this->approvals->decideIfPending($supplierId, $approvalId, $status, $via, $comment !== '' ? $comment : null)) {
            throw new PurchaseApprovalException('approval_already_decided', 'O tomto schválení už bylo rozhodnuto.', 409);
        }
        $logUser = $actorUserId ?? (int) $row['approver_user_id'];
        $this->logger->log('purchase_invoice.approval_' . $status, $logUser, 'purchase_invoice', $invoiceId, [
            'approval_id' => $approvalId,
            'round' => (int) $row['round'],
            'dimension_value_id' => (int) $row['dimension_value_id'],
            'amount_czk' => (float) $row['amount_czk'],
            'via' => $via,
            'comment' => $comment !== '' ? $comment : null,
        ], $ip, $userAgent);

        if ($status === 'rejected') {
            // Zamítnutý doklad se dál neschvaluje — ostatní čekající schválení téhož
            // dokladu by jinak mohla „projít" doklad, o kterém už padlo ne.
            $this->approvals->cancelPending($supplierId, $invoiceId);
            $this->approvals->setInvoiceStatus($supplierId, $invoiceId, 'rejected');
        } elseif ($this->approvals->pendingCount($supplierId, $invoiceId) === 0) {
            $this->complete($supplierId, $invoiceId, $actorUserId ?? ($row['requested_by'] !== null ? (int) $row['requested_by'] : null), $ip, $userAgent);
        }

        $fresh = (array) $this->approvals->find($supplierId, $approvalId);
        return self::serialize($fresh) + ['invoice_approval_status' => (string) $fresh['invoice_approval_status']];
    }

    /**
     * Pošle připomínku hned (nový odkaz — starý tím přestane platit).
     */
    public function remind(int $supplierId, int $approvalId, ?int $userId, bool $manual = true): void
    {
        $row = $this->approvals->find($supplierId, $approvalId);
        if ($row === null) {
            throw new PurchaseApprovalException('not_found', 'Schválení nenalezeno.', 404);
        }
        if ((string) $row['status'] !== 'pending' || (string) $row['invoice_status'] !== 'draft') {
            throw new PurchaseApprovalException('approval_already_decided', 'Schválení už nečeká na rozhodnutí.', 409);
        }
        [$token, $hash, $expires] = self::newToken();
        if (!$this->approvals->rotateToken($supplierId, $approvalId, $hash, $expires, true)) {
            throw new PurchaseApprovalException('approval_already_decided', 'Schválení už nečeká na rozhodnutí.', 409);
        }
        $row['token_expires_at'] = $expires;
        $this->mailer->send($row, $token, true);
        $this->logger->log('purchase_invoice.approval_reminder_sent', $userId, 'purchase_invoice',
            (int) $row['purchase_invoice_id'], [
                'approval_id' => $approvalId,
                'reminder_n' => ((int) $row['reminders_sent']) + 1,
                'manual' => $manual,
            ]);
    }

    /**
     * Stav schvalování dokladu pro detail: všechna kola, zda je schválení potřeba
     * a co by se schvalovalo teď.
     *
     * @return array{data:list<array<string,mixed>>, required:bool, requirements:list<array<string,mixed>>}
     */
    public function overview(int $supplierId, int $invoiceId): array
    {
        $required = $this->requirements->forInvoice($supplierId, $invoiceId);
        return [
            'data' => array_map([self::class, 'serialize'], $this->approvals->forInvoice($supplierId, $invoiceId)),
            'required' => $required !== [],
            'requirements' => array_map(static fn (array $r): array => [
                'dimension_value' => [
                    'id' => $r['value_id'],
                    'code' => $r['value_code'],
                    'name' => $r['value_name'],
                    'type_id' => $r['type_id'],
                    'type_name' => $r['type_name'],
                ],
                'approver' => $r['approver_user_id'] !== null
                    ? ['id' => $r['approver_user_id'], 'name' => $r['approver_name'], 'email' => $r['approver_email']]
                    : null,
                'amount_czk' => $r['amount_czk'],
            ], $required),
        ];
    }

    /**
     * Veřejný (e-mailový) pohled na schválení podle tokenu.
     *
     * @return array<string,mixed>|null null = token neznámý
     */
    public function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = $this->approvals->findByTokenHash($hash);
        if ($row === null || !hash_equals((string) $row['token_hash'], $hash)) {
            return null;
        }
        return $row;
    }

    public static function isExpired(array $row): bool
    {
        $exp = (string) ($row['token_expires_at'] ?? '');
        return $exp !== '' && strtotime($exp) !== false && strtotime($exp) < time();
    }

    /**
     * Řádek schválení ve tvaru API smlouvy (ApprovalRow).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function serialize(array $row): array
    {
        $number = (string) (($row['invoice_vendor_number'] ?? '') ?: ($row['invoice_varsymbol'] ?? ''));
        return [
            'id' => (int) $row['id'],
            'purchase_invoice_id' => (int) $row['purchase_invoice_id'],
            'round' => (int) $row['round'],
            'status' => (string) $row['status'],
            'amount_czk' => (float) $row['amount_czk'],
            'dimension_value' => [
                'id' => (int) $row['dimension_value_id'],
                'code' => (string) $row['dimension_value_code'],
                'name' => (string) $row['dimension_value_name'],
                'type_name' => (string) $row['dimension_type_name'],
            ],
            'approver' => [
                'id' => (int) $row['approver_user_id'],
                'name' => (string) (($row['approver_name'] ?? '') ?: ($row['approver_email'] ?? '')),
                'email' => (string) ($row['approver_email'] ?? ''),
            ],
            'requested_at' => $row['requested_at'] ?? null,
            'decided_at' => $row['decided_at'] ?? null,
            'decided_via' => $row['decided_via'] ?? null,
            'comment' => $row['comment'] ?? null,
            'reminders_sent' => (int) ($row['reminders_sent'] ?? 0),
            'invoice' => [
                'id' => (int) $row['purchase_invoice_id'],
                'document_number' => $number !== '' ? $number : null,
                'supplier_name' => (string) ($row['invoice_vendor_name'] ?? ''),
                'issue_date' => $row['invoice_issue_date'] ?? null,
                'due_date' => $row['invoice_due_date'] ?? null,
                'total_without_vat' => (float) ($row['invoice_total_without_vat'] ?? 0),
                'total_with_vat' => (float) ($row['invoice_total_with_vat'] ?? 0),
                'currency' => (string) ($row['invoice_currency'] ?? 'CZK'),
                'has_pdf' => (string) ($row['invoice_pdf_path'] ?? '') !== '',
                'status' => (string) ($row['invoice_status'] ?? ''),
                'approval_status' => (string) ($row['invoice_approval_status'] ?? 'none'),
            ],
        ];
    }

    /** @return array{0:string,1:string,2:string} token, jeho SHA-256, platnost do */
    public static function newToken(): array
    {
        $token = bin2hex(random_bytes(32));
        return [
            $token,
            hash('sha256', $token),
            (new \DateTimeImmutable('+' . self::TOKEN_TTL_DAYS . ' days'))->format('Y-m-d H:i:s'),
        ];
    }

    // ── interní ──────────────────────────────────────────────────────────────

    /**
     * Porovná, co doklad potřebuje, s tím, co už má, a doplní chybějící kolo.
     *
     * @return array{state:'clear'|'pending', required:list<array<string,mixed>>}
     */
    private function evaluate(int $supplierId, int $invoiceId, ?int $userId, ?string $ip, ?string $userAgent): array
    {
        $required = $this->requirements->forInvoice($supplierId, $invoiceId);
        $rows = $this->approvals->forInvoice($supplierId, $invoiceId);

        // Nejnovější platný (nezrušený) řádek pro každou hodnotu.
        $latest = [];
        foreach ($rows as $r) {
            if ((string) $r['status'] === 'cancelled') {
                continue;
            }
            $valueId = (int) $r['dimension_value_id'];
            $latest[$valueId] ??= $r;
        }

        if ($required === []) {
            // Doklad schválení (už) nepotřebuje — vypnutá konfigurace, pod limitem, jiné
            // středisko. Visící čekání zrušíme, ať po dokladu nezůstane mrtvé kolo.
            $cancelled = $this->approvals->cancelPending($supplierId, $invoiceId);
            if ($cancelled > 0) {
                $this->approvals->setInvoiceStatus($supplierId, $invoiceId, 'none');
            }
            return ['state' => 'clear', 'required' => []];
        }

        $missing = array_values(array_filter($required, static fn (array $r): bool => $r['approver_user_id'] === null));
        if ($missing !== []) {
            throw new PurchaseApprovalException(
                'approval_no_approver',
                'Hodnota dimenze ' . implode(', ', array_map(static fn (array $r): string => $r['value_code'] . ' ' . $r['value_name'], $missing))
                    . ' nemá odpovědnou osobu — doklad nejde odeslat ke schválení. Doplňte ji ve Firma → Dimenze.',
                422,
                ['dimension_values' => array_map(static fn (array $r): array => [
                    'id' => $r['value_id'], 'code' => $r['value_code'], 'name' => $r['value_name'],
                ], $missing)],
            );
        }

        $toCreate = [];
        $toCancel = [];
        $pending = 0;
        $requiredValues = [];
        foreach ($required as $req) {
            $valueId = (int) $req['value_id'];
            $requiredValues[$valueId] = true;
            $have = $latest[$valueId] ?? null;
            $sameAmount = $have !== null && abs((float) $have['amount_czk'] - (float) $req['amount_czk']) < 0.005;
            if ($have !== null && $sameAmount && (string) $have['status'] === 'approved') {
                continue;
            }
            if ($have !== null && $sameAmount && (string) $have['status'] === 'pending'
                && (int) $have['approver_user_id'] === (int) $req['approver_user_id']) {
                $pending++;
                continue;
            }
            if ($have !== null && (string) $have['status'] === 'pending') {
                $toCancel[] = (int) $have['id'];
            }
            $toCreate[] = $req;
        }
        foreach ($latest as $valueId => $r) {
            if (!isset($requiredValues[$valueId]) && (string) $r['status'] === 'pending') {
                $toCancel[] = (int) $r['id'];
            }
        }

        if ($toCreate === [] && $pending === 0) {
            $this->approvals->cancelPending($supplierId, $invoiceId, $toCancel);
            $this->approvals->setInvoiceStatus($supplierId, $invoiceId, 'approved');
            return ['state' => 'clear', 'required' => $required];
        }

        $created = [];
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $this->approvals->cancelPending($supplierId, $invoiceId, $toCancel);
            if ($toCreate !== []) {
                $round = $this->approvals->maxRound($supplierId, $invoiceId) + 1;
                foreach ($toCreate as $req) {
                    [$token, $hash, $expires] = self::newToken();
                    $id = $this->approvals->create(
                        $supplierId, $invoiceId, (int) $req['value_id'], (int) $req['approver_user_id'],
                        $round, (float) $req['amount_czk'], $hash, $expires, $userId,
                    );
                    $created[] = [$id, $token];
                }
            }
            $this->approvals->setInvoiceStatus($supplierId, $invoiceId, 'pending');
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($created as [$id, $token]) {
            $row = $this->approvals->find($supplierId, $id);
            if ($row === null) {
                continue;
            }
            $this->logger->log('purchase_invoice.approval_requested', $userId, 'purchase_invoice', $invoiceId, [
                'approval_id' => $id,
                'round' => (int) $row['round'],
                'dimension_value_id' => (int) $row['dimension_value_id'],
                'approver_user_id' => (int) $row['approver_user_id'],
                'amount_czk' => (float) $row['amount_czk'],
            ], $ip, $userAgent);
            try {
                $this->mailer->send($row, $token, false);
            } catch (\Throwable $e) {
                // Kolo vzniklo; schvalovatel ho najde v aplikaci a účetní může poslat
                // připomínku. Selhání pošty nesmí shodit přechod dokladu.
                $this->logger->log('purchase_invoice.approval_mail_failed', $userId, 'purchase_invoice', $invoiceId, [
                    'approval_id' => $id,
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ], $ip, $userAgent);
            }
        }
        return ['state' => 'pending', 'required' => $required];
    }

    /**
     * Všechna čekající schválení dokladu padla kladně. Ověří znovu, že se doklad
     * mezitím nezměnil (jinak brána založí nové kolo), a přijme ho.
     */
    private function complete(int $supplierId, int $invoiceId, ?int $userId, ?string $ip, ?string $userAgent): void
    {
        try {
            $result = $this->evaluate($supplierId, $invoiceId, $userId, $ip, $userAgent);
        } catch (PurchaseApprovalException $e) {
            // Středisku mezitím zmizel schvalovatel — doklad zůstane konceptem,
            // účetní ho uvidí a vyřeší.
            $this->approvals->setInvoiceStatus($supplierId, $invoiceId, 'pending');
            $this->logger->log('purchase_invoice.approval_receive_failed', $userId, 'purchase_invoice', $invoiceId,
                ['error' => $e->errorCode], $ip, $userAgent);
            return;
        }
        if ($result['state'] !== 'clear') {
            return;
        }
        $this->approvals->setInvoiceStatus($supplierId, $invoiceId, 'approved');
        try {
            $this->receiver->receiveDraft($supplierId, $invoiceId, $userId, $ip, $userAgent, ['via' => 'approval']);
        } catch (\Throwable $e) {
            // Schválení platí; doklad přijme účetní ručně (brána ho pustí, kolo je schválené).
            $this->logger->log('purchase_invoice.approval_receive_failed', $userId, 'purchase_invoice', $invoiceId,
                ['error' => mb_substr($e->getMessage(), 0, 500)], $ip, $userAgent);
        }
    }

    /** @return list<array<string,mixed>> nejnovější nezrušený řádek každé hodnoty dimenze */
    private function activeRows(int $supplierId, int $invoiceId): array
    {
        $out = [];
        foreach ($this->approvals->forInvoice($supplierId, $invoiceId) as $r) {
            if ((string) $r['status'] !== 'cancelled') {
                $out[(int) $r['dimension_value_id']] ??= $r;
            }
        }
        return array_values($out);
    }

    private function requireDraft(int $supplierId, int $invoiceId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            throw new PurchaseApprovalException('not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if ((string) $status !== 'draft') {
            throw new PurchaseApprovalException('invalid_state', 'Schvalovat lze jen koncept dokladu.', 409);
        }
    }
}
