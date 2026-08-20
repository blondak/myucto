<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class AutomationCockpitContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function testCockpitRoutesAndFrontendPermissionAreFailClosed(): void
    {
        $routes = $this->read('api/src/Routes.php');
        foreach (['feed', 'counts', 'stats', 'overview', 'checklist', 'history', 'wizard/analysis'] as $path) {
            self::assertStringContainsString("'/api/automation/{$path}'", $routes);
        }
        self::assertStringContainsString("'/api/automation/wizard/apply'", $routes);
        self::assertStringContainsString("'/api/bank-ai-suggestion-availability'", $routes);

        $permissions = $this->read('api/src/Security/RoutePermissionMap.php');
        self::assertStringContainsString("['POST', '#^/api/automation/wizard/apply$#', 'bank.rules', AccessLevel::WRITE]", $permissions);
        self::assertStringContainsString("['GET', '#^/api/automation(/|$)#', 'accounting', AccessLevel::READ]", $permissions);
        self::assertStringContainsString("['GET', '#^/api/bank-ai-suggestion-availability$#', 'bank.post', AccessLevel::WRITE]", $permissions);

        $workspaceRoutes = $this->read('web/src/router/workspaceRoutes.ts');
        self::assertStringContainsString("name: 'automation-cockpit'", $workspaceRoutes);

        $router = $this->read('web/src/router/index.ts');
        self::assertStringContainsString("'automation-cockpit': ['accounting']", $router);
    }

    public function testMultiSupplierMutationsKeepExplicitRowScopeAndAiIsNotBulkEligible(): void
    {
        $client = $this->read('web/src/api/client.ts');
        self::assertStringContainsString("!config.headers.has('X-Supplier-Id')", $client);

        $automation = $this->read('web/src/api/automation.ts');
        self::assertStringContainsString("!AI_SOURCES.includes(item.source", $automation);
        self::assertStringContainsString("'X-Supplier-Id': String(supplierId)", $automation);
    }

    public function testUndoUsesOriginalDateAndRefusesClosedOrSoftLockedPeriod(): void
    {
        $service = $this->read('api/src/Service/Accounting/Bank/BankPostingService.php');
        self::assertStringContainsString("findForDate(\$supplierId, \$originalDate)", $service);
        self::assertStringContainsString('SELECT locked_until FROM accounting_supplier_settings', $service);
        self::assertStringContainsString("'entry_date' => \$originalDate", $service);
    }

    public function testDigestHasBothPlatformWrappersAndBothLocales(): void
    {
        foreach ([
            'api/bin/cron-automation-digest.php',
            'cmd/cron-automation-digest.cmd',
            'cmd/cron-automation-digest.sh',
            'api/templates/email/automation_digest.cs.html.twig',
            'api/templates/email/automation_digest.cs.txt.twig',
            'api/templates/email/automation_digest.en.html.twig',
            'api/templates/email/automation_digest.en.txt.twig',
        ] as $file) {
            self::assertFileExists($this->root . '/' . $file);
        }
    }

    public function testCockpitTranslationsExistInBothLocales(): void
    {
        foreach (['cs', 'en'] as $locale) {
            $messages = json_decode($this->read("web/src/i18n/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('string', gettype($messages['nav']['automation'] ?? null));
            foreach (['title', 'tab_auto', 'tab_pending', 'tab_needs_input', 'undo_period_closed', 'history_empty'] as $key) {
                self::assertIsString($messages['automation'][$key] ?? null, "Chybí automation.{$key} v {$locale}.json");
            }
            foreach (['continue', 'date', 'description', 'amount'] as $key) {
                self::assertIsString($messages['common'][$key] ?? null, "Chybí common.{$key} v {$locale}.json");
            }
        }
    }

    public function testCockpitKeepsPaginationConflictAndOverrideContracts(): void
    {
        $cockpit = $this->read('web/src/pages/automation/AutomationCockpit.vue');
        self::assertStringContainsString('per_page: perPage', $cockpit);
        self::assertStringContainsString('total.value = result.total', $cockpit);
        self::assertStringContainsString('store.counts?.per_supplier', $cockpit);
        self::assertStringContainsString('suppliers.currentSupplierId', $cockpit);
        self::assertStringContainsString('cursor-pointer rounded bg-primary-600', $cockpit);
        self::assertStringContainsString('<PaginationBar', $cockpit);

        $history = $this->read('web/src/pages/automation/AutomationHistory.vue');
        self::assertStringContainsString('per_page: perPage', $history);
        self::assertStringContainsString('<PaginationBar', $history);

        $suggestions = $this->read('web/src/pages/bank/PostingSuggestions.vue');
        self::assertStringContainsString('page: page.value, per_page: perPage.value', $suggestions);
        self::assertStringContainsString('<PaginationBar', $suggestions);

        $rules = $this->read('web/src/pages/bank/BankPostingRules.vue');
        self::assertStringContainsString('per_page: perPage', $rules);
        self::assertStringContainsString('<PaginationBar', $rules);

        $ruleHistory = $this->read('web/src/components/bank/RuleHistoryModal.vue');
        self::assertStringContainsString('<PaginationBar', $ruleHistory);

        $activation = $this->read('web/src/pages/admin/AccountingActivation.vue');
        self::assertStringContainsString('activationApi.jobs(jobPage.value, jobPerPage)', $activation);
        self::assertStringContainsString('<PaginationBar', $activation);

        $opening = $this->read('web/src/components/settings/activation/OpeningBalanceEditor.vue');
        self::assertStringContainsString('pagedRows', $opening);
        self::assertStringContainsString('<PaginationBar', $opening);

        $dph = $this->read('web/src/pages/reports/DphPriznaniReport.vue');
        self::assertStringContainsString('pagedCrossCheckDocuments', $dph);
        self::assertStringContainsString('pagedPostFilingDocs', $dph);

        $wizard = $this->read('web/src/pages/automation/AutomationWizard.vue');
        self::assertGreaterThanOrEqual(2, substr_count($wizard, "cursor-pointer rounded bg-primary-600"));

        $needsInput = $this->read('web/src/components/automation/NeedsInputCard.vue');
        self::assertStringContainsString('selectedRuleId', $needsInput);
        self::assertStringContainsString('selected_rule_id', $this->read('web/src/api/automation.ts'));
        self::assertStringContainsString('duplicate_entry', $needsInput);

        $feed = $this->read('web/src/pages/automation/FeedTable.vue');
        self::assertStringContainsString('debit_account_code', $feed);
        self::assertStringContainsString('credit_account_code', $feed);
        self::assertStringContainsString('showDayHeader', $feed);

        $why = $this->read('web/src/components/automation/WhyChip.vue');
        self::assertStringContainsString('rule_approved_streak', $why);

        $service = $this->read('api/src/Service/Automation/AutomationFeedService.php');
        self::assertGreaterThanOrEqual(2, substr_count($service, "document_kind <> 'advance'"));
    }

    public function testAuditGuardsRemainWiredIntoAutomationEngine(): void
    {
        $chain = $this->read('api/src/Service/Accounting/Bank/Detect/BankDetectorChain.php');
        self::assertStringContainsString('hasRejected($supplierId, (int) $tx[\'id\'], (int) $rule[\'id\'])', $chain);

        $match = $this->read('api/src/Service/Bank/Match/MatchSuggestionService.php');
        self::assertStringContainsString('$ownTx = !$pdo->inTransaction()', $match);
        self::assertStringContainsString("SAVEPOINT ' . \$savepoint", $match);

        $action = $this->read('api/src/Action/Ai/AiSuggestionAction.php');
        self::assertStringContainsString("array_key_exists('credit_account_code', \$payload)", $action);
        self::assertStringContainsString('$stmt->execute([$supplierId, $credit])', $action);
    }

    private function read(string $path): string
    {
        $value = file_get_contents($this->root . '/' . $path);
        self::assertNotFalse($value, "Nelze načíst {$path}");
        return $value;
    }
}
