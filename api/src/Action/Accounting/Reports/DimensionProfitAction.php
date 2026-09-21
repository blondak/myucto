<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Automation\AutomationFeedService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výsledovka po dimenzi.
 *
 *   GET /api/accounting/reports/dimension-profit?type_id=&from=&to=[&scope=group]
 *
 * `scope=group` u globálního typu sečte všechny firmy skupiny, ke kterým má uživatel
 * účetní přístup (stejné pravidlo jako přehled automatizace napříč firmami); firmy,
 * které nevidí, se do součtu nedostanou a odpověď je vyjmenuje jen počtem.
 */
final class DimensionProfitAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly DimensionProfitService $service,
        private readonly DimensionRepository $dimensions,
        private readonly AutomationFeedService $feed,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();
        $typeId = (int) ($q['type_id'] ?? 0);
        $from = (string) ($q['from'] ?? '');
        $to = (string) ($q['to'] ?? '');
        if ($typeId <= 0 || !self::isDate($from) || !self::isDate($to) || $from > $to) {
            return Json::error($response, 'validation_failed', 'type_id, from a to (YYYY-MM-DD, from ≤ to) jsou povinné.', 422);
        }
        $type = $this->dimensions->findType($supplierId, $typeId);
        if ($type === null) {
            return Json::error($response, 'not_found', 'Typ dimenze nenalezen.', 404);
        }

        $supplierIds = [$supplierId];
        $hidden = 0;
        if (($q['scope'] ?? '') === 'group' && $type['level'] === 'global') {
            $members = array_column($this->dimensions->groupMembers((int) $type['supplier_group_id']), 'id');
            $allowed = $this->feed->allowedSupplierIds((int) ($this->userId($request) ?? 0), RequestAuthorization::isSuperadmin($request));
            foreach ($members as $member) {
                if ($member === $supplierId) {
                    continue;
                }
                if (in_array($member, $allowed, true)) {
                    $supplierIds[] = $member;
                } else {
                    $hidden++;
                }
            }
        }

        try {
            $data = $this->service->build($supplierId, $typeId, $from, $to, $supplierIds);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $data['hidden_companies'] = $hidden;
        return Json::ok($response, $data);
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
