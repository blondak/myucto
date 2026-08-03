<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Calculation\Money;

final class PayrollInputPreviewService
{
    public function __construct(
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollComponentDefinitionFactory $factory,
    ) {}

    /**
     * @param array{
     *   employee_id:int,
     *   component_id:int,
     *   period_start:string,
     *   amount_minor:int
     * } $input
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, array $input): array
    {
        $component = $this->components->find(
            $supplierId,
            $input['component_id'],
        );
        if ($component === null || !$component['is_active']) {
            throw new \InvalidArgumentException('Mzdová složka není aktivní.');
        }
        if (PayrollTimeValue::string(
            $component['valid_from'] ?? null,
            'valid_from',
        ) > $input['period_start']
            || (
                ($component['valid_to'] ?? null) !== null
                && PayrollTimeValue::string(
                    $component['valid_to'],
                    'valid_to',
                ) < $input['period_start']
            )
        ) {
            throw new \InvalidArgumentException(
                'Mzdová složka není v zadaném měsíci účinná.'
            );
        }

        $definition = $this->factory->fromArray($component);
        try {
            $impact = $definition->impact(new Money($input['amount_minor']));
            $support = 'supported';
            $blocker = null;
        } catch (\DomainException $e) {
            $impact = null;
            $support = 'manual_review';
            $blocker = $e->getMessage();
        }

        $limit = $definition->annualLimitMinor;
        $used = $definition->kind->isBenefit()
            ? $this->inputs->annualBenefitTotal(
                $supplierId,
                $input['employee_id'],
                $input['component_id'],
                (int) substr($input['period_start'], 0, 4),
            )
            : 0;
        $after = $used + max(0, $input['amount_minor']);

        return [
            'support_status' => $support,
            'blocker' => $blocker,
            'component_snapshot' => $definition->snapshot(),
            'impact' => $impact?->jsonSerialize(),
            'annual_limit_minor' => $limit,
            'annual_used_minor' => $used,
            'annual_after_minor' => $after,
            'annual_limit_exceeded' => $limit !== null && $after > $limit,
        ];
    }
}
