<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Accounting\Bank\AutoPostingPolicyAction;
use MyInvoice\Action\Accounting\Bank\BankRuleTemplateAction;
use MyInvoice\Repository\PostingRuleRepository;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AutoPostingPolicyApiTest extends BankPostingTestCase
{
    public function testPolicyPutAppliesPresetThenExplicitRows(): void
    {
        $action = $this->container->get(AutoPostingPolicyAction::class);
        $result = $this->callAction($action, 'put', 'PUT', 'admin', [
            'automation_level' => 'assisted',
            'automation_daily_limit_czk' => 25000,
            'automation_digest_enabled' => true,
            'rows' => [['operation_type' => 'bank.fee', 'level' => 'off']],
        ]);
        self::assertSame(200, $result['status']);
        self::assertSame('assisted', $result['body']['automation_level']);
        self::assertEqualsWithDelta(25000.0, (float) $result['body']['automation_daily_limit_czk'], 0.001);
        self::assertTrue($result['body']['automation_digest_enabled']);
        $rows = array_column($result['body']['rows'], null, 'operation_type');
        self::assertSame('off', $rows['bank.fee']['level']);
        self::assertSame('auto', $rows['bank.transfer.own']['effective_level']);
    }

    public function testPolicyRejectsAiAutoAndReadOnlyWrite(): void
    {
        $action = $this->container->get(AutoPostingPolicyAction::class);
        $invalid = $this->callAction($action, 'put', 'PUT', 'admin', [
            'rows' => [['operation_type' => 'ai.classify.bank', 'level' => 'auto']],
        ]);
        self::assertSame(422, $invalid['status']);
        self::assertSame('ai_auto_forbidden', $invalid['body']['error']['code']);

        $readonly = $this->callAction($action, 'put', 'PUT', 'readonly', ['automation_level' => 'off']);
        self::assertSame(403, $readonly['status']);
    }

    public function testTemplateInstantiationResolvesSupplierVariableSymbolAndIsUnique(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET cssz_vsdp='87654321' WHERE id=?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "DELETE FROM bank_posting_rules WHERE supplier_id=? AND system_template_key='remit.social.own'"
        )->execute([$this->supplierId]);
        $action = $this->container->get(BankRuleTemplateAction::class);
        $created = $this->callAction(
            $action,
            'instantiate',
            'POST',
            'admin',
            ['amount_min' => 100, 'amount_max' => 10000],
            ['key' => 'remit.social.own'],
        );
        self::assertSame(201, $created['status']);
        self::assertSame('suggest', $created['body']['rule']['mode']);
        self::assertSame('87654321', $created['body']['rule']['variable_symbol']);
        self::assertSame('remit.social.own', $created['body']['rule']['system_template_key']);

        $duplicate = $this->callAction(
            $action,
            'instantiate',
            'POST',
            'admin',
            [],
            ['key' => 'remit.social.own'],
        );
        self::assertSame(409, $duplicate['status']);
        self::assertSame('template_already_instantiated', $duplicate['body']['error']['code']);
    }

    public function testTemplateVariableSymbolOverrideFillsMissingSupplierSetting(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET cssz_vsdp=NULL WHERE id=?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "DELETE FROM bank_posting_rules WHERE supplier_id=? AND system_template_key='remit.social.own'"
        )->execute([$this->supplierId]);
        $action = $this->container->get(BankRuleTemplateAction::class);

        $missing = $this->callAction($action, 'instantiate', 'POST', 'admin', [], ['key' => 'remit.social.own']);
        self::assertSame(422, $missing['status']);
        self::assertSame('placeholder_missing', $missing['body']['error']['code']);

        $created = $this->callAction(
            $action,
            'instantiate',
            'POST',
            'admin',
            ['variable_symbol' => '123 456'],
            ['key' => 'remit.social.own'],
        );
        self::assertSame(201, $created['status']);
        self::assertSame('123456', $created['body']['rule']['variable_symbol']);
    }

    public function testTemplateRejectsSaldoPostingRuleOverride(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET cssz_vsdp='87654321' WHERE id=?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "DELETE FROM bank_posting_rules WHERE supplier_id=? AND system_template_key='remit.social.own'"
        )->execute([$this->supplierId]);
        $this->container->get(PostingRuleRepository::class)->upsertOverride(
            $this->supplierId,
            'insurance.social.paid',
            '321',
            '221',
            'Neplatná testovací kontace',
        );

        $result = $this->callAction(
            $this->container->get(BankRuleTemplateAction::class),
            'instantiate',
            'POST',
            'admin',
            [],
            ['key' => 'remit.social.own'],
        );
        self::assertSame(422, $result['status']);
        self::assertSame('rule_account_forbidden', $result['body']['error']['code']);
    }
}
