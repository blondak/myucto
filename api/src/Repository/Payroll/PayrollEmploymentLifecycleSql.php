<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollEmploymentLifecycleSql
{
    public static function effectiveStatusAtPlaceholder(): string
    {
        return 'COALESCE(
                    (
                        SELECT lifecycle.to_status
                          FROM payroll_employment_events lifecycle
                         WHERE lifecycle.supplier_id = employment.supplier_id
                           AND lifecycle.employment_id = employment.id
                           AND lifecycle.event_type IN ("created", "status_changed")
                           AND lifecycle.to_status IS NOT NULL
                           AND lifecycle.effective_on <= ?
                         ORDER BY lifecycle.effective_on DESC, lifecycle.id DESC
                         LIMIT 1
                    ),
                    CASE WHEN NOT EXISTS (
                        SELECT 1
                          FROM payroll_employment_events lifecycle
                         WHERE lifecycle.supplier_id = employment.supplier_id
                           AND lifecycle.employment_id = employment.id
                           AND lifecycle.event_type IN ("created", "status_changed")
                    ) THEN employment.status ELSE NULL END
                )';
    }
}
