<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Automation\AutomationFeedService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * `scope=group` u sestav po dimenzi: globální typ (sdílený skupinou firem) se sečte za
 * všechny firmy skupiny, ke kterým má uživatel účetní přístup (stejné pravidlo jako
 * přehled automatizace napříč firmami). Firmy, které nevidí, se do součtu nedostanou
 * a odpověď je vyjmenuje jen počtem.
 */
trait DimensionGroupScope
{
    /**
     * @param array<string,mixed> $type typ dimenze z {@see DimensionRepository::findType()}
     * @return array{0:list<int>, 1:int} firmy do součtu (aktuální první) a počet skrytých
     */
    protected function groupScopeSuppliers(
        Request $request,
        DimensionRepository $dimensions,
        AutomationFeedService $feed,
        int $supplierId,
        array $type,
    ): array {
        $supplierIds = [$supplierId];
        $hidden = 0;
        if (($request->getQueryParams()['scope'] ?? '') !== 'group' || ($type['level'] ?? null) !== 'global') {
            return [$supplierIds, 0];
        }
        $members = array_column($dimensions->groupMembers((int) $type['supplier_group_id']), 'id');
        $allowed = $feed->allowedSupplierIds((int) ($this->userId($request) ?? 0), RequestAuthorization::isSuperadmin($request));
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
        return [$supplierIds, $hidden];
    }

    private static function isReportDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
