<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\Card\CardPaymentOverview;
use MyInvoice\Service\Bank\Card\CardPaymentVehicleHints;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use MyInvoice\Service\PurchaseInvoice\SubmissionFolder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Platby kartou bez dokladu:
 *   GET  /api/payment-cards/unmatched-payments                  — přehled po držitelích (?from, ?to)
 *   POST /api/payment-cards/unmatched-payments/{id}/receipt     — nahrát účtenku (AI vytěžení)
 *   POST /api/payment-cards/unmatched-payments/{id}/rematch     — spárovat pohyb znovu
 *
 * Nahrání účtenky jde přes existující AI vytěžení přijatého dokladu
 * ({@see AiPdfExtractor::extractAndCreate()}). Vzniklý koncept dostane formu úhrady
 * „karta" a koncovku z pohybu; po kontrole a potvrzení dokladu ho spáruje „Spárovat".
 * Bez AI (nebo při selhání vytěžení) se účtenka uloží jako příchozí doklad navázaný
 * na platbu ({@see PurchaseInvoiceSubmissionUploadService}), stejně jako upload do
 * Nákup → Příchozí doklady.
 */
final class CardPaymentOverviewAction
{
    private const MAX_UPLOAD_BYTES = 32 * 1024 * 1024;
    private const DEFAULT_DAYS = 90;

    public function __construct(
        private readonly CardPaymentOverview $overview,
        private readonly AiPdfExtractor $extractor,
        private readonly StatementMatcher $matcher,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly CardPaymentVehicleHints $vehicleHints,
        private readonly \MyInvoice\Service\Accounting\Bank\BankPostingService $bankPosting,
        private readonly PurchaseInvoiceSubmissionUploadService $submissions,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $qp = $request->getQueryParams();
        $to = self::date($qp['to'] ?? null) ?? date('Y-m-d');
        $from = self::date($qp['from'] ?? null) ?? date('Y-m-d', strtotime($to . ' -' . self::DEFAULT_DAYS . ' days'));
        if ($from > $to) {
            return Json::error($response, 'validation_failed', 'Datum od nesmí být po datu do.', 422);
        }
        $creditCardId = isset($qp['credit_card_account_id']) && (int) $qp['credit_card_account_id'] > 0 ? (int) $qp['credit_card_account_id'] : null;
        return Json::ok($response, $this->vehicleHints->annotate($supplierId, $this->overview->unmatched($supplierId, $from, $to, $creditCardId)));
    }

    public function uploadReceipt(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'purchase_invoices.scan', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění nahrávat doklady.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $tx = $this->overview->findCardTransaction($supplierId, (int) ($args['id'] ?? 0));
        if ($tx === null) {
            return Json::error($response, 'not_found', 'Platba kartou nenalezena.', 404);
        }

        $file = $request->getUploadedFiles()['pdf'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Nahrajte účtenku (PDF nebo fotografii) v poli "pdf".', 400);
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return Json::error($response, 'file_too_large', 'Soubor musí mít nejvýše 32 MB.', 413);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $bytes = (string) $file->getStream()->getContents();
        $filename = $file->getClientFilename() ?: null;
        $result = $this->extractor->extractAndCreate($supplierId, $userId, $bytes, null, $filename);

        // Bez AI (nebo když vytěžení selže) se účtenka neztratí: uloží se jako příchozí
        // doklad do Dokumentů (Příchozí doklady / rok / měsíc) navázaný na platbu kartou
        // a počká ve frontě Nákup → Příchozí doklady na zpracování.
        if (!$result['ok']) {
            return $this->storeAsIncoming($request, $response, $supplierId, $userId, $tx, $bytes, $filename, (string) ($result['error'] ?? ''));
        }

        $purchaseId = isset($result['purchase_invoice_id']) ? (int) $result['purchase_invoice_id'] : 0;
        $marked = $result['ok'] && $purchaseId > 0 && empty($result['duplicate'])
            && $this->overview->markReceiptPaidByCard($supplierId, $purchaseId, $tx['card_last4']);

        $this->logger->log('payment_card.receipt_uploaded', $userId ?: null, 'purchase_invoice', $purchaseId ?: null, [
            'bank_transaction_id' => $tx['id'],
            'card_last4'          => $tx['card_last4'],
            'ok'                  => $result['ok'],
            'source'              => $result['source'] ?? null,
            'duplicate'           => !empty($result['duplicate']),
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'purchase_invoice_id' => $purchaseId,
            'duplicate'           => !empty($result['duplicate']),
            'marked_as_card'      => $marked,
            'bank_transaction_id' => $tx['id'],
        ], 201);
    }

    /**
     * @param array{id:int, posted_at:string, amount:float, card_last4:?string} $tx
     */
    private function storeAsIncoming(
        Request $request,
        Response $response,
        int $supplierId,
        int $userId,
        array $tx,
        string $bytes,
        ?string $filename,
        string $extractionError,
    ): Response {
        $note = 'Účtenka k platbě kartou ' . date('d.m.Y', (int) strtotime($tx['posted_at']))
            . ', ' . number_format(abs((float) $tx['amount']), 2, ',', ' ') . ' Kč'
            . ($tx['card_last4'] !== null ? ', karta •••• ' . $tx['card_last4'] : '') . '.';
        try {
            $stored = $this->submissions->submitBytes(
                $bytes,
                $filename ?? ('uctenka-' . $tx['id'] . '.pdf'),
                $supplierId,
                $userId ?: null,
                'staff',
                $note,
                'receipt',
                (int) $tx['id'],
            );
        } catch (PurchaseInvoiceSubmissionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $submissionId = (int) $stored['submission']['id'];
        $this->logger->log('payment_card.receipt_stored', $userId ?: null, 'purchase_invoice_submission', $submissionId, [
            'bank_transaction_id' => $tx['id'],
            'card_last4'          => $tx['card_last4'],
            'duplicate'           => $stored['duplicate'],
            'extraction_error'    => $extractionError !== '' ? mb_substr($extractionError, 0, 300) : null,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'stored'              => 'incoming',
            'submission_id'       => $submissionId,
            'duplicate'           => $stored['duplicate'],
            'folder'              => implode(' / ', SubmissionFolder::segments(new \DateTimeImmutable())),
            'bank_transaction_id' => $tx['id'],
        ], 201);
    }

    public function rematch(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $tx = $this->overview->findCardTransaction($supplierId, (int) ($args['id'] ?? 0));
        if ($tx === null) {
            return Json::error($response, 'not_found', 'Platba kartou nenalezena.', 404);
        }
        $result = $this->matcher->matchBatch([$tx['id']])[$tx['id']] ?? ['status' => 'unmatched'];
        // Spárování ze seznamu plateb kartou vede na totéž zaúčtování a vypořádání jako
        // import a ruční párování (platba 378.x/221, vypořádání 321/378.x).
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $posting = $this->bankPosting->handleTransaction($tx['id'], ((int) ($user['id'] ?? 0)) ?: null);
        return Json::ok($response, ['bank_transaction_id' => $tx['id'], 'result' => $result, 'posting' => $posting]);
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }
}
