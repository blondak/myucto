<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzPvpojPreviewRepository;

final class JmhzPvpojPreviewService
{
    public function __construct(
        private readonly JmhzPvpojPreviewRepository $repository,
        private readonly JmhzPvpojPreviewBuilder $builder,
    ) {}

    public function preview(int $supplierId, int $revisionId): JmhzPvpojPreview
    {
        $source = $this->repository->findSource($supplierId, $revisionId);
        if ($source === null) {
            throw new JmhzPvpojPreviewException(
                'jmhz_pvpoj_source_not_found',
                'Schválený sociální výsledek PVPOJ nebyl nalezen.',
            );
        }

        return $this->builder->build($supplierId, $source);
    }

    public function assertOfficialSubmissionSupported(): never
    {
        throw new JmhzPvpojPreviewException(
            'jmhz_full_submission_unsupported',
            'PVPOJ preview není úplné JMHZ ani stav připraveného, odeslaného nebo přijatého podání.',
        );
    }
}
