<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Bank;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\AccountingCorrectionRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Bank\BankRuleMatcher;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\Learning\RulePromotionService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\IpMatcher;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pravidla účtování opakovaných bankovních transakcí — REST API (mini-epic
 * AUTOMATIZACE, §5). Validace: existence účtů v osnově, 221 strana dle směru (R6),
 * saldokontní blacklist (H2), aspoň 1 kritérium, mode create vždy suggest.
 */
final class BankPostingRuleAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const SALDO_BLACKLIST = ['311', '321', '314', '324', '325'];
    private const BACKFILL_LIMIT = 200;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly BankPostingRuleRepository $rules,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly BankRuleMatcher $matcher,
        private readonly BankPostingService $service,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly RulePromotionService $promotion,
        private readonly AccountingCorrectionRepository $corrections,
        private readonly SupplierBankAccountRepository $bankAccounts,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $direction = in_array($q['direction'] ?? null, ['incoming', 'outgoing'], true) ? (string) $q['direction'] : null;
        $active = array_key_exists('active', $q) && $q['active'] !== '' ? (bool) filter_var($q['active'], FILTER_VALIDATE_BOOLEAN) : null;
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 50)));
        $result = $this->rules->paginateForTenant($supplierId, $direction, $active, $perPage, ($page - 1) * $perPage);
        $items = array_map(static fn (array $rule): array => $rule + [
            'promotion_candidate' => RulePromotionService::isCandidate($rule),
        ], $result['items']);
        return Json::ok($response, ['items' => $items, 'total' => $result['total'], 'page' => $page, 'per_page' => $perPage]);
    }

    public function promote(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        try {
            $rule = $this->promotion->promote($supplierId, (int) $args['id'], $this->userId($request));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        return Json::ok($response, ['rule' => $rule + ['promotion_candidate' => false]]);
    }

    public function demote(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        try {
            $rule = $this->promotion->demote($supplierId, (int) $args['id'], $this->userId($request), 'manual');
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        return Json::ok($response, ['rule' => $rule + ['promotion_candidate' => false]]);
    }

    public function history(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        $rule = $this->rules->find($supplierId, $id);
        if ($rule === null) return Json::error($response, 'not_found', 'Pravidlo nenalezeno.', 404);
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 25)));
        $result = $this->corrections->paginateForRule($supplierId, $id, $perPage, ($page - 1) * $perPage);
        $events = [];
        $corrections = [];
        foreach ($result['items'] as $row) {
            if ((string) $row['entity_type'] === 'bank_posting_rule') {
                $events[] = [
                    'id' => $row['id'], 'event_type' => $row['event_type'], 'reason' => $row['reason'],
                    'created_by_name' => $row['created_by_name'], 'created_at' => $row['created_at'],
                ];
            } else {
                $corrections[] = [
                    'id' => $row['id'], 'event_type' => $row['event_type'],
                    'suggested' => $this->pair($row['suggested_debit'], $row['suggested_credit']),
                    'final' => $this->pair($row['final_debit'], $row['final_credit']),
                    'amount' => $row['amount'], 'created_by_name' => $row['created_by_name'],
                    'created_at' => $row['created_at'],
                ];
            }
        }
        $stats = $this->corrections->statsForRule($supplierId, $id);
        $uses = (int) $rule['hit_count'] + $stats['reject_count'];
        return Json::ok($response, [
            'events' => $events,
            'corrections' => $corrections,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'stats' => [
                'hit_count' => (int) $rule['hit_count'], 'approved_streak' => (int) $rule['approved_streak'],
                'rejected_streak' => (int) $rule['rejected_streak'], 'override_count' => $stats['override_count'],
                'success_rate' => $uses === 0 ? 0.0 : round(max(0, (int) $rule['hit_count'] - $stats['override_count']) / $uses, 4),
            ],
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $data = $this->normalizeRule($supplierId, $body, true);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $ruleId = $this->rules->insert($supplierId, $data, $this->userId($request));
        $this->log($request, 'bank_rule.created', $ruleId, ['name' => $data['name']]);

        $backfilled = null;
        if (!empty($body['backfill_suggestions'])) {
            $backfilled = $this->backfill($supplierId, $ruleId, $data['direction'], $this->userId($request));
        }
        $rule = $this->rules->find($supplierId, $ruleId);
        return Json::ok($response, $backfilled === null ? ['rule' => $rule] : ['rule' => $rule, 'backfilled' => $backfilled], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        $existing = $this->rules->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Pravidlo nenalezeno.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $fields = $this->normalizeUpdate($supplierId, $existing, $body);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->rules->update($supplierId, $id, $fields);
        $this->log($request, 'bank_rule.updated', $id, array_keys($fields));

        // Sjednoceno s create: úprava kritérií pravidla umí přepočítat návrhy nad historií;
        // backfill přes suggestRuleForBackfill invaliduje starší pending návrhy dotčených pohybů
        // (P1.9), takže je nový návrh neblokuje.
        $backfilled = null;
        if (!empty($body['backfill_suggestions'])) {
            $backfilled = $this->backfill(
                $supplierId,
                $id,
                (string) ($fields['direction'] ?? $existing['direction']),
                $this->userId($request),
            );
        }
        $rule = $this->rules->find($supplierId, $id);
        return Json::ok($response, $backfilled === null ? $rule : $rule + ['backfilled' => $backfilled]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        if (!$this->rules->delete($supplierId, $id)) {
            return Json::error($response, 'not_found', 'Pravidlo nenalezeno.', 404);
        }
        $this->log($request, 'bank_rule.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /** Znovu použije konkrétní pravidlo na bezpečně vybranou historii; vždy jen návrhy. */
    public function backfillRule(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        $rule = $this->rules->find($supplierId, $id);
        if ($rule === null) {
            return Json::error($response, 'not_found', 'Pravidlo nenalezeno.', 404);
        }
        if (!(bool) $rule['is_active']) {
            return Json::error($response, 'rule_inactive', 'Nejprve pravidlo aktivujte.', 409);
        }

        $backfilled = $this->backfill(
            $supplierId,
            $id,
            (string) $rule['direction'],
            $this->userId($request),
        );
        $this->log($request, 'bank_rule.backfilled', $id, ['backfilled' => $backfilled]);
        return Json::ok($response, ['backfilled' => $backfilled]);
    }

    /** Read-only test pravidla nad historií (12 měsíců zpět). */
    public function dryRun(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $rule = $this->normalizeRule($supplierId, $body, true);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }

        $txs = $this->tenantTransactions($supplierId, $rule['direction']);
        $matched = 0;
        $alreadyPosted = 0;
        $sample = [];
        foreach ($txs as $tx) {
            if (!$this->matcher->matching($rule, [
                'amount'               => (float) $tx['amount'],
                'variable_symbol'      => $tx['variable_symbol'],
                'counterparty_account' => $tx['counterparty_account'],
                'counterparty_bank'    => $tx['counterparty_bank'],
                'description'          => $tx['description'],
                'counterparty_name'    => $tx['counterparty_name'],
            ])) {
                continue;
            }
            $matched++;
            $posted = (bool) $tx['already_posted'];
            if ($posted) {
                $alreadyPosted++;
            }
            if (count($sample) < 10) {
                $sample[] = [
                    'id'             => (int) $tx['id'],
                    'posted_at'      => (string) $tx['posted_at'],
                    'amount'         => (float) $tx['amount'],
                    'description'    => $tx['description'] !== null ? (string) $tx['description'] : null,
                    'already_posted' => $posted,
                ];
            }
        }
        return Json::ok($response, [
            'matched_count'        => $matched,
            'already_posted_count' => $alreadyPosted,
            'shadowed_by_own_transfer' => $rule['counterparty_account'] !== null
                && $this->bankAccounts->matchCounterparty(
                    $supplierId,
                    (string) $rule['counterparty_account'],
                    $rule['counterparty_bank'] !== null ? (string) $rule['counterparty_bank'] : null,
                ) !== null,
            'sample'               => $sample,
        ]);
    }

    // ── validace / normalizace ──────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalizeRule(int $supplierId, array $body, bool $isCreate): array
    {
        $direction = (string) ($body['direction'] ?? '');
        if (!in_array($direction, ['incoming', 'outgoing'], true)) {
            throw $this->err('rule_criteria_missing', 'Neplatný směr pravidla.');
        }
        $debit = trim((string) ($body['debit_account_code'] ?? ''));
        $credit = trim((string) ($body['credit_account_code'] ?? ''));
        $this->assertAccounts($supplierId, $direction, $debit, $credit);

        $account = self::nn($body['counterparty_account'] ?? null, static fn (string $v): string => AccountNumberNormalizer::normalize($v));
        $vs = self::nn($body['variable_symbol'] ?? null, static fn (string $v): string => VariableSymbolNormalizer::digits($v));
        $fragment = self::nn($body['message_contains'] ?? null, static fn (string $v): string => BankMessageNormalizer::normalize($v));
        $bank = self::nn($body['counterparty_bank'] ?? null);
        $prefix = self::nn($body['counterparty_prefix'] ?? null, static fn (string $v): string => ltrim(preg_replace('/\D/', '', $v) ?? '', '0'));
        if ($account === null && $bank === null && $prefix === null && $vs === null && $fragment === null) {
            throw $this->err('rule_criteria_missing', 'Pravidlo musí mít alespoň jedno kritérium.');
        }

        $priority = (int) ($body['priority'] ?? 100);
        if ($priority < 0 || $priority > 999) {
            throw $this->err('invalid_priority', 'Priorita musí být v rozsahu 0 až 999.');
        }
        $operationType = self::nn($body['operation_type'] ?? null);
        if ($operationType !== null && !in_array($operationType, OperationType::all(), true)) {
            throw $this->err('invalid_operation_type', 'Neplatný typ operace.');
        }
        $currency = strtoupper(self::nn($body['applies_currency'] ?? null) ?? 'CZK');
        $this->assertFxAccounts($direction, $debit, $credit, $currency);

        return [
            'name'                 => self::nn($body['name'] ?? null) ?? 'Pravidlo',
            'direction'            => $direction,
            'counterparty_account' => $account,
            'counterparty_bank'    => $bank,
            'counterparty_prefix'  => $prefix,
            'variable_symbol'      => $vs,
            'message_contains'     => $fragment,
            'amount_min'           => self::amount($body['amount_min'] ?? null),
            'amount_max'           => self::amount($body['amount_max'] ?? null),
            'debit_account_code'   => $debit,
            'credit_account_code'  => $credit,
            'description'          => self::nn($body['description'] ?? null),
            'mode'                 => 'suggest', // create vždy suggest (R7, H4e)
            'priority'             => $priority,
            'operation_type'       => $operationType,
            'auto_amount_cap'      => self::amount($body['auto_amount_cap'] ?? null),
            'applies_currency'     => $currency,
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalizeUpdate(int $supplierId, array $existing, array $body): array
    {
        $fields = [];
        if (array_key_exists('name', $body)) {
            $fields['name'] = self::nn($body['name']) ?? 'Pravidlo';
        }
        if (array_key_exists('is_active', $body)) {
            $fields['is_active'] = (bool) filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        // Kritéria / kontace / band — přepočet a validace jako u create, pokud přišly.
        foreach (['counterparty_account' => static fn (string $v): string => AccountNumberNormalizer::normalize($v),
                  'variable_symbol'      => static fn (string $v): string => VariableSymbolNormalizer::digits($v),
                  'message_contains'     => static fn (string $v): string => BankMessageNormalizer::normalize($v)] as $key => $norm) {
            if (array_key_exists($key, $body)) {
                $fields[$key] = self::nn($body[$key], $norm);
            }
        }
        if (array_key_exists('counterparty_bank', $body)) {
            $fields['counterparty_bank'] = self::nn($body['counterparty_bank']);
        }
        if (array_key_exists('counterparty_prefix', $body)) {
            $fields['counterparty_prefix'] = self::nn($body['counterparty_prefix'], static fn (string $v): string => ltrim(preg_replace('/\D/', '', $v) ?? '', '0'));
        }
        if (array_key_exists('description', $body)) {
            $fields['description'] = self::nn($body['description']);
        }
        foreach (['amount_min', 'amount_max', 'auto_amount_cap'] as $key) {
            if (array_key_exists($key, $body)) {
                $fields[$key] = self::amount($body[$key]);
            }
        }
        $direction = array_key_exists('direction', $body) && in_array($body['direction'], ['incoming', 'outgoing'], true)
            ? (string) $body['direction'] : (string) $existing['direction'];
        if (array_key_exists('direction', $body)) {
            $fields['direction'] = $direction;
        }
        $debit = trim((string) ($body['debit_account_code'] ?? $existing['debit_account_code']));
        $credit = trim((string) ($body['credit_account_code'] ?? $existing['credit_account_code']));
        if (array_key_exists('direction', $body)
            || array_key_exists('debit_account_code', $body)
            || array_key_exists('credit_account_code', $body)) {
            $this->assertAccounts($supplierId, $direction, $debit, $credit);
        }
        if (array_key_exists('debit_account_code', $body) || array_key_exists('credit_account_code', $body)) {
            $fields['debit_account_code'] = $debit;
            $fields['credit_account_code'] = $credit;
        }
        if (array_key_exists('priority', $body)) {
            $priority = (int) $body['priority'];
            if ($priority < 0 || $priority > 999) {
                throw $this->err('invalid_priority', 'Priorita musí být v rozsahu 0 až 999.');
            }
            $fields['priority'] = $priority;
        }
        if (array_key_exists('operation_type', $body)) {
            $operation = self::nn($body['operation_type']);
            if ($operation !== null && !in_array($operation, OperationType::all(), true)) {
                throw $this->err('invalid_operation_type', 'Neplatný typ operace.');
            }
            $fields['operation_type'] = $operation;
        }
        if (array_key_exists('applies_currency', $body)) {
            $fields['applies_currency'] = strtoupper(self::nn($body['applies_currency']) ?? 'CZK');
        }
        $this->assertFxAccounts(
            $direction,
            (string) ($fields['debit_account_code'] ?? $existing['debit_account_code']),
            (string) ($fields['credit_account_code'] ?? $existing['credit_account_code']),
            (string) ($fields['applies_currency'] ?? $existing['applies_currency'] ?? 'CZK'),
        );

        // Režim se mění výhradně auditovatelnými promote/demote endpointy.
        if (array_key_exists('mode', $body) && in_array($body['mode'], ['suggest', 'auto'], true)) {
            $mode = (string) $body['mode'];
            if ($mode !== (string) $existing['mode']) {
                throw new \MyInvoice\Service\Accounting\PostingException(
                    'rule_promotion_required',
                    'Režim pravidla změňte samostatnou akcí Povýšit nebo Jen návrhy.',
                    409,
                );
            }
        }

        return $fields;
    }

    private function assertAccounts(int $supplierId, string $direction, string $debit, string $credit): void
    {
        $map = $this->accounts->codeToIdMap($supplierId);
        foreach ([$debit, $credit] as $code) {
            if ($code === '' || !isset($map[$code])) {
                throw $this->err('account_not_found', 'Účet ' . $code . ' není v účtové osnově.');
            }
        }
        // R6: bankovní strana musí být 221* dle směru.
        $bankSide = $direction === 'incoming' ? $debit : $credit;
        if (!str_starts_with($bankSide, '221')) {
            throw $this->err('rule_bank_side_required', 'Bankovní strana musí být účet 221 dle směru platby.');
        }
        // H2: ne-bankovní strana nesmí být saldokontní.
        $nonBank = $direction === 'incoming' ? $credit : $debit;
        foreach (self::SALDO_BLACKLIST as $prefix) {
            if (str_starts_with($nonBank, $prefix)) {
                throw $this->err('rule_saldo_forbidden', 'Platby faktur se párují, ne účtují pravidlem.');
            }
        }
    }

    private function assertFxAccounts(string $direction, string $debit, string $credit, string $currency): void
    {
        if ($currency === 'CZK') {
            return;
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw $this->err('invalid_currency', 'Neplatný kód měny.');
        }
        $nonBank = $direction === 'incoming' ? $credit : $debit;
        // 221* = vlastní účet/analytika (běžný účet → termínovaný vklad): převod mezi vlastními
        // cizoměnovými účty, ne výsledková operace. Cizoměnová pozice na 221* nezůstává viset —
        // uzávěrka ji přeceňuje. Musí souhlasit s BankPostingService::assertFxResultAccounts(),
        // jinak by UI pustilo pravidlo, které engine při účtování odmítne.
        if (str_starts_with($nonBank, '221')) {
            return;
        }
        if (!str_starts_with($nonBank, '5') && !str_starts_with($nonBank, '6')) {
            throw $this->err('fx_rule_account_forbidden', 'Cizoměnové pravidlo smí účtovat jen na výsledkový účet 5xx/6xx nebo vlastní účet 221.');
        }
    }

    // ── backfill / dry-run data ─────────────────────────────────────────────────

    /** Suggest degradace nad historickými unmatched nezaúčtovanými tx (limit 200, otevřená období). */
    private function backfill(int $supplierId, int $ruleId, string $direction, ?int $userId): int
    {
        $sign = $direction === 'incoming' ? '> 0' : '< 0';
        $n = 0;
        $lastId = PHP_INT_MAX;
        do {
            $stmt = $this->db->pdo()->prepare(
                "SELECT bt.id FROM bank_transactions bt
                   JOIN bank_statements bs ON bs.id = bt.statement_id
                   JOIN accounting_periods p ON p.supplier_id = ? AND bt.posted_at BETWEEN p.starts_on AND p.ends_on AND p.status = 'open'
                  WHERE bt.source = 'statement' AND bt.amount {$sign} AND bt.match_status = 'unmatched'
                    AND bs.supplier_id = ? AND bt.id < ?
                    AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.supplier_id = ?
                                      AND je.source_type = 'bank' AND je.source_id = bt.id AND je.reversed_by IS NULL)
                    AND " . $this->ownsStatementSql('bs') . "
                  ORDER BY bt.id DESC
                  LIMIT 500"
            );
            // p.supplier_id, bs.supplier_id, lastId, je.supplier_id, resolver (2×)
            $stmt->execute(array_merge(
                [$supplierId, $supplierId, $lastId, $supplierId],
                \MyInvoice\Repository\BankStatementOwnershipResolver::params($supplierId),
            ));
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            foreach ($ids as $txId) {
                $res = $this->service->suggestRuleForBackfill($supplierId, $txId, $ruleId, $userId);
                if (($res['action'] ?? '') === 'suggested' && ($res['created'] ?? false) && ++$n >= self::BACKFILL_LIMIT) {
                    break 2;
                }
            }
            if ($ids !== []) $lastId = $ids[array_key_last($ids)];
        } while (count($ids) === 500);
        return $n;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function tenantTransactions(int $supplierId, string $direction): array
    {
        $sign = $direction === 'incoming' ? '> 0' : '< 0';
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.posted_at, bt.amount, bt.variable_symbol, bt.counterparty_account,
                    bt.counterparty_bank, bt.description, bt.counterparty_name,
                    EXISTS (SELECT 1 FROM journal_entries je WHERE je.supplier_id = ?
                              AND je.source_type = 'bank' AND je.source_id = bt.id AND je.reversed_by IS NULL) AS already_posted
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.source = 'statement' AND bt.amount {$sign}
                AND bt.posted_at >= (CURDATE() - INTERVAL 12 MONTH)
                AND " . $this->ownsStatementSql('bs') . "
              ORDER BY bt.posted_at DESC
              LIMIT 2000"
        );
        // je.supplier_id, resolver (2×)
        $stmt->execute(array_merge(
            [$supplierId],
            \MyInvoice\Repository\BankStatementOwnershipResolver::params($supplierId),
        ));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Podmínka vlastnictví výpisu supplierem. SEC-01: deleguje na
     * {@see \MyInvoice\Repository\BankStatementOwnershipResolver}, váže tedy
     * {@see BankStatementOwnershipResolver::PARAM_COUNT} (= 2) placeholderů —
     * hodnoty dodá ::params().
     */
    private function ownsStatementSql(string $bsAlias): string
    {
        return \MyInvoice\Repository\BankStatementOwnershipResolver::sql($bsAlias);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private static function nn(mixed $v, ?callable $transform = null): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $s = $transform !== null ? $transform($s) : $s;
        return $s === '' ? null : $s;
    }

    private static function amount(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        return round((float) $v, 2);
    }

    private function pair(mixed $debit, mixed $credit): ?string
    {
        return $debit === null || $credit === null ? null : (string) $debit . '/' . (string) $credit;
    }

    private function err(string $code, string $message): \MyInvoice\Service\Accounting\PostingException
    {
        return new \MyInvoice\Service\Accounting\PostingException($code, $message, 422);
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'bank_posting_rule', $id, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
