<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupBankPostingRulesProjectionTest extends TestCase
{
    public function testRemapsRuleReferencesAndDisablesAutomationWithoutLosingSettings(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:bank_posting_rules');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $projection->assertRegistryTargets($registry);
        $row = array_replace(array_fill_keys($projection->dataColumns, null), [
            'id' => 51, 'supplier_id' => 7, 'created_by' => 9,
            'last_rejected_tx_id' => 41, 'system_template_key' => 'synthetic_fee',
            'debit_account_code' => '568', 'credit_account_code' => '221',
            'mode' => 'auto', 'is_active' => 1, 'hit_count' => 12,
            'approved_streak' => 4, 'message_contains' => 'synthetic fee',
        ]);
        $projection->assertCompleteSourceRow($row);
        $mapped = $projection->references->remap($row,
            static fn (CompanyBackupReference $reference, array $values): array => match ($reference->target) {
                'table:supplier' => [71],
                'table:users' => [91],
                'table:bank_transactions' => [401],
                'table:chart_of_accounts' => [71, $values[1]],
                'table:bank_rule_templates' => $values,
                default => throw new \LogicException('Neočekávaná reference.'),
            });
        $restored = $projection->restoreOverrides->apply($mapped);
        self::assertSame(71, $restored['supplier_id']);
        self::assertSame(91, $restored['created_by']);
        self::assertSame(401, $restored['last_rejected_tx_id']);
        self::assertSame('synthetic_fee', $restored['system_template_key']);
        self::assertSame('568', $restored['debit_account_code']);
        self::assertSame('221', $restored['credit_account_code']);
        self::assertSame(0, $restored['is_active']);
        self::assertSame('auto', $restored['mode']);
        self::assertSame(12, $restored['hit_count']);
        self::assertSame(4, $restored['approved_streak']);
        self::assertSame(1, $row['is_active']);
        $template = $registry->definition('table:bank_rule_templates');
        self::assertNotNull($template);
        self::assertSame(TenantDataPolicy::GlobalReference, $template->policy);
        self::assertSame(['template_key'], $template->details['natural_key']);
    }
}
