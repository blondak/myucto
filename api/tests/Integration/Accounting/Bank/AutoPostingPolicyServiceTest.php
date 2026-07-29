<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\PolicyInput;
use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AutoPostingPolicyServiceTest extends BankPostingTestCase
{
    private AutoPostingPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = $this->container->get(AutoPostingPolicyService::class);
    }

    public function testDefaultIsSuggestAndExplicitAutoPassesSafeCandidate(): void
    {
        // Čistá firma bez policy řádků — dev DB nese reálný preset „full" (bank.fee=auto),
        // takže default se musí testovat na klonu, který žádnou uloženou politiku nemá.
        // Klonu založíme období pro entry_date, jinak decide() spadne na 'period_closed'.
        $sid = $this->cloneSupplier('double_entry');
        $this->periods->create($sid, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        self::assertSame('suggest', $this->policy->levelFor($sid, OperationType::BANK_FEE));
        self::assertSame('suggest', $this->policy->decide($sid, $this->input())->decision);

        $this->policy->upsertRow($sid, OperationType::BANK_FEE, 'auto', $this->userId);
        self::assertSame('auto', $this->policy->decide($sid, $this->input())->decision);
    }

    public function testHardGuardsHavePriority(): void
    {
        $this->policy->upsertRow($this->supplierId, OperationType::BANK_FEE, 'auto', $this->userId);
        self::assertSame('blocked', $this->policy->decide($this->supplierId, $this->input(crossCurrency: true))->decision);
        self::assertSame('cross_currency', $this->policy->decide($this->supplierId, $this->input(crossCurrency: true))->note);

        $saldo = $this->input(debit: '311');
        self::assertSame('saldo_forbidden', $this->policy->decide($this->supplierId, $saldo)->note);

        $duplicate = $this->input(duplicateSuspect: true);
        self::assertSame('needs_input', $this->policy->decide($this->supplierId, $duplicate)->decision);
        self::assertSame('duplicate_suspect', $this->policy->decide($this->supplierId, $duplicate)->note);
    }

    public function testLiabilityNeedsPostedPrescription(): void
    {
        $this->policy->upsertRow($this->supplierId, OperationType::REMITTANCE_VAT, 'auto', $this->userId);
        $this->policy->upsertRow($this->supplierId, OperationType::DETECTOR_TAX_REMITTANCE, 'auto', $this->userId);
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id={$this->supplierId} AND account_code='343'"
        )->fetchColumn();
        $this->db->pdo()->prepare(
            'INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
             VALUES (?, "343999", "Testovací analytika DPH", "liability", "credit", 0, ?)'
        )->execute([$this->supplierId, $parentId]);
        $input = $this->input(operation: OperationType::REMITTANCE_VAT, debit: '343999', source: 'detector');
        $without = $this->policy->decide($this->supplierId, $input);
        self::assertSame('suggest', $without->decision);
        self::assertSame('liability_prescription_missing', $without->note);

        $this->postPredpis('manual', 991234, '548', '343999', 1000.00);
        self::assertSame('auto', $this->policy->decide($this->supplierId, $input)->decision);
    }

    public function testAiAndLowConfidenceNeverAuto(): void
    {
        $this->policy->upsertRow($this->supplierId, OperationType::BANK_FEE, 'auto', $this->userId);
        self::assertSame('suggest', $this->policy->decide($this->supplierId, $this->input(source: 'llm'))->decision);
        self::assertSame('suggest', $this->policy->decide($this->supplierId, $this->input(confidence: 0.70))->decision);
        $low = $this->policy->decide($this->supplierId, $this->input(confidence: 0.39));
        self::assertSame('needs_input', $low->decision);
        self::assertSame('low_confidence', $low->note);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessage('AI návrhy');
        $this->policy->upsertRow($this->supplierId, OperationType::AI_BANK, 'auto', $this->userId);
    }

    public function testRuleRequiresThreeHitsBandAndCap(): void
    {
        $this->policy->upsertRow($this->supplierId, OperationType::BANK_RULE_CUSTOM, 'auto', $this->userId);
        $rule = ['mode' => 'auto', 'hit_count' => 2, 'amount_min' => 1, 'amount_max' => 2000, 'auto_amount_cap' => 1500];
        self::assertSame('suggest', $this->policy->decide($this->supplierId, $this->input(operation: OperationType::BANK_RULE_CUSTOM, rule: $rule))->decision);
        $rule['hit_count'] = 3;
        self::assertSame('auto', $this->policy->decide($this->supplierId, $this->input(operation: OperationType::BANK_RULE_CUSTOM, rule: $rule))->decision);
        $over = $this->policy->decide($this->supplierId, $this->input(operation: OperationType::BANK_RULE_CUSTOM, amount: 1600, rule: $rule));
        self::assertSame('suggest', $over->decision);
        self::assertSame('amount_over_cap', $over->note);
    }

    public function testInternalTransferRuleAutoPostsWithoutBandOrHits(): void
    {
        $this->policy->upsertRow($this->supplierId, OperationType::BANK_RULE_CUSTOM, 'auto', $this->userId);
        // Termínovaný vklad = interní převod 221↔221100: žádný amplitudový pás, hit_count 0,
        // přesto auto (částka = přesný pohyb na výpisu, bez výsledkového/saldokontního dopadu).
        $transferRule = ['mode' => 'auto', 'hit_count' => 0, 'amount_min' => null, 'amount_max' => null];
        $create = $this->input(operation: OperationType::BANK_RULE_CUSTOM, debit: '221100', credit: '221', rule: $transferRule);
        self::assertSame('auto', $this->policy->decide($this->supplierId, $create)->decision);
        $close = $this->input(operation: OperationType::BANK_RULE_CUSTOM, debit: '221', credit: '221100', rule: $transferRule);
        self::assertSame('auto', $this->policy->decide($this->supplierId, $close)->decision);

        // Auto_amount_cap platí i pro interní převod.
        $capped = ['mode' => 'auto', 'hit_count' => 0, 'amount_min' => null, 'amount_max' => null, 'auto_amount_cap' => 500.0];
        $over = $this->policy->decide($this->supplierId, $this->input(
            operation: OperationType::BANK_RULE_CUSTOM, amount: 600.0, debit: '221100', credit: '221', rule: $capped));
        self::assertSame('suggest', $over->decision);
        self::assertSame('amount_over_cap', $over->note);

        // Kontrola: ne-převodové pravidlo (568/221, výsledkový účet) brzdu bez pásu/hitů drží.
        $expense = $this->input(operation: OperationType::BANK_RULE_CUSTOM, debit: '568', credit: '221', rule: $transferRule);
        self::assertSame('suggest', $this->policy->decide($this->supplierId, $expense)->decision);
    }

    public function testPeriodLockAnomalyAutoAllowedAndDailyLimitGuards(): void
    {
        $this->policy->upsertRow($this->supplierId, OperationType::BANK_FEE, 'auto', $this->userId);

        $this->db->pdo()->exec("UPDATE accounting_periods SET status='closed' WHERE id={$this->periodId}");
        $closed = $this->policy->decide($this->supplierId, $this->input());
        self::assertSame('blocked', $closed->decision);
        self::assertSame('period_closed', $closed->note);
        $this->db->pdo()->exec("UPDATE accounting_periods SET status='open' WHERE id={$this->periodId}");

        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until=VALUES(locked_until)'
        )->execute([$this->supplierId, self::YEAR . '-06-15']);
        self::assertSame('period_closed', $this->policy->decide($this->supplierId, $this->input())->note);
        $this->db->pdo()->prepare('UPDATE accounting_supplier_settings SET locked_until=NULL WHERE supplier_id=?')
            ->execute([$this->supplierId]);

        $anomaly = $this->policy->decide($this->supplierId, $this->input(anomaly: true));
        self::assertSame('suggest', $anomaly->decision);
        self::assertSame('anomaly', $anomaly->note);
        self::assertSame('suggest', $this->policy->decide($this->supplierId, $this->input(autoAllowed: false))->decision);

        $this->policy->updateSettings($this->supplierId, 999.99, false);
        $limit = $this->policy->decide($this->supplierId, $this->input());
        self::assertSame('suggest', $limit->decision);
        self::assertSame('daily_limit_reached', $limit->note);
    }

    public function testDetectorSwitchCapsEffectiveLevelAndFullPresetKeepsAiSuggest(): void
    {
        $this->policy->upsertRow($this->supplierId, OperationType::REMITTANCE_VAT, 'auto', $this->userId);
        $this->policy->upsertRow($this->supplierId, OperationType::DETECTOR_TAX_REMITTANCE, 'suggest', $this->userId);
        self::assertSame('suggest', $this->policy->levelFor($this->supplierId, OperationType::REMITTANCE_VAT));

        $this->policy->applyPreset($this->supplierId, 'full', $this->userId);
        self::assertSame('auto', $this->policy->levelFor($this->supplierId, OperationType::REMITTANCE_VAT));
        self::assertSame('suggest', $this->policy->levelFor($this->supplierId, OperationType::AI_BANK));
    }

    /**
     * `applyPreset()` zapisuje snímek typů existujících v okamžiku volání. Typ přidaný
     * do OperationType později řádek nedostane a dřív padal na natvrdo `suggest` — i u
     * firmy s presetem `full`. Na ostrých datech takhle tiše neběžely odvody pojistného
     * zaměstnavatele, přestože UI hlásilo plnou automatiku (migrace 1133).
     */
    public function testOperationTypeWithoutStoredRowInheritsSupplierPreset(): void
    {
        $sid = $this->cloneSupplier('double_entry');
        $this->policy->applyPreset($sid, 'full', $this->userId);
        $this->db->pdo()->prepare(
            'DELETE FROM auto_posting_policy WHERE supplier_id = ? AND operation_type = ?'
        )->execute([$sid, OperationType::REMITTANCE_SOCIAL_EMPLOYER]);

        self::assertSame('auto', $this->policy->levelFor($sid, OperationType::REMITTANCE_SOCIAL_EMPLOYER),
            'Chybějící řádek musí zdědit preset firmy, ne spadnout na suggest.');

        // AI a učené kontace zůstávají i v presetu „full" na suggest.
        $this->db->pdo()->prepare(
            'DELETE FROM auto_posting_policy WHERE supplier_id = ? AND operation_type = ?'
        )->execute([$sid, OperationType::AI_BANK]);
        self::assertSame('suggest', $this->policy->levelFor($sid, OperationType::AI_BANK));

        // Preset „off" nesmí chybějícím řádkem propašovat automatiku.
        $this->policy->applyPreset($sid, 'off', $this->userId);
        $this->db->pdo()->prepare(
            'DELETE FROM auto_posting_policy WHERE supplier_id = ? AND operation_type = ?'
        )->execute([$sid, OperationType::REMITTANCE_SOCIAL_EMPLOYER]);
        self::assertSame('off', $this->policy->levelFor($sid, OperationType::REMITTANCE_SOCIAL_EMPLOYER));
    }

    /** @param array<string,mixed>|null $rule */
    private function input(
        string $operation = OperationType::BANK_FEE,
        string $source = 'detector',
        float $confidence = 0.95,
        float $amount = 1000.0,
        string $debit = '568',
        string $credit = '221',
        ?array $rule = null,
        bool $crossCurrency = false,
        bool $duplicateSuspect = false,
        bool $autoAllowed = true,
        bool $anomaly = false,
    ): PolicyInput {
        return new PolicyInput(
            $operation,
            $source,
            $confidence,
            $amount,
            'CZK',
            self::YEAR . '-06-15',
            $debit,
            $credit,
            $rule,
            autoAllowed: $autoAllowed,
            crossCurrency: $crossCurrency,
            duplicateSuspect: $duplicateSuspect,
            anomaly: $anomaly,
        );
    }
}
