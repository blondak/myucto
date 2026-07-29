<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\OffsetRepository;
use MyInvoice\Service\Accounting\OffsetException;
use MyInvoice\Service\Accounting\OffsetService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\OffsetAgreementPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

/**
 * Vzájemné zápočty pohledávek a závazků (Fáze F).
 *
 *   GET  /api/accounting/offsets                 — historie dohod (?status=)
 *   GET  /api/accounting/offsets/partners        — partneři s otevřenou pohledávkou i závazkem
 *   GET  /api/accounting/offsets/open?partner_id — otevřené FV+PF partnera
 *   POST /api/accounting/offsets                 — sestavení dohody (draft) — účetní|admin
 *   GET  /api/accounting/offsets/{id}            — detail dohody
 *   POST /api/accounting/offsets/{id}/confirm    — potvrzení (zaúčtování 321/311 + vyrovnání)
 *   POST /api/accounting/offsets/{id}/cancel     — zrušení (storno + odvolání vyrovnání)
 *   GET  /api/accounting/offsets/{id}/pdf        — PDF dohody o zápočtu
 */
final class OffsetAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly OffsetService $offsets,
        private readonly OffsetRepository $repo,
        private readonly OffsetAgreementPdfRenderer $pdf,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $status = trim((string) ($request->getQueryParams()['status'] ?? ''));
        return Json::ok($response, ['items' => $this->offsets->list($supplierId, $status !== '' ? ['status' => $status] : [])]);
    }

    public function partners(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, ['items' => $this->repo->partnersWithOpenBoth($supplierId)]);
    }

    public function open(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $partnerId = (int) ($request->getQueryParams()['partner_id'] ?? 0);
        if ($partnerId <= 0) {
            return Json::error($response, 'validation_failed', 'partner_id je povinný.', 422);
        }
        try {
            return Json::ok($response, $this->offsets->openItemsForPartner($supplierId, $partnerId));
        } catch (OffsetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        try {
            return Json::ok($response, $this->offsets->build($supplierId, (int) ($args['id'] ?? 0)));
        } catch (OffsetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $partnerId = (int) ($body['partner_id'] ?? 0);
        $date = trim((string) ($body['agreement_date'] ?? ''));
        $note = $this->nullableString($body['note'] ?? null);
        $rawItems = $body['items'] ?? null;
        if ($partnerId <= 0) {
            return Json::error($response, 'validation_failed', 'partner_id je povinný.', 422);
        }
        if (!is_array($rawItems) || $rawItems === []) {
            return Json::error($response, 'validation_failed', 'items musí obsahovat aspoň jeden doklad.', 422);
        }
        $items = array_map(static fn ($it): array => [
            'doc_type' => (string) (($it['doc_type'] ?? '')),
            'doc_id'   => (int) (($it['doc_id'] ?? 0)),
            'amount'   => (float) (($it['amount'] ?? 0)),
        ], array_values(array_filter($rawItems, 'is_array')));

        try {
            $result = $this->offsets->create($supplierId, $partnerId, $date, $items, $note, $this->userId($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e);
        }

        $this->log($request, 'accounting.offset_created', (int) $result['agreement']['id'], [
            'document_no' => $result['agreement']['document_no'],
            'total'       => $result['agreement']['total_amount'],
        ], $supplierId);

        return Json::ok($response, $result, 201);
    }

    public function confirm(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        try {
            $result = $this->offsets->confirm($supplierId, $id, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e);
        }

        $this->log($request, 'accounting.offset_confirmed', $id, [
            'journal_entry_id' => $result['agreement']['journal_entry_id'] ?? null,
        ], $supplierId);

        return Json::ok($response, $result);
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        try {
            $result = $this->offsets->cancel($supplierId, $id, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e);
        }

        $this->log($request, 'accounting.offset_cancelled', $id, [], $supplierId);
        return Json::ok($response, $result);
    }

    public function pdf(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        try {
            $data = $this->offsets->build($supplierId, $id);
        } catch (OffsetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $data['entity']  = $this->loadParty('supplier', $supplierId);
        $data['partner'] = $this->loadParty('client', (int) $data['agreement']['partner_id']);

        $bytes = $this->pdf->render($data);
        $filename = 'dohoda-o-zapoctu-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $data['agreement']['document_no']) . '.pdf';
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    // ── interní ────────────────────────────────────────────────────────────────

    private function mapError(Response $response, \Throwable $e): Response
    {
        if ($e instanceof OffsetException) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if ($e instanceof UnbalancedEntryException) {
            return Json::error($response, 'unbalanced_entry', $e->getMessage(), 422);
        }
        if ($e instanceof PostingException) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        throw $e;
    }

    /**
     * @return array{name:string, ic:?string, address:string}
     */
    private function loadParty(string $kind, int $id): array
    {
        $table = $kind === 'supplier' ? 'supplier' : 'clients';
        $stmt = $this->db->pdo()->prepare(
            "SELECT company_name, ic, street, city, zip FROM {$table} WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $address = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim(trim((string) ($row['zip'] ?? '')) . ' ' . trim((string) ($row['city'] ?? ''))),
        ], static fn (string $p): bool => $p !== '');
        return [
            'name'    => (string) ($row['company_name'] ?? ''),
            'ic'      => $row['ic'] ?? null,
            'address' => implode(', ', $address),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload, int $supplierId): void
    {
        $this->activity->log(
            $action,
            $this->userId($request),
            'offset_agreement',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
