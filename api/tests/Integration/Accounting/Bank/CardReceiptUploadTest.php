<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Bank\CardPaymentOverviewAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Service\PurchaseInvoice\SubmissionFolder;
use MyInvoice\Tests\Support\FakeLlmGateway;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response as Psr7Response;
use Slim\Psr7\UploadedFile;

/**
 * Nahrát účtenku k platbě kartou bez AI: vytěžení selže (AI není nastavená), účtenka
 * se ale neztratí — uloží se jako příchozí doklad do Dokumentů (Příchozí doklady / rok /
 * měsíc) navázaný na platbu, stejně jako upload do Nákup → Příchozí doklady.
 */
#[Group('integration')]
final class CardReceiptUploadTest extends BankPostingTestCase
{
    public function testReceiptWithoutAiIsStoredAsIncomingDocumentLinkedToPayment(): void
    {
        /** @var \DI\Container $container */
        $container = $this->container;
        $llm = new FakeLlmGateway(static fn (string $bytes): ?array => null, false);
        $container->set(LlmGatewayInterface::class, $llm);

        $tx = $this->transaction($this->statement(), -250.00, ['description' => 'Platba kartou | d.tran. 14.06.2099']);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '4321' WHERE id = ?")->execute([$tx]);
        $bytes = "%PDF-1.4\n% synteticka uctenka " . uniqid('', true) . "\n%%EOF\n";

        $res = $this->upload($tx, $bytes);
        self::assertSame(201, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('incoming', $res['body']['stored'] ?? null);
        self::assertSame(implode(' / ', SubmissionFolder::segments(new \DateTimeImmutable())), $res['body']['folder']);
        self::assertFalse($res['body']['duplicate']);

        $row = $this->db->pdo()->prepare(
            'SELECT s.bank_transaction_id, s.document_kind_hint, s.submitted_via, s.note, d.folder_id
               FROM purchase_invoice_submissions s JOIN documents d ON d.id = s.document_id
              WHERE s.id = ? AND s.supplier_id = ?'
        );
        $row->execute([(int) $res['body']['submission_id'], $this->supplierId]);
        $submission = $row->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($submission);
        self::assertSame($tx, (int) $submission['bank_transaction_id'], 'Příchozí doklad je navázaný na platbu kartou.');
        self::assertSame('receipt', $submission['document_kind_hint']);
        self::assertSame('staff', $submission['submitted_via']);
        self::assertStringContainsString('•••• 4321', (string) $submission['note']);
        self::assertNotNull($submission['folder_id'], 'Originál leží ve složce Příchozí doklady / rok / měsíc, ne v kořeni.');

        $again = $this->upload($tx, $bytes);
        self::assertSame(201, $again['status']);
        self::assertTrue($again['body']['duplicate'], 'Stejná účtenka se neuloží dvakrát.');
        self::assertSame($res['body']['submission_id'], $again['body']['submission_id']);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function upload(int $txId, string $bytes): array
    {
        $stream = (new StreamFactory())->createStream($bytes);
        $file = new UploadedFile($stream, 'uctenka.pdf', 'application/pdf', strlen($bytes), UPLOAD_ERR_OK);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payment-cards/unmatched-payments/' . $txId . '/receipt')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole($this->userId, 'Test', 'staff', true, ['purchase_invoices.scan' => 2]))
            ->withUploadedFiles(['pdf' => $file]);
        $response = $this->container->get(CardPaymentOverviewAction::class)
            ->uploadReceipt($request, new Psr7Response(), ['id' => (string) $txId]);
        $response->getBody()->rewind();
        return ['status' => $response->getStatusCode(), 'body' => (array) json_decode((string) $response->getBody(), true)];
    }
}
