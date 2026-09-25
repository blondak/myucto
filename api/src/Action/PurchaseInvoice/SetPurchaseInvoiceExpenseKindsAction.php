<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/purchase-invoices/{id}/expense-kinds — druh nákladu po položkách.
 *
 * Body: { items: [{ id: number, expense_kind: string|null }] }
 *
 * Kontrolní okno po AI importu potvrzuje návrhy druhu nákladu faktura po faktuře.
 * Plný PUT by musel poslat celý doklad (a u zaplaceného dokladu z importu je navíc
 * dostupný jen přes vynucenou úpravu), proto úzký endpoint jen na tuhle klasifikaci.
 *
 * Pojistky jsou TYTÉŽ jako u vynucené úpravy ({@see UpdatePurchaseInvoiceAction}):
 *   - zámek dokladu (uzavřené období, podané přiznání) se kontroluje první;
 *   - koncept smí upravit každý, kdo smí upravovat přijaté faktury, jiný stav jen admin firmy;
 *   - storno je neměnné;
 *   - zaúčtovaný doklad v otevřeném období se přeúčtuje, v uzavřeném se odmítne
 *     (oprava tam vyžaduje reconcile v editoru);
 *   - evidence drobného majetku se srovná s novou klasifikací (mimo koncept).
 *
 * Volba je ruční rozhodnutí účetní: provenience automatu (pravidlo, zdroj) se smaže a účet,
 * který na řádek dosadil automat, s ní — jinak by starý účet přebil nově zvolený druh.
 * Účet zvolený ručně zůstává, ten je silnější než druh (viz PostingService).
 */
final class SetPurchaseInvoiceExpenseKindsAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Connection $db,
        private readonly DocumentLockService $locks,
        private readonly DocumentJournalSync $journalSync,
        private readonly SmallAssetService $smallAssets,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $lock = $this->locks->forPurchaseInvoice($existing);
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'purchase_invoice', $id)) {
            return $deny;
        }

        $status = (string) ($existing['status'] ?? '');
        if ($status === 'cancelled') {
            return Json::error($response, 'not_editable', 'Stornovaný doklad nelze upravit.', 409);
        }
        if ($status !== 'draft' && !RequestAuthorization::isCompanyAdmin($request)) {
            return Json::error($response, 'not_editable',
                "Druh nákladu u dokladu ve stavu '{$status}' může změnit jen administrátor firmy.", 409);
        }
        if ($lock->posted && $lock->inClosedPeriod) {
            return Json::error($response, 'posted_in_closed_period',
                'Doklad je zaúčtovaný v uzavřeném období. Druh nákladu opravte v editoru (vynucená úprava s dorovnáním).',
                409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $requested = self::parseItems($body['items'] ?? null);
        if ($requested === null) {
            return Json::error($response, 'validation_failed', 'Neplatný seznam položek.', 400, [
                'fields' => ['items' => 'Očekává se pole {id, expense_kind} s platným druhem nákladu.'],
            ]);
        }

        $current = [];
        foreach ((array) ($existing['items'] ?? []) as $item) {
            $current[(int) $item['id']] = $item;
        }
        $changes = [];
        foreach ($requested as $itemId => $kind) {
            if (!isset($current[$itemId])) {
                return Json::error($response, 'invalid_reference', 'Položka nepatří k tomuto dokladu.', 400);
            }
            $from = ($current[$itemId]['expense_kind'] ?? null) ?: null;
            if ($from !== $kind) {
                $changes[$itemId] = ['from' => $from, 'to' => $kind];
            }
        }
        if ($changes === []) {
            return Json::ok($response, $existing);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE purchase_invoice_items
                    SET expense_kind = ?,
                        is_fixed_asset = ?,
                        expense_account_code = IF(expense_classification_source IS NULL, expense_account_code, NULL),
                        expense_rule_id = NULL,
                        expense_classification_source = NULL
                  WHERE id = ? AND purchase_invoice_id = ?'
            );
            foreach ($changes as $itemId => $change) {
                $stmt->execute([$change['to'], $change['to'] === ExpenseKind::FixedAsset->value ? 1 : 0, $itemId, $id]);
            }
            if ($ownTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $repostedEntryId = null;
        if ($lock->posted) {
            $updated = $this->repo->find($id, $supplierId) ?? $existing;
            try {
                $repostedEntryId = $this->journalSync->repostForceEdit($supplierId, 'purchase_invoice', $id, [
                    'entry_date' => (string) ($updated['tax_date'] ?? $updated['issue_date']),
                    'document_date' => (string) ($updated['tax_date'] ?? $updated['issue_date']),
                    'document_no' => (string) ($updated['varsymbol'] ?? ''),
                    'user_id' => $user['id'] ?? null, 'posted_by' => $user['id'] ?? null,
                    'ip' => $ip, 'user_agent' => $request->getHeaderLine('User-Agent'),
                ]);
            } catch (PostingException | UnbalancedEntryException $e) {
                $code = $e instanceof PostingException ? $e->errorCode : 'unbalanced_entry';
                $httpStatus = $e instanceof PostingException ? $e->httpStatus : 422;
                return Json::error($response, $code,
                    'Druh nákladu je uložený, ale přeúčtování deníku selhalo: ' . $e->getMessage()
                        . ' Deník dorovnej ručně.',
                    $httpStatus);
            }
        }

        if ($status !== 'draft') {
            try {
                $this->smallAssets->syncFromPurchaseInvoice($supplierId, $id, $user['id'] ?? null);
            } catch (\Throwable $e) {
                $this->logger->log('purchase_invoice.small_asset_sync_failed', $user['id'] ?? null,
                    'purchase_invoice', $id, ['error' => $e->getMessage()],
                    $ip, $request->getHeaderLine('User-Agent'));
            }
        }

        $this->logger->log('purchase_invoice.expense_kinds_set', $user['id'] ?? null, 'purchase_invoice', $id, [
            'changes'          => $changes,
            'reposted_entry_id' => $repostedEntryId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $invoice = $this->repo->find($id, $supplierId);
        if ($invoice !== null && $repostedEntryId !== null) {
            $invoice['_repost'] = ['entry_id' => $repostedEntryId];
        }
        return Json::ok($response, $invoice);
    }

    /** @return array<int, ?string>|null item id => druh (null = neurčeno), null při neplatném vstupu */
    private static function parseItems(mixed $items): ?array
    {
        if (!is_array($items) || $items === []) {
            return null;
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id']) || (int) $item['id'] <= 0) {
                return null;
            }
            $raw = $item['expense_kind'] ?? null;
            $kind = ($raw === null || $raw === '') ? null : ExpenseKind::tryFrom((string) $raw);
            if ($raw !== null && $raw !== '' && $kind === null) {
                return null;
            }
            $out[(int) $item['id']] = $kind?->value;
        }
        return $out;
    }
}
