<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Repository\Payroll\HealthInsuranceOverviewRepository;
use MyInvoice\Service\Codebook\HealthInsurers;

final class HealthPaymentOverviewService
{
    public function __construct(
        private readonly HealthInsuranceOverviewRepository $repository,
        private readonly HealthPaymentOverviewBuilder $builder,
    ) {}

    /** @return list<HealthPaymentOverview> */
    public function overviews(int $supplierId, int $revisionId): array
    {
        $source = $this->repository->findApprovedHealthResult(
            $supplierId,
            $revisionId,
        );
        if ($source === null) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_result_not_found',
                'Schválený zdravotní výsledek nebyl nalezen.',
            );
        }

        return $this->builder->build($supplierId, $source);
    }

    public function overview(
        int $supplierId,
        int $revisionId,
        string $insurerCode,
    ): HealthPaymentOverview {
        if (!HealthInsurers::isValid($insurerCode)) {
            throw new \InvalidArgumentException(
                HealthInsurers::invalidCodeMessage($insurerCode),
            );
        }
        foreach ($this->overviews($supplierId, $revisionId) as $overview) {
            if ($overview->insurerCode === $insurerCode) {
                return $overview;
            }
        }

        throw new \OutOfBoundsException(
            'Přehled zvolené zdravotní pojišťovny nebyl nalezen.',
        );
    }

    public function assertElectronicSubmissionSupported(): never
    {
        throw new HealthInsuranceOverviewException(
            'health_insurance_transport_unavailable',
            'Elektronické podání zdravotní pojišťovně není podporované bez ověřené specifikace formátu a transportu.',
        );
    }
}
