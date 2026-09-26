<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class DocumentRepostRedStornoTest extends BankPostingTestCase
{
    private function redPurchase(): array
    {
        $docId = $this->purchaseInvoice('SYN-RED-REPOST', $this->client('Syntetický dodavatel'), -1000.0, 'credit_note');
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $docId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000.0, 'is_red_storno' => true],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.0, 'is_red_storno' => true],
        ], ['entry_date' => self::YEAR . '-06-10', 'user_id' => $this->userId]);
        return [$docId, $entryId];
    }

    private function request(string $method, array $lines = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/accounting/journal/repost')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['accounting' => 2]))
            ->withParsedBody(['lines' => $lines]);
    }

    private function correctedLines(): array
    {
        return [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 1000.0, 'is_red_storno' => true],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.0, 'is_red_storno' => true],
        ];
    }

    public function testPlanPreservesRedStornoInOriginalLines(): void
    {
        [$docId] = $this->redPurchase();
        $response = $this->container->get(JournalAction::class)->repostPlan(
            $this->request('GET'), new Response(), ['source' => 'purchase-invoices', 'id' => (string) $docId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $plan = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([true, true], array_column($plan['lines'], 'is_red_storno'));
    }

    public function testRepostPreservesNegativeAccountingAndForeignEffect(): void
    {
        [$docId, $entryId] = $this->redPurchase();
        $this->db->pdo()->prepare("UPDATE journal_entry_lines SET currency_code = 'EUR', fx_rate = 25, amount_foreign = 40 WHERE entry_id = ? AND side = 'credit'")->execute([$entryId]);
        $response = $this->container->get(JournalAction::class)->repost(
            $this->request('POST', $this->correctedLines()), new Response(), ['source' => 'purchase-invoices', 'id' => (string) $docId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $lines = $this->journal->linesForEntry($entryId, $this->supplierId);
        self::assertSame([true, true], array_column($lines, 'is_red_storno'));
        $stmt = $this->db->pdo()->prepare('SELECT side, signed_amount, signed_amount_foreign FROM journal_entry_lines WHERE entry_id = ? ORDER BY line_no');
        $stmt->execute([$entryId]);
        $saved = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['debit', 'credit'], array_column($saved, 'side'));
        self::assertSame([-1000.0, -1000.0], array_map('floatval', array_column($saved, 'signed_amount')));
        self::assertSame(-40.0, (float) $saved[1]['signed_amount_foreign']);
    }

    public function testLockedPlanAllowsNeutralRedReclassification(): void
    {
        [$docId] = $this->redPurchase();
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplierId, self::YEAR . '-06-30']);
        $response = $this->container->get(JournalAction::class)->repostPlan(
            $this->request('POST', $this->correctedLines()), new Response(), ['source' => 'purchase-invoices', 'id' => (string) $docId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $plan = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('replace', $plan['strategy']);
        self::assertSame('tax_neutral_rewrite', $plan['reason_code']);
        self::assertNull($plan['tax_neutral_violation']);
        self::assertFalse($plan['date_shifted']);
    }

    public function testRepostRejectsNonBooleanStornoFlag(): void
    {
        [$docId] = $this->redPurchase();
        $lines = $this->correctedLines();
        $lines[0]['is_red_storno'] = 'false';
        $response = $this->container->get(JournalAction::class)->repost(
            $this->request('POST', $lines), new Response(), ['source' => 'purchase-invoices', 'id' => (string) $docId],
        );
        self::assertSame(422, $response->getStatusCode());
    }
}
