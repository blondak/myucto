<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\ReminderService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SendReminderAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ReminderService $reminders,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        // Volitelné body (#60) — příjemci upravení v modalu, sémantika jako u
        // POST /send: explicitní `to` je celý seznam, samotné cc/bcc se přidají.
        $body = (array) ($request->getParsedBody() ?? []);
        $list = static fn (string $key): ?array => isset($body[$key]) && is_array($body[$key])
            ? array_values(array_filter($body[$key], 'is_string'))
            : null;

        try {
            $result = $this->reminders->send(
                $id,
                $userId,
                $ip,
                $request->getHeaderLine('User-Agent'),
                $list('to'),
                $list('cc') ?? [],
                $list('bcc') ?? [],
            );
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_state', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'invalid_email', $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return Json::error($response, 'send_failed', 'Upomínku se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        return Json::ok($response, [
            'invoice'      => $this->repo->find($id),
            'sent_to'      => $result['sent_to'],
            'cc'           => $result['cc'],
            'bcc'          => $result['bcc'],
            'days_overdue' => $result['days_overdue'],
            'sent_at'      => date('Y-m-d H:i:s'),
        ]);
    }
}
