<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

final class IncompleteTenantDataRegistryCoverage extends \LogicException
{
    public function __construct(public readonly TenantDataCoverageReport $report)
    {
        parent::__construct(
            'Tenantový registr neprošel coverage bránou: '
                . implode(', ', array_map(
                    static fn (TenantDataCoverageIssue $issue): string => $issue->code . ':' . $issue->object,
                    $report->issues,
                )),
        );
    }
}
