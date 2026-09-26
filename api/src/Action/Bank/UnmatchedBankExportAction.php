<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\UnmatchedBankExportService;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\MailDeliveredArchiveException;
use MyInvoice\Service\Mail\SupplierEmailContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UnmatchedBankExportAction
{
    public function __construct(
        private readonly UnmatchedBankExportService $export,
        private readonly DocumentRequestRepository $documents,
        private readonly DocumentStorage $storage,
        private readonly Mailer $mailer,
        private readonly SupplierEmailContext $emailContext,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function download(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        try {
            $file = $this->export->build($supplierId, (int) ($args['id'] ?? 0));
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }
        $response->getBody()->write($file['bytes']);
        return $response->withHeader('Content-Type', UnmatchedBankExportService::MIME)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function recipients(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        try {
            $file = $this->export->preview($supplierId, (int) ($args['id'] ?? 0));
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }
        return Json::ok($response, [
            'to' => $this->documents->clientRecipientEmails($supplierId),
            'count' => $file['count'],
            'account' => $file['account'],
        ]);
    }

    public function send(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if (($body['confirmed'] ?? false) !== true) {
            return Json::error($response, 'confirmation_required', 'Potvrďte odeslání klientovi.', 422);
        }
        try {
            $file = $this->export->build($supplierId, (int) ($args['id'] ?? 0));
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }
        if ($file['count'] === 0) {
            return Json::error($response, 'no_unmatched_transactions', 'Výpis nemá žádné skutečně nespárované pohyby.', 422);
        }
        $to = $this->documents->clientRecipientEmails($supplierId);
        if ($to === []) {
            return Json::error($response, 'no_recipients', 'Firma nemá aktivního klientského uživatele s e-mailem.', 422);
        }
        $supplier = $this->emailContext->forSupplier($supplierId);
        if ($supplier === null) {
            return Json::error($response, 'not_found', 'Firma nenalezena.', 404);
        }

        $path = $this->storage->tmpPath($supplierId);
        file_put_contents($path, $file['bytes']);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $deferred = false;
        try {
            $result = $this->mailer->sendTemplateDetailed('bank_unmatched', 'cs', $to, [
                'account' => $file['account'],
                'count' => $file['count'],
                'supplier' => $supplier,
            ], 'Nespárované bankovní pohyby', [], [], [[
                'path' => $path,
                'name' => $file['filename'],
                'contentType' => UnmatchedBankExportService::MIME,
            ]], $userId);
            $deferred = ($result['deferred'] ?? false) === true;
        } catch (MailDeliveredArchiveException) {
        } catch (\Throwable $e) {
            @unlink($path);
            return Json::error($response, 'send_failed', 'E-mail se nepodařilo odeslat.', 502);
        }
        if (!$deferred) @unlink($path);
        $this->logger->log('bank.unmatched_export_sent', $userId, 'bank_statement', (int) $args['id'], [
            'to' => $to,
            'count' => $file['count'],
            'deferred' => $deferred,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['sent_to' => $to, 'count' => $file['count'], 'deferred' => $deferred]);
    }
}
