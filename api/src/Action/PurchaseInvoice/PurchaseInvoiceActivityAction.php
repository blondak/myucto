<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/{id}/activity
 *
 * Activity log pro přijatou fakturu — všechny purchase_invoice.* události
 * (created, updated, transitioned, pdf_uploaded, pdf_downloaded, pdf_deleted,
 *  exchange_rate_set, exchange_rate_reloaded, items_updated).
 *
 * Mirror invoice activity action — stejný response tvar pro frontend reuse komponenty.
 */
final class PurchaseInvoiceActivityAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $supplierId = SupplierGuard::currentId($request);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $sql = "SELECT al.id, al.user_id, u.email AS user_email, u.name AS user_name,
                       al.action, al.payload, al.ip, al.created_at
                  FROM activity_log al
             LEFT JOIN users u ON u.id = al.user_id
                 WHERE al.entity_type = 'purchase_invoice' AND al.entity_id = ?
              ORDER BY al.id DESC
                 LIMIT 200";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            if ($r['payload'] !== null) {
                $r['payload'] = json_decode((string) $r['payload'], true);
            }
            if ($r['ip'] !== null && $r['ip'] !== '') {
                $r['ip'] = @inet_ntop($r['ip']) ?: null;
            } else {
                $r['ip'] = null;
            }
            $r['id'] = (int) $r['id'];
            $r['user_id'] = $r['user_id'] !== null ? (int) $r['user_id'] : null;
        }

        return Json::ok($response, $rows);
    }
}
