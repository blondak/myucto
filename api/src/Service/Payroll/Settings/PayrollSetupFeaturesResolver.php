<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Settings;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Payroll\SupportMatrix;

final class PayrollSetupFeaturesResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmployerPolicyRepository $policies,
        private readonly SupportMatrix $supportMatrix,
    ) {}

    public function resolve(
        int $supplierId,
        string $effectiveOn,
    ): PayrollSetupFeatures {
        $policy = $this->policies->findEffective(
            $supplierId,
            $effectiveOn,
        );
        $sourceBlockers = [];
        $jmhzAvailable = $this->featureAvailability('jmhz_export');
        if ($jmhzAvailable === null) {
            $sourceBlockers['jmhz_feature_source'] =
                'Support matrix neobsahuje ověřitelnou dostupnost JMHZ.';
        } elseif ($jmhzAvailable) {
            $sourceBlockers['jmhz_feature_source'] =
                'JMHZ je globálně dostupné, ale chybí tenantový zdroj jeho aktivace.';
        }

        return new PayrollSetupFeatures(
            homeOffice: $policy !== null
                && $this->string($policy, 'home_office_policy') !== 'not_used',
            travelExpenses: $policy !== null
                && $this->string(
                    $policy,
                    'travel_expense_policy',
                ) !== 'not_used',
            fourEyes: $this->bool($policy, 'four_eyes_required'),
            automaticCalculation: $this->bool(
                $policy,
                'automatic_calculation_enabled',
            ),
            automaticPosting: $this->bool(
                $policy,
                'automatic_posting_enabled',
            ),
            automaticPayments: $this->bool(
                $policy,
                'automatic_payments_enabled',
            ),
            secureDelivery: $policy !== null
                && $this->string($policy, 'delivery_channel') !== 'disabled',
            jmhz: false,
            activeApproverCount: $this->activeApproverCount($supplierId),
            jmhzRegistryReady: false,
            jmhzCertificateReady: false,
            sourceBlockers: $sourceBlockers,
        );
    }

    private function featureAvailability(string $key): ?bool
    {
        foreach ($this->supportMatrix->all()['features'] as $feature) {
            if ($feature['key'] === $key) {
                return $feature['available'];
            }
        }

        return null;
    }

    private function activeApproverCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(DISTINCT candidate.user_id)
               FROM (
                 SELECT users.id AS user_id,
                        COALESCE(membership.role_id, users.role_id) AS role_id
                   FROM users
                   JOIN user_suppliers membership
                     ON membership.user_id = users.id
                    AND membership.supplier_id = ?
                  WHERE users.is_active = 1
                 UNION ALL
                 SELECT users.id AS user_id, users.role_id
                   FROM users
                   JOIN roles global_role
                     ON global_role.id = users.role_id
                    AND global_role.system_key = "superadmin"
                    AND global_role.is_active = 1
                  WHERE users.is_active = 1
               ) candidate
               JOIN roles effective_role
                 ON effective_role.id = candidate.role_id
                AND effective_role.is_active = 1
          LEFT JOIN role_permissions permission
                 ON permission.role_id = effective_role.id
                AND permission.permission_key = "payroll.approve"
                AND permission.access_level >= 2
              WHERE effective_role.system_key = "superadmin"
                 OR (
                   effective_role.role_type = "staff"
                   AND permission.role_id IS NOT NULL
                 )',
        );
        $stmt->execute([$supplierId]);
        $count = $stmt->fetchColumn();
        if (!is_int($count) && !is_string($count)) {
            throw new \UnexpectedValueException(
                'Počet aktivních schvalovatelů nelze ověřit.',
            );
        }

        return (int) $count;
    }

    /** @param array<string,mixed>|null $policy */
    private function string(?array $policy, string $field): string
    {
        $value = $policy[$field] ?? null;
        return is_string($value) ? $value : '';
    }

    /** @param array<string,mixed>|null $policy */
    private function bool(?array $policy, string $field): bool
    {
        return ($policy[$field] ?? null) === true;
    }
}
