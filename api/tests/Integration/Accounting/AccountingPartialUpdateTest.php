<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\AccountingSetupAssistantAction;
use MyInvoice\Action\Accounting\Cash\CashDocumentAction;
use MyInvoice\Action\Accounting\CnbRepoRateAction;
use MyInvoice\Action\Accounting\GoPay\GoPayAction;
use MyInvoice\Action\Accounting\JournalTemplateAction;
use MyInvoice\Action\Accounting\OtherItemAction;
use MyInvoice\Action\Accounting\PeriodLockAction;
use MyInvoice\Action\Accounting\PostingRuleAction;
use MyInvoice\Action\Accounting\Reports\ReportingSettingsAction;
use MyInvoice\Action\Accounting\Reports\StatementNotesAction;
use MyInvoice\Repository\AccountingSetupRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\CashDocumentRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\JournalEntryTemplateRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\Codebooks\PostingRulesImportService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Částečná úprava účetních záznamů (issue #113): klíč, který v těle chybí, nechává
 * uloženou hodnotu; explicitní null ji maže. Akce u jedné hodnoty bez klíče odmítnou.
 * Vše v transakci BankPostingTestCase → rollback.
 */
#[Group('integration')]
final class AccountingPartialUpdateTest extends BankPostingTestCase
{
    private const RULE_KEY = 'invoice.services.issued';

    public function testPostingRulePutKeepsOmittedSide(): void
    {
        $action = $this->container->get(PostingRuleAction::class);
        $args = ['rule_key' => self::RULE_KEY];
        self::assertSame(200, $this->callAction($action, 'put', 'PUT', 'accountant',
            ['debit_account_code' => '311', 'credit_account_code' => '601'], $args)['status']);

        $res = $this->callAction($action, 'put', 'PUT', 'accountant', ['credit_account_code' => '602'], $args);
        self::assertSame(200, $res['status']);
        self::assertSame('311', $res['body']['debit_account_code'], 'Vynechaná strana MD zůstává.');
        self::assertSame('602', $res['body']['credit_account_code']);

        $res = $this->callAction($action, 'put', 'PUT', 'accountant', ['debit_account_code' => null], $args);
        self::assertSame(200, $res['status']);
        self::assertNull($res['body']['debit_account_code'], 'Explicitní null stranu vyprázdní.');
        self::assertSame('602', $res['body']['credit_account_code']);
    }

    public function testPostingRuleImportWithoutCreditColumnKeepsCredit(): void
    {
        $import = $this->container->get(PostingRulesImportService::class);
        $first = $import->import($this->supplierId, $this->userId, "klic;md_ucet;d_ucet\n" . self::RULE_KEY . ";315;602\n", 'k.csv', false);
        self::assertTrue($first['ok']);

        $second = $import->import($this->supplierId, $this->userId, "klic;md_ucet\n" . self::RULE_KEY . ";313\n", 'k.csv', false);
        self::assertTrue($second['ok']);
        $rule = $this->container->get(PostingRuleRepository::class)->resolve($this->supplierId, self::RULE_KEY);
        self::assertSame('313', $rule['debit_account_code']);
        self::assertSame('602', $rule['credit_account_code'], 'Chybějící sloupec d_ucet stranu D nemaže.');

        $third = $import->import($this->supplierId, $this->userId, "klic;md_ucet;d_ucet\n" . self::RULE_KEY . ";313;\n", 'k.csv', false);
        self::assertTrue($third['ok']);
        self::assertNull($this->container->get(PostingRuleRepository::class)->resolve($this->supplierId, self::RULE_KEY)['credit_account_code'],
            'Prázdná buňka v existujícím sloupci stranu vyprázdní.');
    }

    public function testPeriodLockWithoutKeyIsRejected(): void
    {
        $action = $this->container->get(PeriodLockAction::class);
        $settings = $this->container->get(AccountingSupplierSettingsRepository::class);
        self::assertSame(200, $this->callAction($action, 'update', 'PUT', 'admin',
            ['locked_until' => self::YEAR . '-03-31', 'reason' => 'Uzávěrka čtvrtletí'])['status']);

        $res = $this->callAction($action, 'update', 'PUT', 'admin', ['reason' => 'Jen zdůvodnění']);
        self::assertSame(400, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
        self::assertSame(self::YEAR . '-03-31', $settings->getLockedUntil($this->supplierId), 'Zámek bez klíče nezmizí.');

        self::assertSame(200, $this->callAction($action, 'update', 'PUT', 'admin',
            ['locked_until' => null, 'reason' => 'Oprava podání'])['status']);
        self::assertNull($settings->getLockedUntil($this->supplierId));
    }

    public function testStatementNoteWithoutContentIsRejected(): void
    {
        $action = $this->container->get(StatementNotesAction::class);
        $args = ['id' => (string) $this->periodId, 'section' => 'accounting_principles'];
        self::assertSame(200, $this->callAction($action, 'save', 'PUT', 'admin', ['content' => 'Účtujeme podle ZoÚ.'], $args)['status']);

        $res = $this->callAction($action, 'save', 'PUT', 'admin', [], $args);
        self::assertSame(400, $res['status']);
        self::assertSame('Účtujeme podle ZoÚ.', $this->statementNote(), 'Sekce bez klíče content nezmizí.');

        self::assertSame(200, $this->callAction($action, 'save', 'PUT', 'admin', ['content' => null], $args)['status']);
        self::assertNull($this->statementNote());
    }

    public function testRepoRateWithoutNoteKeepsNote(): void
    {
        $action = $this->container->get(CnbRepoRateAction::class);
        $date = self::YEAR . '-07-01';
        self::assertSame(200, $this->callAction($action, 'upsert', 'PUT', 'admin',
            ['valid_from' => $date, 'rate' => 3.5, 'note' => 'Měnová politika'])['status']);

        self::assertSame(200, $this->callAction($action, 'upsert', 'PUT', 'admin', ['valid_from' => $date, 'rate' => 3.75])['status']);
        self::assertSame(['rate' => 3.75, 'note' => 'Měnová politika'], $this->repoRate($date));

        self::assertSame(200, $this->callAction($action, 'upsert', 'PUT', 'admin', ['valid_from' => $date, 'rate' => 3.75, 'note' => null])['status']);
        self::assertSame(['rate' => 3.75, 'note' => null], $this->repoRate($date));
    }

    public function testReportingSettingsKeepOmittedValues(): void
    {
        $action = $this->container->get(ReportingSettingsAction::class);
        $settings = $this->container->get(AccountingSupplierSettingsRepository::class);
        self::assertSame(200, $this->callAction($action, 'update', 'PUT', 'accountant', [
            'avg_employees' => 25, 'statement_scope_override' => 'small',
            'tax_authority_offset' => true, 'tax_authority_offset_from_year' => self::YEAR,
        ])['status']);

        self::assertSame(200, $this->callAction($action, 'update', 'PUT', 'accountant', ['statutory_audit' => true])['status']);
        self::assertSame(200, $this->callAction($action, 'update', 'PUT', 'accountant', ['tax_authority_offset' => true])['status']);
        $s = $settings->get($this->supplierId);
        self::assertSame(25, $s['avg_employees']);
        self::assertSame('small', $s['statement_scope_override']);
        self::assertTrue($s['statutory_audit']);
        self::assertSame(self::YEAR, $s['tax_authority_offset_from_year']);

        self::assertSame(200, $this->callAction($action, 'update', 'PUT', 'accountant',
            ['avg_employees' => null, 'tax_authority_offset_from_year' => null])['status']);
        $s = $settings->get($this->supplierId);
        self::assertNull($s['avg_employees']);
        self::assertSame('small', $s['statement_scope_override']);
        self::assertNull($s['tax_authority_offset_from_year']);
    }

    public function testJournalTemplateUpdateKeepsDescriptionAndLines(): void
    {
        $templates = $this->container->get(JournalEntryTemplateRepository::class);
        $id = $templates->create($this->supplierId, 'Nájem', 'Měsíční nájem', $this->userId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000.0, 'label' => 'Nájem', 'cost_center' => null],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.0, 'label' => null, 'cost_center' => null],
        ]);
        $action = $this->container->get(JournalTemplateAction::class);

        $res = $this->callAction($action, 'update', 'PUT', 'accountant', ['name' => 'Nájem kanceláře'], ['id' => (string) $id]);
        self::assertSame(200, $res['status']);
        $tpl = $templates->find($this->supplierId, $id);
        self::assertSame('Nájem kanceláře', $tpl['name']);
        self::assertSame('Měsíční nájem', $tpl['description'], 'Vynechaný popis zůstává.');
        self::assertCount(2, $tpl['lines'], 'Vynechané řádky zůstávají.');
        self::assertSame(1000.0, $tpl['lines'][0]['default_amount']);

        self::assertSame(200, $this->callAction($action, 'update', 'PUT', 'accountant', ['description' => null], ['id' => (string) $id])['status']);
        $tpl = $templates->find($this->supplierId, $id);
        self::assertNull($tpl['description']);
        self::assertSame('Nájem kanceláře', $tpl['name']);
    }

    public function testSetupProposalEditKeepsOmittedFields(): void
    {
        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobId = $this->container->get(ImportJobRepository::class)->create($this->supplierId, 'accounting_setup_analysis', [], $this->userId);
        $runId = $setup->createRun($this->supplierId, $jobId, [], 1, $this->userId);
        $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);
        $this->db->pdo()->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
             VALUES (?, '501.777', 'Kancelářské potřeby', 'expense', 'debit', 0, 1)"
        )->execute([$this->supplierId]);
        $setup->addProposal($runId, $this->supplierId, 'chart_account', hash('sha256', 'partial-chart'), 'Použít účet', 0.9, 2, 500, [
            'account_code' => '501.900', 'name' => 'Papír', 'create' => false, 'replacement_account_code' => '501.777',
        ], []);
        $setup->addProposal($runId, $this->supplierId, 'bank_rule', hash('sha256', 'partial-bank'), 'Nájem', 0.9, 3, 3000, [
            'name' => 'Nájem', 'message_contains' => 'NAJEM', 'debit_account_code' => '518',
            'credit_account_code' => '221', 'mode' => 'suggest',
        ], []);
        $byType = array_column($setup->proposals($this->supplierId, $runId), null, 'proposal_type');
        $action = $this->container->get(AccountingSetupAssistantAction::class);

        $chartArgs = ['id' => (string) $runId, 'proposalId' => (string) $byType['chart_account']['id']];
        $res = $this->callAction($action, 'updateProposal', 'PUT', 'admin', ['name' => 'Papír a tonery'], $chartArgs);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $json = $res['body']['proposal']['proposal_json'];
        self::assertSame('Papír a tonery', $json['name']);
        self::assertFalse($json['create'], 'Návrh „použít existující účet" se nepřepne na novou analytiku.');
        self::assertSame('501.777', $json['replacement_account_code']);

        $bankArgs = ['id' => (string) $runId, 'proposalId' => (string) $byType['bank_rule']['id']];
        $res = $this->callAction($action, 'updateProposal', 'PUT', 'admin', ['name' => 'Nájem kanceláře'], $bankArgs);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $json = $res['body']['proposal']['proposal_json'];
        self::assertSame('NAJEM', $json['message_contains'], 'Vynechaný filtr zprávy zůstává.');
        self::assertSame('518', $json['debit_account_code']);

        $res = $this->callAction($action, 'updateProposal', 'PUT', 'admin', ['message_contains' => null], $bankArgs);
        self::assertSame(200, $res['status']);
        self::assertNull($res['body']['proposal']['proposal_json']['message_contains']);
    }

    public function testGoPaySettingsKeepOmittedValues(): void
    {
        $pdo = $this->db->pdo();
        $parent = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id={$this->supplierId} AND account_code='221'")->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type,normal_side,is_synthetic,parent_id)
             VALUES (?,?,?,"asset","debit",0,?)'
        );
        $insert->execute([$this->supplierId, '221.GP97', 'GoPay test', $parent]);
        $gopayId = (int) $pdo->lastInsertId();
        $insert->execute([$this->supplierId, '221.BK97', 'Banka test', $parent]);
        $bankId = (int) $pdo->lastInsertId();
        $action = $this->container->get(GoPayAction::class);

        self::assertSame(200, $this->callAction($action, 'saveSettings', 'PUT', 'admin', [
            'currency' => 'CZK', 'gopay_account_id' => $gopayId, 'receivable_account_id' => $this->accountIdByCode('311'),
            'fee_account_id' => $this->accountIdByCode('568'), 'clearing_account_id' => $this->accountIdByCode('261'),
            'destination_bank_account_id' => $bankId, 'payout_account_number' => '1000000005',
            'payout_bank_code' => '0100', 'payout_date_tolerance_days' => 7,
        ])['status']);

        $res = $this->callAction($action, 'saveSettings', 'PUT', 'admin', ['currency' => 'CZK', 'payout_date_tolerance_days' => 5]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $settings = $res['body']['settings'];
        self::assertSame(5, (int) $settings['payout_date_tolerance_days']);
        self::assertSame($gopayId, (int) $settings['gopay_account_id'], 'Vynechané účty zůstávají.');
        self::assertSame('1000000005', $settings['payout_account_number']);

        $res = $this->callAction($action, 'saveSettings', 'PUT', 'admin', ['currency' => 'CZK', 'payout_bank_code' => '0300']);
        self::assertSame(200, $res['status']);
        self::assertSame(5, (int) $res['body']['settings']['payout_date_tolerance_days'], 'Vynechaná tolerance se nevrací na výchozí 3.');
    }

    public function testOtherItemUpdateKeepsOmittedFieldsAndPostingLines(): void
    {
        $service = $this->container->get(OtherItemService::class);
        $partner = $this->client('Pronajímatel s.r.o.');
        $item = $service->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Nájemné', 'partner_id' => $partner,
            'partner_name' => 'Pronajímatel s.r.o.', 'issued_on' => self::YEAR . '-01-31',
            'accounting_on' => self::YEAR . '-01-30', 'due_on' => self::YEAR . '-02-05', 'currency' => 'CZK',
            'amount' => 1200, 'variable_symbol' => '20990131', 'account_code' => '325', 'note' => 'Smlouva 1',
            'posting_lines' => [['account_code' => '518', 'amount' => 700], ['account_code' => '378', 'amount' => 500]],
        ], $this->userId);
        $id = (string) $item['id'];
        $action = $this->container->get(OtherItemAction::class);

        $res = $this->callAction($action, 'update', 'PUT', 'admin', ['title' => 'Nájemné leden'], ['id' => $id]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $stored = $service->get($this->supplierId, (int) $id);
        self::assertSame('Nájemné leden', $stored['title']);
        self::assertSame('rent', $stored['kind']);
        self::assertSame($partner, (int) $stored['partner_id']);
        self::assertSame('Pronajímatel s.r.o.', $stored['partner_name']);
        self::assertSame(self::YEAR . '-01-30', $stored['accounting_on']);
        self::assertSame('20990131', $stored['variable_symbol']);
        self::assertSame('325', $stored['account_code']);
        self::assertSame('Smlouva 1', $stored['note']);
        self::assertCount(2, $stored['posting_lines'], 'Vynechaný rozpad protiúčtů zůstává.');

        $res = $this->callAction($action, 'update', 'PUT', 'admin',
            ['note' => null, 'variable_symbol' => null, 'counter_account_code' => '518'], ['id' => $id]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $stored = $service->get($this->supplierId, (int) $id);
        self::assertNull($stored['note']);
        self::assertNull($stored['variable_symbol']);
        self::assertSame('518', $stored['counter_account_code']);
        self::assertSame([['account_code' => '518', 'amount' => 1200.0]], $stored['posting_lines'], 'Jediný protiúčet nahradí rozpad.');
    }

    public function testCashDraftUpdateKeepsOmittedFieldsAndVatBreakdown(): void
    {
        $registerId = $this->container->get(CashRegisterService::class)->create($this->supplierId, [
            'name' => 'Pokladna částečná', 'account_code' => '211', 'is_default' => false,
        ]);
        $projectId = $this->project();
        $created = $this->container->get(CashDocumentService::class)->create($this->supplierId, [
            'register_id' => $registerId, 'doc_type' => 'in', 'purpose' => 'sale',
            'issue_date' => self::YEAR . '-06-15', 'tax_date' => self::YEAR . '-06-14', 'description' => 'Prodej za hotové',
            'total_amount' => 1210.00, 'vat_mode' => 'vat', 'partner_name' => 'Odběratel s.r.o.',
            'partner_ic' => '12345678', 'partner_dic' => 'CZ12345678', 'project_id' => $projectId,
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
            'post' => false,
        ], $this->userId);
        $id = (int) $created['id'];
        $action = $this->container->get(CashDocumentAction::class);

        $res = $this->callAction($action, 'update', 'PUT', 'admin', ['description' => 'Prodej zboží'], ['id' => (string) $id]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $docs = $this->container->get(CashDocumentRepository::class);
        $doc = $docs->find($this->supplierId, $id);
        self::assertSame('Prodej zboží', $doc['description']);
        self::assertSame('vat', $doc['vat_mode'], 'Doklad s DPH nezmění režim na „bez DPH".');
        self::assertSame(self::YEAR . '-06-14', $doc['tax_date'], 'DUZP zůstává.');
        self::assertSame('CZ12345678', $doc['partner_dic']);
        self::assertSame('Odběratel s.r.o.', $doc['partner_name']);
        self::assertSame($projectId, $doc['project_id'], 'Zakázka zůstává i bez klíče project_id.');
        self::assertEqualsWithDelta(1210.00, $doc['total_amount'], 0.001);
        $lines = $docs->vatLinesFor($id);
        self::assertCount(1, $lines, 'Vynechaný rozpad DPH zůstává.');
        self::assertEqualsWithDelta(210.00, $lines[0]['vat_amount'], 0.001);

        $res = $this->callAction($action, 'update', 'PUT', 'admin',
            ['vat_mode' => 'none', 'partner_dic' => null, 'project_id' => null], ['id' => (string) $id]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $doc = $docs->find($this->supplierId, $id);
        self::assertSame('none', $doc['vat_mode']);
        self::assertNull($doc['partner_dic']);
        self::assertNull($doc['project_id']);
        self::assertSame('Odběratel s.r.o.', $doc['partner_name']);
        self::assertSame([], $docs->vatLinesFor($id));
    }

    public function testForeignCashDraftKeepsManualRateWithoutRefetch(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([self::YEAR . '-06-15', 'EUR', 25.00]);
        $registerId = $this->container->get(CashRegisterService::class)->create($this->supplierId, [
            'name' => 'EUR pokladna', 'account_code' => '211600', 'currency_code' => 'EUR', 'is_default' => false,
        ]);
        $created = $this->container->get(CashDocumentService::class)->create($this->supplierId, [
            'register_id' => $registerId, 'doc_type' => 'out', 'purpose' => 'purchase',
            'issue_date' => self::YEAR . '-06-15', 'description' => 'Nákup v EUR',
            'amount_foreign' => 40.00, 'fx_rate' => 24.5, 'post' => false,
        ], $this->userId);
        $id = (int) $created['id'];

        $res = $this->callAction($this->container->get(CashDocumentAction::class), 'update', 'PUT', 'admin',
            ['description' => 'Nákup materiálu v EUR'], ['id' => (string) $id]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $doc = $this->container->get(CashDocumentRepository::class)->find($this->supplierId, $id);
        self::assertEqualsWithDelta(40.00, $doc['amount_foreign'], 0.001, 'Cizoměnová částka se znovu nenásobí kurzem.');
        self::assertEqualsWithDelta(24.5, $doc['fx_rate'], 0.000001, 'Ruční kurz se nepřepíše kurzem ČNB.');
        self::assertEqualsWithDelta(980.00, $doc['total_amount'], 0.001);
    }

    public function testOtherItemSplitSurvivesNullCounterAndAccountingDateFollowsIssue(): void
    {
        $service = $this->container->get(OtherItemService::class);
        $item = $service->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Nájemné', 'issued_on' => self::YEAR . '-01-31',
            'due_on' => self::YEAR . '-02-05', 'currency' => 'CZK', 'amount' => 1200, 'account_code' => '325',
            'posting_lines' => [['account_code' => '518', 'amount' => 700], ['account_code' => '378', 'amount' => 500]],
        ], $this->userId);
        $id = (string) $item['id'];
        self::assertNull($item['counter_account_code']);
        $action = $this->container->get(OtherItemAction::class);

        $res = $this->callAction($action, 'update', 'PUT', 'admin',
            ['title' => 'Nájemné únor', 'counter_account_code' => null], ['id' => $id]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertCount(2, $service->get($this->supplierId, (int) $id)['posting_lines'], 'Prázdný protiúčet z GET rozpad nesmaže.');

        $res = $this->callAction($action, 'update', 'PUT', 'admin',
            ['issued_on' => self::YEAR . '-02-01', 'due_on' => self::YEAR . '-02-15'], ['id' => $id]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $stored = $service->get($this->supplierId, (int) $id);
        self::assertSame(self::YEAR . '-02-01', $stored['accounting_on'], 'Datum zaúčtování rovné datu vzniku jde s ním.');

        $single = $service->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'fee', 'title' => 'Poplatek', 'issued_on' => self::YEAR . '-01-31',
            'accounting_on' => self::YEAR . '-01-30', 'due_on' => self::YEAR . '-02-05', 'currency' => 'CZK', 'amount' => 300,
            'posting_lines' => [['account_code' => '568', 'amount' => 300]],
        ], $this->userId);
        $res = $this->callAction($action, 'update', 'PUT', 'admin',
            ['amount' => 350, 'issued_on' => self::YEAR . '-02-01'], ['id' => (string) $single['id']]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $stored = $service->get($this->supplierId, (int) $single['id']);
        self::assertSame([['account_code' => '568', 'amount' => 350.0]], $stored['posting_lines'], 'Jediný protiúčet jde s novou částkou.');
        self::assertSame(self::YEAR . '-01-30', $stored['accounting_on'], 'Odlišné datum zaúčtování zůstává.');
    }

    public function testForeignCashVatDraftEditsWithoutResendingLines(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([self::YEAR . '-06-15', 'EUR', 25.00]);
        $registerId = $this->container->get(CashRegisterService::class)->create($this->supplierId, [
            'name' => 'EUR pokladna DPH', 'account_code' => '211601', 'currency_code' => 'EUR', 'is_default' => false,
        ]);
        $created = $this->container->get(CashDocumentService::class)->create($this->supplierId, [
            'register_id' => $registerId, 'doc_type' => 'in', 'purpose' => 'sale',
            'issue_date' => self::YEAR . '-06-15', 'description' => 'Prodej v EUR', 'vat_mode' => 'vat',
            'amount_foreign' => 121.00, 'fx_rate' => 24.37,
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 100.00, 'vat_amount' => 21.00]],
            'post' => false,
        ], $this->userId);
        $id = (int) $created['id'];
        $docs = $this->container->get(CashDocumentRepository::class);
        $before = $docs->find($this->supplierId, $id);
        $linesBefore = $docs->vatLinesFor($id);
        $action = $this->container->get(CashDocumentAction::class);

        $res = $this->callAction($action, 'update', 'PUT', 'admin', ['description' => 'Prodej zboží v EUR'], ['id' => (string) $id]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $doc = $docs->find($this->supplierId, $id);
        self::assertSame('Prodej zboží v EUR', $doc['description']);
        self::assertEqualsWithDelta((float) $before['total_amount'], (float) $doc['total_amount'], 0.001);
        self::assertEqualsWithDelta(24.37, (float) $doc['fx_rate'], 0.000001);
        self::assertEquals($linesBefore, $docs->vatLinesFor($id), 'Rozpad DPH v CZK zůstává beze změny.');

        $res = $this->callAction($action, 'update', 'PUT', 'admin', ['amount_foreign' => 242.00], ['id' => (string) $id]);
        self::assertSame(422, $res['status'], 'Změněná částka bez nového rozpadu se odmítne.');
    }

    private function statementNote(): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT content FROM statement_notes WHERE supplier_id = ? AND fiscal_year = ? AND section_key = ?'
        );
        $stmt->execute([$this->supplierId, self::YEAR, 'accounting_principles']);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** @return array{rate:float, note:?string} */
    private function repoRate(string $date): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT rate, note FROM cnb_repo_rates WHERE valid_from = ?');
        $stmt->execute([$date]);
        $row = (array) $stmt->fetch(PDO::FETCH_ASSOC);
        return ['rate' => (float) $row['rate'], 'note' => $row['note'] !== null ? (string) $row['note'] : null];
    }

    private function accountIdByCode(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    private function project(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO projects (client_id, name, currency_id) VALUES (?, ?, ?)')
            ->execute([$this->client('Zákazník zakázky s.r.o.'), 'Syntetická zakázka', $this->currencyId]);
        return (int) $pdo->lastInsertId();
    }
}
