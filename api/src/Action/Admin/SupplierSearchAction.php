<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SupplierSearchAction
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['is_superadmin'] ?? false) !== true) {
            return Json::error($response, 'forbidden_permission', 'Pouze superadmin.', 403);
        }
        $query = $request->getQueryParams();
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '' && !ctype_digit($q) && mb_strlen($q) < 2) {
            return Json::error($response, 'validation_failed', 'Textový dotaz musí mít alespoň dva znaky.', 400);
        }
        $limit = min(50, max(1, (int) ($query['limit'] ?? 20)));
        $cursor = max(0, (int) ($query['cursor'] ?? 0));
        $params = [];
        $where = '1 = 1';
        if ($q !== '') {
            if (ctype_digit($q) && mb_strlen($q) < 2) {
                $where .= ' AND s.id = ?';
                $params[] = (int) $q;
            } else {
                $like = '%' . $q . '%';
                $where .= ' AND (LOWER(COALESCE(s.display_name, \'\')) LIKE LOWER(?)
                              OR LOWER(s.company_name) LIKE LOWER(?) OR LOWER(COALESCE(s.ic, \'\')) LIKE LOWER(?)';
                $params[] = $like; $params[] = $like; $params[] = $like;
                if (ctype_digit($q)) {
                    $where .= ' OR s.id = ?';
                    $params[] = (int) $q;
                }
                $where .= ')';
            }
        }
        $sql = "SELECT s.id, COALESCE(NULLIF(s.display_name, ''), s.company_name) AS name, s.company_name, s.ic
                  FROM supplier s WHERE {$where} ORDER BY name, s.id LIMIT " . ($limit + 1) . " OFFSET {$cursor}";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'name' => (string) $row['name'],
            'company_name' => (string) $row['company_name'], 'ic' => $row['ic'],
        ], $rows);
        return Json::ok($response, [
            'data' => $items,
            'next_cursor' => $hasMore ? (string) ($cursor + $limit) : null,
        ]);
    }
}
