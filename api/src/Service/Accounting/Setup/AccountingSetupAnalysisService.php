<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Setup;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSetupRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ExpenseKeywordCatalogRepository;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Automation\RuleProposalService;
use PDO;

final class AccountingSetupAnalysisService
{
    /** Pravidlo z historie předbíhá katalog (100) i AI (90); nižší číslo = dřív. */
    private const HISTORY_RULE_PRIORITY = 40;

    public function __construct(
        private readonly Connection $db,
        private readonly ImportJobRepository $jobs,
        private readonly AccountingSetupRepository $setup,
        private readonly ChartOfAccountsRepository $chart,
        private readonly PostingRuleRepository $postingRules,
        private readonly ExpenseClassificationRuleRepository $expenseRules,
        private readonly ExpenseKeywordCatalogRepository $catalog,
        private readonly ExpenseClassificationService $classification,
        private readonly RuleProposalService $bankProposals,
        private readonly AccountingSetupAiSampleBuilder $aiSamples,
        private readonly AccountingSetupAiEnricherInterface $aiEnricher,
    ) {}

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || $job['source'] !== 'accounting_setup_analysis' || !$this->jobs->markRunning($jobId)) {
            return;
        }
        $supplierId = (int) $job['supplier_id'];
        $params = (array) ($job['params'] ?? []);
        $catalogVersion = $this->catalog->latestVersion();
        $run = $this->setup->runByJob($jobId);
        $runId = $run === null
            ? $this->setup->createRun($supplierId, $jobId, $params, $catalogVersion, (int) $job['created_by'])
            : (int) $run['id'];

        try {
            $rows = $this->items($supplierId, $params);
            $this->jobs->updateProgress($jobId, ['total_items' => count($rows), 'current_step' => 'purchase_invoices']);
            $catalog = $this->catalog->active($catalogVersion);
            $activeExpenseRules = $this->expenseRules->activeFor($supplierId);
            $chart = $this->chartState($supplierId);
            $fixedAssetAnalytic = $this->analyticForGroup($chart, 'fixed_asset', 'fixed_asset');
            $fixedAssetCount = 0;
            $fixedAssetAmount = 0.0;
            $groups = [];
            $unclassified = 0;
            $storedScorable = 0;
            $storedAgreement = 0;
            $accountScorable = 0;
            $accountAgreement = 0;
            $hashRows = [];
            $created = 0;
            $unclassifiedRows = [];

            $this->jobs->updateProgress($jobId, ['current_step' => 'posting_history']);
            $history = $this->historyRules($runId, $supplierId, $params, $rows, $chart, $activeExpenseRules);
            $created += $history['created'];
            $historyCovered = $history['covered_item_ids'];
            $this->jobs->updateProgress($jobId, ['current_step' => 'purchase_invoices']);

            foreach ($rows as $index => $row) {
                if ($this->jobs->isCancelRequested($jobId)) {
                    $this->jobs->markCancelled($jobId);
                    return;
                }
                $coveredByHistory = isset($historyCovered[(int) $row['id']]);
                $description = (string) $row['description'];
                $normalized = BankMessageNormalizer::normalizeKeepDigits($description);
                $matched = self::matchCatalog($normalized, $catalog);
                $year = (int) $row['acq_year'];
                $unitPriceCzk = abs((float) $row['unit_price_without_vat']) * self::fxRate($row['exchange_rate']);
                $suggestion = $this->classification->suggestForItem(
                    $supplierId,
                    $description,
                    $row['vendor_name'] !== null ? (string) $row['vendor_name'] : null,
                    $row['vendor_id'] !== null ? (int) $row['vendor_id'] : null,
                    $unitPriceCzk,
                    $year,
                );

                if ($suggestion === null && !$coveredByHistory) {
                    $unclassified++;
                    $unclassifiedRows[] = $row;
                }
                if ($suggestion !== null && $row['expense_kind'] !== null) {
                    $storedScorable++;
                    if ($suggestion->kind->value === (string) $row['expense_kind']) {
                        $storedAgreement++;
                    }
                }
                if ($suggestion !== null && $row['historical_account'] !== null) {
                    $accountScorable++;
                    $suggestedAccount = $suggestion->accountCode ?? $suggestion->kind->fallbackAccount();
                    if (self::sameSynthetic($suggestedAccount, (string) $row['historical_account'])) {
                        $accountAgreement++;
                    }
                }

                // Položku pokrývá pravidlo ze zaúčtované historie: katalogový odhad by mu
                // jen konkuroval (a kandidát na majetek u něčeho, co se roky účtovalo do
                // nákladů, je šum).
                if ($matched !== null && $suggestion !== null && !$coveredByHistory) {
                    $ruleKind = self::baseRuleKind($matched, $suggestion->kind->value);
                    $groupVendorId = self::groupingVendorId($ruleKind, $row['vendor_id']);
                    $key = implode('|', [
                        (string) ($groupVendorId ?? 0), $matched['locale'], $matched['concept_key'],
                        $matched['phrase'], $ruleKind, (string) ($suggestion->accountCode ?? ''),
                    ]);
                    $groups[$key] ??= [
                        'vendor_id' => $groupVendorId,
                        'vendor_name' => $groupVendorId !== null && $row['vendor_name'] !== null
                            ? (string) $row['vendor_name'] : null,
                        'locale' => (string) $matched['locale'],
                        'concept' => (string) $matched['concept_key'],
                        'phrase' => (string) $matched['phrase'],
                        'kind' => $ruleKind,
                        'account' => $ruleKind === 'fixed_asset'
                            ? null
                            : $this->activeAccountOrNull($supplierId, $suggestion->accountCode),
                        'confidence' => $suggestion->confidence,
                        'count' => 0,
                        'amount' => 0.0,
                        'samples' => [],
                    ];
                    $groups[$key]['count']++;
                    $groups[$key]['amount'] += abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']);
                    if (count($groups[$key]['samples']) < 3) {
                        $groups[$key]['samples'][] = [
                            'purchase_invoice_id' => (int) $row['purchase_invoice_id'],
                            'item_id' => (int) $row['id'],
                            'description' => mb_substr($description, 0, 180),
                            'year' => $year,
                        ];
                    }

                    if ($suggestion->kind->value === 'fixed_asset') {
                        $limit = $this->classification->assetLimitForYear($year);
                        $signature = hash('sha256', 'asset|' . $row['id'] . '|' . $year . '|' . $limit);
                        $this->setup->addProposal(
                            $runId, $supplierId, 'asset_candidate', $signature,
                            'Kandidát na dlouhodobý majetek', $suggestion->confidence, 1,
                            abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']),
                            [
                                'purchase_invoice_id' => (int) $row['purchase_invoice_id'],
                                'item_id' => (int) $row['id'],
                                'item_description' => mb_substr($description, 0, 180),
                                'expense_kind' => 'fixed_asset',
                                'target_account_code' => $fixedAssetAnalytic['account_code'] ?? null,
                                'acquisition_year' => $year,
                                'unit_price_czk' => round($unitPriceCzk, 2),
                                'fixed_asset_limit' => $limit,
                                'requires_asset_card' => true,
                            ],
                            ['reason' => $suggestion->reason, 'sample' => mb_substr($description, 0, 180), 'source' => 'catalog'],
                        );
                        $fixedAssetCount++;
                        $fixedAssetAmount += abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']);
                        $created++;
                    }
                }

                $hashRows[] = [(int) $row['id'], (string) $row['updated_at'], hash('sha256', $normalized)];
                if (($index + 1) % 25 === 0 || $index + 1 === count($rows)) {
                    $this->jobs->updateProgress($jobId, ['processed' => $index + 1]);
                }
            }

            $chartProposals = [];
            foreach ($groups as &$group) {
                if ($group['count'] < 2) {
                    continue;
                }
                $analytic = $this->analyticForGroup($chart, (string) $group['concept'], (string) $group['kind']);
                if ($analytic === null) {
                    if (!$this->isAnalyticAccount($chart, $group['account'])) {
                        $group['account'] = null;
                    }
                    continue;
                }
                $group['account'] = $analytic['account_code'];
                if (!empty($analytic['create'])) {
                    $code = (string) $analytic['account_code'];
                    $chartProposals[$code] ??= $analytic;
                    $chartProposals[$code]['occurrence_count'] = (int) ($chartProposals[$code]['occurrence_count'] ?? 0) + (int) $group['count'];
                    $chartProposals[$code]['affected_amount'] = (float) ($chartProposals[$code]['affected_amount'] ?? 0) + (float) $group['amount'];
                }
            }
            unset($group);

            if ($fixedAssetCount > 0 && !empty($fixedAssetAnalytic['create'])) {
                $code = (string) $fixedAssetAnalytic['account_code'];
                $chartProposals[$code] = $fixedAssetAnalytic;
                $chartProposals[$code]['occurrence_count'] = $fixedAssetCount;
                $chartProposals[$code]['affected_amount'] = $fixedAssetAmount;
            }

            foreach ($chartProposals as $proposal) {
                $signature = hash('sha256', self::canonicalJson($proposal));
                $this->setup->addProposal(
                    $runId, $supplierId, 'chart_account', $signature,
                    'Nová analytika ' . $proposal['account_code'] . ' - ' . $proposal['name'],
                    0.88, (int) ($proposal['occurrence_count'] ?? 0), (float) ($proposal['affected_amount'] ?? 0),
                    array_diff_key($proposal, ['occurrence_count' => true, 'affected_amount' => true, 'create' => true]),
                    ['reason' => 'flat_chart', 'parent_account_code' => $proposal['parent_account_code'], 'source' => 'catalog'],
                );
                $created++;
            }

            $aiSummary = [
                'requested' => !empty($params['use_ai']),
                'status' => 'not_requested',
                'sample_limit' => 50,
                'samples_sent' => 0,
                'requests_sent' => 0,
                'classified_items' => 0,
                'proposals' => 0,
            ];
            if (!empty($params['use_ai'])) {
                $this->jobs->updateProgress($jobId, ['current_step' => 'ai_enrichment']);
                $sampleLimit = in_array((int) ($params['ai_sample_limit'] ?? 50), [50, 100, 200], true)
                    ? (int) $params['ai_sample_limit']
                    : 50;
                $sampleSet = $this->aiSamples->build($unclassifiedRows, $sampleLimit);
                $aiResult = $this->aiEnricher->enrich($supplierId, $sampleSet['samples'], self::aiChartShape($chart));
                $aiApplied = in_array(($aiResult['status'] ?? null), ['ok', 'partial'], true)
                    ? $this->addAiRecommendations(
                        $runId,
                        $supplierId,
                        (array) ($aiResult['recommendations'] ?? []),
                        $sampleSet['rows_by_sample'],
                        $chart,
                        self::reservedAnalyticCodes($chartProposals),
                        $activeExpenseRules,
                        isset($chartProposals['042.100']),
                    )
                    : ['created' => 0, 'classified' => 0, 'kind_scorable' => 0, 'kind_agreement' => 0, 'account_scorable' => 0, 'account_agreement' => 0];
                $created += $aiApplied['created'];
                $unclassified = max(0, $unclassified - $aiApplied['classified']);
                $aiSummary = [
                    'requested' => true,
                    'status' => (string) ($aiResult['status'] ?? 'failed'),
                    'error' => $aiResult['error'] ?? null,
                    'samples_sent' => (int) ($aiResult['samples_sent'] ?? 0),
                    'sample_limit' => $sampleLimit,
                    'requests_sent' => (int) ($aiResult['requests_sent'] ?? 0),
                    'classified_items' => $aiApplied['classified'],
                    'proposals' => $aiApplied['created'],
                    'provider' => $aiResult['provider'] ?? null,
                    'model' => $aiResult['model'] ?? null,
                    'validation' => [
                        'kind_scorable' => $aiApplied['kind_scorable'],
                        'kind_agreement_pct' => $aiApplied['kind_scorable'] === 0
                            ? null : round(100 * $aiApplied['kind_agreement'] / $aiApplied['kind_scorable'], 1),
                        'account_scorable' => $aiApplied['account_scorable'],
                        'account_agreement_pct' => $aiApplied['account_scorable'] === 0
                            ? null : round(100 * $aiApplied['account_agreement'] / $aiApplied['account_scorable'], 1),
                    ],
                ];
                if (in_array(($aiResult['status'] ?? null), ['failed', 'partial'], true)) {
                    $this->jobs->appendLog($jobId, 'AI doplnění bylo přeskočeno: ' . (string) ($aiResult['error'] ?? 'unknown'));
                }
            }

            foreach ($groups as $group) {
                if ($group['count'] < 2) {
                    continue;
                }
                if (!$this->isAnalyticAccount($chart, $group['account'])
                    && !isset($chartProposals[(string) $group['account']])) {
                    continue;
                }
                $proposal = [
                    'name' => trim(($group['vendor_name'] ?? self::expenseKindLabel((string) $group['kind'])) . ' - ' . $group['phrase']),
                    'vendor_client_id' => $group['vendor_id'],
                    'vendor_name_contains' => null,
                    'description_contains' => $group['phrase'],
                    'expense_kind' => $group['kind'],
                    'target_account_code' => $group['account'],
                    'application_mode' => 'suggest',
                    'priority' => 100,
                    'is_active' => true,
                    'locale' => $group['locale'],
                ];
                if ($this->hasEquivalentExpenseRule($activeExpenseRules, $proposal)) {
                    continue;
                }
                $signature = hash('sha256', self::canonicalJson($proposal));
                $this->setup->addProposal(
                    $runId, $supplierId, 'expense_rule', $signature,
                    $proposal['name'], $group['confidence'], $group['count'], $group['amount'],
                    $proposal,
                    ['samples' => $group['samples'], 'concept' => $group['concept'], 'source' => 'catalog'],
                );
                $created++;
            }

            $postingTargets = $this->postingTargets($chart, $groups);
            foreach ($postingTargets as $kindValue => $targetAccount) {
                $kind = ExpenseKind::tryFrom($kindValue);
                if ($kind === null) {
                    continue;
                }
                $ruleKey = $kind->ruleKey();
                $current = $this->postingRules->resolve($supplierId, $ruleKey);
                $credit = (string) ($current['credit_account_code'] ?? '321');
                if ((string) ($current['debit_account_code'] ?? '') === $targetAccount && $credit === '321') {
                    continue;
                }
                $proposal = [
                    'rule_key' => $ruleKey,
                    'description' => 'Přijatá faktura - ' . self::expenseKindLabel($kindValue)
                        . ' (' . $targetAccount . '/321)',
                    'debit_account_code' => $targetAccount,
                    'credit_account_code' => '321',
                ];
                $this->setup->addProposal(
                    $runId, $supplierId, 'posting_rule', hash('sha256', self::canonicalJson($proposal)),
                    'Předkontace pro ' . self::expenseKindLabel($kindValue) . ' na ' . $targetAccount . '/321',
                    0.88, 0, 0.0, $proposal,
                    ['reason' => 'analytic_default', 'expense_kind' => $kindValue, 'source' => 'catalog'],
                );
                $created++;
            }

            $this->jobs->updateProgress($jobId, ['current_step' => 'bank_rules']);
            $bank = $this->bankProposals->analyze($supplierId, (int) ($params['months_back'] ?? 60), true);
            foreach ((array) ($bank['clusters'] ?? []) as $cluster) {
                $proposal = (array) ($cluster['proposal'] ?? []);
                if (($proposal['debit_account_code'] ?? null) === null || ($proposal['credit_account_code'] ?? null) === null) {
                    continue;
                }
                $signature = hash('sha256', self::canonicalJson($proposal));
                $this->setup->addProposal(
                    $runId, $supplierId, 'bank_rule', $signature,
                    (string) ($proposal['name'] ?? 'Bankovní pravidlo'), 0.9,
                    (int) ($cluster['tx_count'] ?? 0), 0.0, $proposal,
                    ['first_seen' => $cluster['first_seen'] ?? null, 'last_seen' => $cluster['last_seen'] ?? null, 'source' => 'history'],
                );
                $created++;
            }

            if ($unclassified > 0) {
                $this->setup->addProposal(
                    $runId, $supplierId, 'data_quality', hash('sha256', 'unclassified|' . $unclassified),
                    'Položky bez spolehlivé klasifikace', 0.0, $unclassified, 0.0,
                    ['code' => 'unclassified_items'], ['count' => $unclassified, 'source' => 'history'],
                );
                $created++;
            }

            $summary = [
                'documents' => count(array_unique(array_column($rows, 'purchase_invoice_id'))),
                'items' => count($rows),
                'proposals' => $created,
                'unclassified' => $unclassified,
                'classification_coverage_pct' => self::coveragePct(count($rows), $unclassified),
                'catalog_version' => $catalogVersion,
                'catalog_locales' => ['cs', 'sk', 'de', 'en'],
                'history' => $history['summary'],
                'ai' => $aiSummary,
                'validation' => [
                    'kind_scorable' => $storedScorable,
                    'kind_agreement_pct' => $storedScorable === 0 ? null : round(100 * $storedAgreement / $storedScorable, 1),
                    'account_scorable' => $accountScorable,
                    'account_agreement_pct' => $accountScorable === 0 ? null : round(100 * $accountAgreement / $accountScorable, 1),
                ],
                'locked_period_documents' => $this->lockedDocumentCount($supplierId, $params),
            ];
            $this->setup->completeRun(
                $runId,
                hash('sha256', self::canonicalJson($hashRows)),
                $this->tableHash($supplierId, 'chart_of_accounts', ['account_code', 'account_type', 'is_active']),
                $this->tableHash($supplierId, 'expense_classification_rules', ['id', 'updated_at']),
                $summary,
            );
            $this->jobs->updateProgress($jobId, ['processed' => count($rows), 'created_count' => $created, 'current_step' => 'completed']);
            $this->jobs->markCompleted($jobId);
        } catch (\Throwable $e) {
            $this->jobs->appendLog($jobId, 'Analýza selhala: ' . $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    private function items(int $supplierId, array $params): array
    {
        $where = ['pi.supplier_id = ?', "pi.status NOT IN ('draft','cancelled')", "pi.document_kind NOT IN ('advance','tax_document')"];
        $bind = [$supplierId];
        if (!empty($params['date_from'])) {
            $where[] = 'COALESCE(pi.tax_date, pi.issue_date) >= ?';
            $bind[] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $where[] = 'COALESCE(pi.tax_date, pi.issue_date) <= ?';
            $bind[] = $params['date_to'];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT pii.id, pii.purchase_invoice_id, pii.description, pii.unit_price_without_vat,
                    pii.total_without_vat, pii.expense_kind, pi.updated_at,
                    pi.vendor_id, pi.exchange_rate, c.company_name vendor_name,
                    YEAR(COALESCE(pi.tax_date, pi.issue_date)) acq_year,
                    (SELECT CASE WHEN COUNT(DISTINCT coa.account_code) = 1 THEN MIN(coa.account_code) END
                       FROM journal_entries je
                       JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                       JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.supplier_id = je.supplier_id
                      WHERE je.supplier_id = pi.supplier_id AND je.source_type = 'purchase_invoice'
                        AND je.source_id = pi.id AND je.reversed_by IS NULL
                        AND (coa.account_code LIKE '5%' OR coa.account_code LIKE '04%')) historical_account
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
               LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY pii.id'
        );
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Návrhy pravidel ze zaúčtované historie ({@see AccountingHistoryRuleLearner}).
     *
     * Historie má přednost před katalogem i před stávajícími pravidly: když dnešní
     * pravidlo posílá doklady dodavatele jinam, než kam se roky účtovaly, dostane
     * návrh prioritu těsně nad ním. Kde stávající pravidla už vedou na stejný účet,
     * návrh nevznikne, jen se položky započítají jako pokryté.
     *
     * @param list<array<string,mixed>> $rows položky z items()
     * @param list<array<string,mixed>> $activeExpenseRules
     * @return array{created:int,covered_item_ids:array<int,bool>,summary:array<string,int>}
     */
    private function historyRules(
        int $runId,
        int $supplierId,
        array $params,
        array $rows,
        array $chart,
        array $activeExpenseRules,
    ): array {
        $itemsByInvoice = [];
        foreach ($rows as $row) {
            $itemsByInvoice[(int) $row['purchase_invoice_id']][] = $row;
        }
        $documents = [];
        foreach ($this->postedCostAccounts($supplierId, $params) as $invoiceId => $document) {
            if (!isset($itemsByInvoice[$invoiceId])) {
                continue;
            }
            $document['descriptions'] = array_map(
                static fn (array $row): string => (string) $row['description'],
                $itemsByInvoice[$invoiceId],
            );
            $documents[] = $document;
        }
        $learned = AccountingHistoryRuleLearner::learn($documents);

        $kindsByAccount = $this->postingRuleKindsByAccount($supplierId);
        $rulesById = [];
        foreach ($activeExpenseRules as $rule) {
            $rulesById[(int) $rule['id']] = $rule;
        }
        $created = 0;
        $covered = [];
        $summary = [
            'documents' => count($documents),
            'documents_learned' => $learned['documents'],
            'rules' => 0,
            'already_covered' => 0,
            'overrides' => 0,
            'not_postable' => 0,
            'ambiguous_vendors' => count($learned['ambiguous']),
            'covered_items' => 0,
        ];

        foreach ($learned['rules'] as $candidate) {
            $account = (string) $candidate['account'];
            $kind = $this->historyKind($chart, $account, $kindsByAccount);
            if ($kind === null) {
                $summary['not_postable']++;
                continue;
            }
            $keyword = $candidate['keyword'] !== null ? (string) $candidate['keyword'] : null;
            $items = [];
            foreach ($candidate['invoice_ids'] as $invoiceId) {
                foreach ($itemsByInvoice[$invoiceId] ?? [] as $row) {
                    if ($keyword === null
                        || str_contains(BankMessageNormalizer::normalizeKeepDigits((string) $row['description']), $keyword)) {
                        $items[(int) $row['id']] = $row;
                    }
                }
            }

            $current = $this->currentRuleOutcome($supplierId, $items, $activeExpenseRules, $rulesById, $account, $kind);
            foreach ($items as $itemId => $_) {
                $covered[$itemId] = true;
            }
            if ($current['covered']) {
                $summary['already_covered']++;
                continue;
            }

            $strong = $candidate['share'] >= 0.95 && $candidate['agreeing'] >= 5;
            $proposal = [
                'name' => trim((string) ($candidate['vendor_name'] ?? ('#' . $candidate['vendor_id']))
                    . ($keyword !== null ? ' - ' . $keyword : '')) . ' → ' . $account,
                'vendor_client_id' => (int) $candidate['vendor_id'],
                'vendor_name_contains' => null,
                'description_contains' => $keyword,
                'expense_kind' => $kind->value,
                'target_account_code' => $account,
                'application_mode' => $strong ? 'auto' : 'suggest',
                'priority' => $current['overrides'] === []
                    ? self::HISTORY_RULE_PRIORITY
                    : max(1, min(array_column($current['overrides'], 'priority')) - 1),
                'is_active' => true,
                'learned_from' => 'history',
            ];
            if ($this->hasEquivalentExpenseRule($activeExpenseRules, $proposal)) {
                $summary['already_covered']++;
                continue;
            }
            if ($current['overrides'] !== []) {
                $summary['overrides']++;
            }
            $this->setup->addProposal(
                $runId, $supplierId, 'expense_rule',
                hash('sha256', self::canonicalJson(array_diff_key($proposal, ['name' => true]))),
                $proposal['name'], (float) $candidate['share'], (int) $candidate['agreeing'], (float) $candidate['amount'],
                $proposal,
                [
                    'source' => 'history',
                    'documents' => (int) $candidate['documents'],
                    'agreeing' => (int) $candidate['agreeing'],
                    'share' => (float) $candidate['share'],
                    'window' => (string) $candidate['window'],
                    'first_seen' => $candidate['first_seen'],
                    'last_seen' => $candidate['last_seen'],
                    'other_accounts' => $candidate['other_accounts'],
                    'overrides' => $current['overrides'],
                    'samples' => $candidate['samples'],
                ],
            );
            $summary['rules']++;
            $created++;
        }

        if ($learned['ambiguous'] !== []) {
            $ambiguous = $learned['ambiguous'];
            usort($ambiguous, static fn (array $a, array $b): int => $b['documents'] <=> $a['documents']);
            $this->setup->addProposal(
                $runId, $supplierId, 'data_quality',
                hash('sha256', 'history_ambiguous|' . count($ambiguous)),
                'Dodavatelé účtovaní na různé účty', 0.0, count($ambiguous), 0.0,
                ['code' => 'history_ambiguous_vendors'],
                ['source' => 'history', 'vendors' => array_slice($ambiguous, 0, 30)],
            );
            $created++;
        }

        $summary['covered_items'] = count($covered);
        return ['created' => $created, 'covered_item_ids' => $covered, 'summary' => $summary];
    }

    /**
     * Nákladové účty aktivního zaúčtování každé přijaté faktury (storno má source_id
     * NULL a stornovaný originál active_source_id NULL, takže se nepočítá ani jedno).
     * Obě strany: dobropis nese náklad na Dal.
     *
     * @return array<int,array{invoice_id:int,vendor_id:int,vendor_name:?string,date:string,accounts:array<string,float>}>
     */
    private function postedCostAccounts(int $supplierId, array $params): array
    {
        $where = [
            'pi.supplier_id = ?', "pi.status NOT IN ('draft','cancelled')",
            "pi.document_kind NOT IN ('advance','tax_document')", 'pi.vendor_id IS NOT NULL',
        ];
        $bind = [$supplierId];
        if (!empty($params['date_from'])) {
            $where[] = 'COALESCE(pi.tax_date, pi.issue_date) >= ?';
            $bind[] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $where[] = 'COALESCE(pi.tax_date, pi.issue_date) <= ?';
            $bind[] = $params['date_to'];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id invoice_id, pi.vendor_id, c.company_name vendor_name,
                    COALESCE(pi.tax_date, pi.issue_date) doc_date, coa.account_code, SUM(jel.signed_amount) amount
               FROM purchase_invoices pi
               JOIN journal_entries je ON je.supplier_id = pi.supplier_id
                AND je.source_type = 'purchase_invoice' AND je.active_source_id = pi.id
               JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
               JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.supplier_id = je.supplier_id
               LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
              WHERE " . implode(' AND ', $where) . "
                AND (coa.account_code LIKE '0%' OR coa.account_code LIKE '1%' OR coa.account_code LIKE '5%')
              GROUP BY pi.id, pi.vendor_id, c.company_name, doc_date, coa.account_code
             HAVING SUM(jel.signed_amount) <> 0
              ORDER BY pi.id"
        );
        $stmt->execute($bind);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invoiceId = (int) $row['invoice_id'];
            $out[$invoiceId] ??= [
                'invoice_id' => $invoiceId,
                'vendor_id' => (int) $row['vendor_id'],
                'vendor_name' => $row['vendor_name'] !== null ? (string) $row['vendor_name'] : null,
                'date' => (string) $row['doc_date'],
                'accounts' => [],
            ];
            $code = (string) $row['account_code'];
            $out[$invoiceId]['accounts'][$code] = ($out[$invoiceId]['accounts'][$code] ?? 0.0) + (float) $row['amount'];
        }
        return $out;
    }

    /** @return array<string,ExpenseKind> účet z předkontace => druh výdaje */
    private function postingRuleKindsByAccount(int $supplierId): array
    {
        $out = [];
        foreach (ExpenseKind::cases() as $kind) {
            $debit = trim((string) ($this->postingRules->resolve($supplierId, $kind->ruleKey())['debit_account_code'] ?? ''));
            if ($debit !== '' && !isset($out[$debit])) {
                $out[$debit] = $kind;
            }
        }
        return $out;
    }

    /**
     * Druh výdaje pro účet, na který se historicky účtovalo. Majetek (0xx) se z historie
     * neučí: pořízení je jednorázové a pravidlo „dodavatel → 042" by z každé další
     * faktury udělalo kartu majetku. Cílem pravidla musí být aktivní analytika, stejně
     * jako u ostatních návrhů (schválení to vynucuje).
     *
     * @param array<string,ExpenseKind> $kindsByAccount
     */
    private function historyKind(array $chart, string $account, array $kindsByAccount): ?ExpenseKind
    {
        if (!$this->isAnalyticAccount($chart, $account) || preg_match('/^[15]/', $account) !== 1) {
            return null;
        }
        $mapped = $kindsByAccount[$account] ?? null;
        if ($mapped !== null && $mapped !== ExpenseKind::FixedAsset) {
            return $mapped;
        }
        $name = BankMessageNormalizer::normalizeKeepDigits((string) ($chart['by_code'][$account]['name'] ?? ''));
        if (str_contains($name, 'drobn')) {
            return str_starts_with($account, '518') || str_contains($name, 'nehmot')
                ? ExpenseKind::SmallIntangible
                : ExpenseKind::SmallAsset;
        }
        return str_starts_with($account, '50') || str_starts_with($account, '1')
            ? ExpenseKind::Material
            : ExpenseKind::Service;
    }

    /**
     * Co s položkami udělají DNEŠNÍ pravidla. `covered` = všechny vyhodnocené položky
     * už pravidlo vede na stejný účet i druh; `overrides` = pravidla, která je posílají
     * jinam a historie je má přebít.
     *
     * @param array<int,array<string,mixed>> $items
     * @param list<array<string,mixed>> $activeExpenseRules
     * @param array<int,array<string,mixed>> $rulesById
     * @return array{covered:bool,overrides:list<array{id:int,name:string,priority:int,account:?string}>}
     */
    private function currentRuleOutcome(
        int $supplierId,
        array $items,
        array $activeExpenseRules,
        array $rulesById,
        string $account,
        ExpenseKind $kind,
    ): array {
        if ($activeExpenseRules === [] || $items === []) {
            return ['covered' => false, 'overrides' => []];
        }
        $agree = 0;
        $evaluated = 0;
        $overrides = [];
        foreach (array_slice($items, 0, 50, true) as $row) {
            $evaluated++;
            $suggestion = $this->classification->suggestFromRules(
                $supplierId,
                (string) $row['description'],
                $row['vendor_name'] !== null ? (string) $row['vendor_name'] : null,
                $row['vendor_id'] !== null ? (int) $row['vendor_id'] : null,
                abs((float) $row['unit_price_without_vat']) * self::fxRate($row['exchange_rate']),
                (int) $row['acq_year'],
                $activeExpenseRules,
            );
            if ($suggestion === null || $suggestion->ruleId === null) {
                continue;
            }
            $rule = $rulesById[$suggestion->ruleId] ?? null;
            // Účet null u pravidla = klasifikátor ho záměrně odložil na účetní (pojistka
            // PHM u řádku, který palivem není). Shodu tedy posuzuje cíl pravidla, ne
            // odložený výsledek; pojistka pak stejně platí i pro pravidlo z historie.
            $suggested = $suggestion->accountCode
                ?? (trim((string) ($rule['target_account_code'] ?? '')) ?: $suggestion->kind->fallbackAccount());
            if ($suggested === $account && $suggestion->kind === $kind) {
                $agree++;
                continue;
            }
            if ($rule !== null) {
                $overrides[(int) $rule['id']] = [
                    'id' => (int) $rule['id'],
                    'name' => (string) $rule['name'],
                    'priority' => (int) $rule['priority'],
                    'account' => $suggestion->accountCode,
                ];
            }
        }
        return ['covered' => $evaluated > 0 && $agree === $evaluated, 'overrides' => array_values($overrides)];
    }

    private function lockedDocumentCount(int $supplierId, array $params): int
    {
        $sql = "SELECT COUNT(DISTINCT pi.id)
                  FROM purchase_invoices pi
                  JOIN accounting_periods ap ON ap.supplier_id = pi.supplier_id
                   AND COALESCE(pi.tax_date, pi.issue_date) BETWEEN ap.starts_on AND ap.ends_on
                  LEFT JOIN accounting_supplier_settings aset ON aset.supplier_id = pi.supplier_id
                 WHERE pi.supplier_id = ?
                   AND (ap.status <> 'open' OR COALESCE(pi.tax_date, pi.issue_date) <= aset.locked_until)";
        $bind = [$supplierId];
        if (!empty($params['date_from'])) {
            $sql .= ' AND COALESCE(pi.tax_date, pi.issue_date) >= ?';
            $bind[] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $sql .= ' AND COALESCE(pi.tax_date, pi.issue_date) <= ?';
            $bind[] = $params['date_to'];
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($bind);
        return (int) $stmt->fetchColumn();
    }

    private function tableHash(int $supplierId, string $table, array $columns): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . implode(',', $columns) . " FROM {$table} WHERE supplier_id = ? ORDER BY id");
        $stmt->execute([$supplierId]);
        return hash('sha256', self::canonicalJson($stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @param list<array<string,mixed>> $recommendations
     * @param array<string,list<array<string,mixed>>> $rowsBySample
     * @param array<string,bool> $reservedCodes
     * @return array{created:int,classified:int,kind_scorable:int,kind_agreement:int,account_scorable:int,account_agreement:int}
     */
    private function addAiRecommendations(
        int $runId,
        int $supplierId,
        array $recommendations,
        array $rowsBySample,
        array $chart,
        array $reservedCodes,
        array $activeExpenseRules,
        bool $fixedAssetAnalyticAlreadyProposed,
    ): array {
        $created = 0;
        $classifiedIds = [];
        $kindScorable = 0;
        $kindAgreement = 0;
        $accountScorable = 0;
        $accountAgreement = 0;
        $analytics = [];
        $fixedAssetAnalytic = $this->analyticForGroup($chart, 'fixed_asset', 'fixed_asset');
        $fixedAssetCount = 0;
        $fixedAssetAmount = 0.0;
        $fixedAssetConfidence = 0.0;

        foreach ($recommendations as $recommendation) {
            $nature = self::correctAiNature(
                (string) ($recommendation['nature'] ?? ''),
                (string) ($recommendation['keyword'] ?? ''),
                (string) ($recommendation['analytic_name'] ?? ''),
            );
            $kind = self::kindForAiNature($nature);
            $parentCode = self::parentForAiNature($nature);
            if ($kind === null || $parentCode === null) {
                continue;
            }
            $rows = [];
            foreach ((array) ($recommendation['sample_ids'] ?? []) as $sampleId) {
                foreach ($rowsBySample[(string) $sampleId] ?? [] as $row) {
                    $rows[(int) $row['id']] = $row;
                }
            }
            if (count($rows) < 2) {
                continue;
            }

            $analyticName = trim((string) ($recommendation['analytic_name'] ?? ''));
            $analyticKey = $parentCode . '|' . mb_strtolower($analyticName);
            $analytic = $analytics[$analyticKey] ?? null;
            if (!array_key_exists($analyticKey, $analytics)) {
                $analytic = $this->nextAiAnalytic($chart, $parentCode, $analyticName, $reservedCodes);
                $analytics[$analyticKey] = $analytic;
                if ($analytic !== null && !empty($analytic['create'])) {
                    $analyticProposal = array_diff_key($analytic, ['create' => true]);
                    $this->setup->addProposal(
                        $runId,
                        $supplierId,
                        'chart_account',
                        hash('sha256', self::canonicalJson($analyticProposal)),
                        'Nová analytika ' . $analytic['account_code'] . ' - ' . $analytic['name'],
                        (float) $recommendation['confidence'],
                        count($rows),
                        self::rowsAmount($rows),
                        $analyticProposal,
                        ['reason' => 'ai_flat_chart', 'source' => 'ai'],
                    );
                    $created++;
                }
            }
            $targetAccount = $analytic['account_code'] ?? null;
            if ($targetAccount === null) {
                continue;
            }

            $keyword = trim((string) ($recommendation['keyword'] ?? ''));
            $proposal = [
                'name' => 'AI - ' . $analyticName,
                'vendor_client_id' => null,
                'vendor_name_contains' => null,
                'description_contains' => $keyword,
                'expense_kind' => $kind->value,
                'target_account_code' => $targetAccount,
                'application_mode' => 'suggest',
                'priority' => 90,
                'is_active' => true,
                'locale' => 'multi',
            ];
            if ($this->hasEquivalentExpenseRule($activeExpenseRules, $proposal)) {
                continue;
            }
            $this->setup->addProposal(
                $runId,
                $supplierId,
                'expense_rule',
                hash('sha256', self::canonicalJson($proposal)),
                $proposal['name'],
                (float) $recommendation['confidence'],
                count($rows),
                self::rowsAmount($rows),
                $proposal,
                ['source' => 'ai', 'nature' => $nature, 'sample_count' => count($recommendation['sample_ids'])],
            );
            $created++;

            foreach ($rows as $row) {
                $rowId = (int) $row['id'];
                $classifiedIds[$rowId] = true;
                $year = (int) $row['acq_year'];
                $unitPriceCzk = abs((float) $row['unit_price_without_vat']) * self::fxRate($row['exchange_rate']);
                $effectiveKind = $kind;
                $effectiveAccount = $targetAccount;
                if ($nature === 'tangible_asset'
                    && self::isAboveFixedAssetLimit($unitPriceCzk, $this->classification->assetLimitForYear($year))) {
                    $effectiveKind = ExpenseKind::FixedAsset;
                    $effectiveAccount = (string) ($fixedAssetAnalytic['account_code'] ?? '042');
                    $limit = $this->classification->assetLimitForYear($year);
                    $asset = [
                        'purchase_invoice_id' => (int) $row['purchase_invoice_id'],
                        'item_id' => $rowId,
                        'item_description' => mb_substr((string) $row['description'], 0, 180),
                        'expense_kind' => 'fixed_asset',
                        'target_account_code' => $fixedAssetAnalytic['account_code'] ?? null,
                        'acquisition_year' => $year,
                        'unit_price_czk' => round($unitPriceCzk, 2),
                        'fixed_asset_limit' => $limit,
                        'requires_asset_card' => true,
                    ];
                    $this->setup->addProposal(
                        $runId,
                        $supplierId,
                        'asset_candidate',
                        hash('sha256', 'ai-asset|' . $rowId . '|' . $year . '|' . $limit),
                        'Kandidát na dlouhodobý majetek',
                        (float) $recommendation['confidence'],
                        1,
                        abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']),
                        $asset,
                        ['source' => 'ai', 'nature' => $nature],
                    );
                    $fixedAssetCount++;
                    $fixedAssetAmount += abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']);
                    $fixedAssetConfidence = max($fixedAssetConfidence, (float) $recommendation['confidence']);
                    $created++;
                }
                if ($row['expense_kind'] !== null) {
                    $kindScorable++;
                    if ((string) $row['expense_kind'] === $effectiveKind->value) {
                        $kindAgreement++;
                    }
                }
                if ($row['historical_account'] !== null) {
                    $accountScorable++;
                    if (self::sameSynthetic($effectiveAccount, (string) $row['historical_account'])) {
                        $accountAgreement++;
                    }
                }
            }
        }

        if ($fixedAssetCount > 0 && !empty($fixedAssetAnalytic['create']) && !$fixedAssetAnalyticAlreadyProposed) {
            $analyticProposal = array_diff_key($fixedAssetAnalytic, [
                'occurrence_count' => true,
                'affected_amount' => true,
                'create' => true,
            ]);
            $this->setup->addProposal(
                $runId,
                $supplierId,
                'chart_account',
                hash('sha256', self::canonicalJson($analyticProposal)),
                'Nová analytika ' . $fixedAssetAnalytic['account_code'] . ' - ' . $fixedAssetAnalytic['name'],
                $fixedAssetConfidence,
                $fixedAssetCount,
                $fixedAssetAmount,
                $analyticProposal,
                ['reason' => 'ai_fixed_asset', 'source' => 'ai'],
            );
            $created++;
        }

        return [
            'created' => $created,
            'classified' => count($classifiedIds),
            'kind_scorable' => $kindScorable,
            'kind_agreement' => $kindAgreement,
            'account_scorable' => $accountScorable,
            'account_agreement' => $accountAgreement,
        ];
    }

    /** @param list<array<string,mixed>> $activeRules @param array<string,mixed> $proposal */
    private function hasEquivalentExpenseRule(array $activeRules, array $proposal): bool
    {
        foreach ($activeRules as $existing) {
            if (AccountingRuleEquivalence::expense($existing, $proposal)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,bool> $reservedCodes */
    private function nextAiAnalytic(array $chart, string $parentCode, string $name, array &$reservedCodes): ?array
    {
        $parent = $chart['by_code'][$parentCode] ?? null;
        if ($parent === null || empty($parent['is_active']) || empty($parent['is_synthetic']) || $name === '') {
            return null;
        }
        $normalizedName = BankMessageNormalizer::normalizeKeepDigits($name);
        foreach ($chart['by_code'] as $account) {
            if (!empty($account['is_active']) && empty($account['is_synthetic'])
                && (int) ($account['parent_id'] ?? 0) === (int) $parent['id']
                && BankMessageNormalizer::normalizeKeepDigits((string) ($account['name'] ?? '')) === $normalizedName
            ) {
                return ['account_code' => (string) $account['account_code'], 'create' => false];
            }
        }
        for ($suffix = 100; $suffix <= 999; $suffix++) {
            $code = $parentCode . '.' . str_pad((string) $suffix, 3, '0', STR_PAD_LEFT);
            if (isset($chart['by_code'][$code]) || isset($reservedCodes[$code])) {
                continue;
            }
            $reservedCodes[$code] = true;
            return [
                'account_code' => $code,
                'name' => mb_substr($name, 0, 160),
                'parent_account_code' => $parentCode,
                'account_type' => (string) $parent['account_type'],
                'normal_side' => $parent['normal_side'],
                'is_synthetic' => false,
                'is_active' => true,
                'create' => true,
            ];
        }
        return null;
    }

    private static function kindForAiNature(string $nature): ?ExpenseKind
    {
        return match ($nature) {
            'service', 'repair', 'insurance' => ExpenseKind::Service,
            'material', 'energy', 'fuel' => ExpenseKind::Material,
            'tangible_asset' => ExpenseKind::SmallAsset,
            'intangible_asset' => ExpenseKind::SmallIntangible,
            default => null,
        };
    }

    private static function parentForAiNature(string $nature): ?string
    {
        return match ($nature) {
            'material', 'fuel', 'tangible_asset' => '501',
            'energy' => '502',
            'service', 'intangible_asset' => '518',
            'repair' => '511',
            'insurance' => '548',
            default => null,
        };
    }

    private static function correctAiNature(string $nature, string $keyword, string $analyticName): string
    {
        if (!in_array($nature, ['material', 'service'], true)) {
            return $nature;
        }
        $text = BankMessageNormalizer::normalizeKeepDigits($keyword . ' ' . $analyticName);
        foreach ([
            'elektr', 'spotreba energie', 'energy consumption', 'utility bill',
            'zemni plyn', 'spotreba plynu', 'dodavka plynu', 'natural gas', 'gas supply', 'gasverbrauch', 'erdgas',
            'teplo', 'tepelna energie', 'heat supply', 'district heating', 'fernwarme', 'waermeversorgung',
            'vodne', 'stocne', 'dodavka vody', 'water supply', 'water and sewer', 'wasser', 'abwasser',
        ] as $energyTerm) {
            if (str_contains($text, $energyTerm)) {
                return 'energy';
            }
        }
        return $nature;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function rowsAmount(array $rows): float
    {
        $amount = 0.0;
        foreach ($rows as $row) {
            $amount += abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']);
        }
        return $amount;
    }

    /** @return list<array{code:string,is_synthetic:bool,analytic_count:int}> */
    private static function aiChartShape(array $chart): array
    {
        $shape = [];
        foreach (['501', '502', '511', '518', '548', '042'] as $code) {
            $account = $chart['by_code'][$code] ?? null;
            if ($account === null || empty($account['is_active'])) {
                continue;
            }
            $shape[] = [
                'code' => $code,
                'is_synthetic' => (bool) $account['is_synthetic'],
                'analytic_count' => (int) ($chart['children'][(int) $account['id']] ?? 0),
            ];
        }
        return $shape;
    }

    private function activeAccountOrNull(int $supplierId, ?string $accountCode): ?string
    {
        if ($accountCode === null || $accountCode === '') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId, $accountCode]);
        return $stmt->fetchColumn() === false ? null : $accountCode;
    }

    /** @return array{by_code:array<string,array<string,mixed>>,children:array<int,int>} */
    private function chartState(int $supplierId): array
    {
        $byCode = [];
        $children = [];
        foreach ($this->chart->listForTenant($supplierId, true) as $account) {
            $byCode[(string) $account['account_code']] = $account;
            if ($account['parent_id'] !== null && $account['is_active']) {
                $children[(int) $account['parent_id']] = ($children[(int) $account['parent_id']] ?? 0) + 1;
            }
        }
        return ['by_code' => $byCode, 'children' => $children];
    }

    /** @return array<string,mixed>|null */
    private function analyticForGroup(array $chart, string $concept, string $kind): ?array
    {
        $templates = self::analyticTemplates();
        $template = $templates[$concept] ?? ($templates[$kind] ?? null);
        if ($template === null) {
            return null;
        }
        [$code, $parentCode, $name] = $template;
        $parent = $chart['by_code'][$parentCode] ?? null;
        if ($parent === null || empty($parent['is_active']) || empty($parent['is_synthetic'])) {
            return null;
        }
        $existing = $chart['by_code'][$code] ?? null;
        if ($existing !== null) {
            return !empty($existing['is_active']) && empty($existing['is_synthetic'])
                && (int) ($existing['parent_id'] ?? 0) === (int) $parent['id']
                ? ['account_code' => $code, 'create' => false]
                : null;
        }
        return [
            'account_code' => $code,
            'name' => $name,
            'parent_account_code' => $parentCode,
            'account_type' => (string) $parent['account_type'],
            'normal_side' => $parent['normal_side'],
            'is_synthetic' => false,
            'is_active' => true,
            'create' => true,
            'occurrence_count' => 0,
            'affected_amount' => 0.0,
        ];
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    private static function analyticTemplates(): array
    {
        return [
            'fuel' => ['501.100', '501', 'Pohonné hmoty'],
            'small_asset' => ['501.200', '501', 'Drobný majetek'],
            'material' => ['501.900', '501', 'Ostatní materiál'],
            'energy' => ['502.100', '502', 'Spotřeba energie'],
            'vehicle_repair' => ['511.100', '511', 'Opravy vozidel'],
            'repair' => ['511.900', '511', 'Ostatní opravy a údržba'],
            'insurance' => ['548.100', '548', 'Pojištění'],
            'service' => ['518.100', '518', 'Ostatní služby'],
            'small_intangible' => ['518.200', '518', 'Drobný nehmotný majetek'],
            'fixed_asset' => ['042.100', '042', 'Pořízení DHM'],
        ];
    }

    private function isAnalyticAccount(array $chart, mixed $accountCode): bool
    {
        $code = trim((string) $accountCode);
        $account = $code === '' ? null : ($chart['by_code'][$code] ?? null);
        return $account !== null && !empty($account['is_active']) && empty($account['is_synthetic']);
    }

    /** @param array<string,array<string,mixed>> $chartProposals @return array<string,bool> */
    private static function reservedAnalyticCodes(array $chartProposals): array
    {
        $codes = array_fill_keys(array_keys($chartProposals), true);
        foreach (self::analyticTemplates() as [$code]) {
            $codes[$code] = true;
        }
        return $codes;
    }

    /** @return array<string,string> expense_kind => account_code */
    private function postingTargets(array $chart, array $groups): array
    {
        $preferredConcept = [
            'material' => 'material',
            'small_asset' => 'small_asset',
            'service' => 'service',
            'fixed_asset' => 'fixed_asset',
        ];
        $observedConcepts = [];
        foreach ($groups as $group) {
            if ((int) $group['count'] >= 2) {
                $observedConcepts[(string) $group['concept']] = true;
            }
        }
        $out = [];
        foreach ($preferredConcept as $kind => $concept) {
            if (!isset($observedConcepts[$concept])) {
                continue;
            }
            $analytic = $this->analyticForGroup($chart, $concept, $kind);
            if ($analytic !== null) {
                $out[$kind] = (string) $analytic['account_code'];
            }
        }
        return $out;
    }

    private static function matchCatalog(string $text, array $catalog): ?array
    {
        $veto = [];
        foreach ($catalog as $entry) {
            $phrase = BankMessageNormalizer::normalizeKeepDigits((string) ($entry['phrase'] ?? ''));
            if (($entry['polarity'] ?? '') === 'veto' && self::contains($text, $phrase)) {
                $veto[(string) $entry['concept_key']] = true;
            }
        }
        foreach ($catalog as $entry) {
            if (($entry['polarity'] ?? '') !== 'positive') {
                continue;
            }
            $phrase = BankMessageNormalizer::normalizeKeepDigits((string) ($entry['phrase'] ?? ''));
            if (!self::contains($text, $phrase)) {
                continue;
            }
            if (($entry['expense_kind'] ?? '') === 'small_asset' && isset($veto['asset_veto'])) {
                continue;
            }
            if (($entry['concept_key'] ?? '') === 'fuel' && isset($veto['fuel_veto'])) {
                continue;
            }
            return $entry;
        }
        return null;
    }

    private static function contains(string $text, string $phrase): bool
    {
        return $phrase !== '' && preg_match('/(?:^| )' . preg_quote($phrase, '/') . '(?= |$)/', $text) === 1;
    }

    private static function sameSynthetic(string $a, string $b): bool
    {
        return substr(str_replace('.', '', $a), 0, 3) === substr(str_replace('.', '', $b), 0, 3);
    }

    private static function fxRate(mixed $rate): float
    {
        $value = (float) $rate;
        return $value > 0 ? $value : 1.0;
    }

    private static function baseRuleKind(array $catalogMatch, string $suggestedKind): string
    {
        $catalogKind = (string) ($catalogMatch['expense_kind'] ?? '');
        return ExpenseKind::tryFrom($catalogKind) !== null ? $catalogKind : $suggestedKind;
    }

    private static function isAboveFixedAssetLimit(float $unitPriceCzk, float $limit): bool
    {
        return $unitPriceCzk > $limit;
    }

    private static function groupingVendorId(string $kind, mixed $vendorId): ?int
    {
        if (in_array($kind, ['small_asset', 'small_intangible'], true)) {
            return null;
        }
        return $vendorId !== null ? (int) $vendorId : null;
    }

    private static function coveragePct(int $items, int $unclassified): float
    {
        if ($items <= 0) {
            return 0.0;
        }
        return round(100 * max(0, $items - max(0, $unclassified)) / $items, 1);
    }

    private static function expenseKindLabel(string $kind): string
    {
        return match ($kind) {
            'service' => 'služby',
            'material' => 'materiál, energie a PHM',
            'small_asset' => 'drobný hmotný majetek',
            'small_intangible' => 'drobný nehmotný majetek',
            'fixed_asset' => 'dlouhodobý majetek',
            default => 'ostatní náklady',
        };
    }

    private static function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
