<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\PostingRuleChartAlignmentService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Náhled „Doplnit podle osnovy" a přesměr syntetiky na jedinou analytiku v enginu musí
 * znát TÝŽ seznam vyhrazených analytik (PostingService::dedicatedAnalyticSql).
 *
 * RED před sjednocením: analytika mezičlenu platební karty 378.101 jako jediná pod 378
 * se v náhledu hlásila jako `auto` (engine přesměruje na 378.101), přestože engine ji
 * z přesměru vylučuje — náhled tvrdil jiný zápis, než jaký by vznikl.
 */
#[Group('integration')]
final class PostingRuleAlignmentDedicatedAnalyticTest extends BankPostingTestCase
{
    public function testPaymentCardAnalyticIsNotOfferedAsSingleAnalyticRedirect(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("DELETE FROM chart_of_accounts WHERE supplier_id = ? AND account_code LIKE '378.%'
                        AND NOT EXISTS (SELECT 1 FROM journal_entry_lines l WHERE l.account_id = chart_of_accounts.id)")
            ->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO payment_cards (supplier_id, label, last4, analytic_suffix) VALUES (?, 'Karta test', '4321', '101')")
            ->execute([$this->supplierId]);
        $parent = $this->accounts->findByCode($this->supplierId, '378');
        self::assertNotNull($parent);
        $this->accounts->insert($this->supplierId, [
            'account_code' => '378.101', 'name' => 'Karta ****4321', 'account_type' => 'asset',
            'normal_side' => 'debit', 'is_synthetic' => false, 'parent_id' => (int) $parent['id'], 'is_active' => true,
        ]);
        $children = (int) $pdo->query(
            "SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code LIKE '378.%' AND is_active = 1"
        )->fetchColumn();
        if ($children !== 1) {
            self::markTestSkipped('Testovací osnova nese pod 378 další analytiky s historií.');
        }
        $rules = $this->container->get(PostingRuleRepository::class);
        $ruleKey = (string) array_key_first($rules->effectiveMap($this->supplierId));
        $rule = $rules->resolve($this->supplierId, $ruleKey);
        $rules->upsertOverride($this->supplierId, $ruleKey, '378', (string) ($rule['credit_account_code'] ?? '') ?: null, (string) ($rule['description'] ?? $ruleKey));

        $preview = $this->container->get(PostingRuleChartAlignmentService::class)->preview($this->supplierId);

        $row = array_values(array_filter($preview['rules'], static fn (array $r): bool => $r['rule_key'] === $ruleKey))[0] ?? null;
        self::assertNotNull($row);
        self::assertSame('378', $row['debit']['effective_code'], 'Engine na analytiku karty nepřesměruje — náhled nesmí tvrdit opak.');
        self::assertNotSame(PostingRuleChartAlignmentService::STATUS_AUTO, $row['debit']['status']);
    }
}
