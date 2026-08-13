<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzScenario1DocumentService
{
    public function __construct(
        private JmhzPreparationSnapshotService $preparations,
        private JmhzPvpojPreviewService $pvpoj,
        private JmhzScenario1DocumentResolver $resolver,
    ) {}

    public function resolve(
        int $supplierId,
        string $environment,
        int $preparationId,
    ): JmhzScenario1Resolution {
        $preparation = $this->preparations->loadVerified(
            $supplierId,
            $environment,
            $preparationId,
        );
        if ($preparation->builderVersion !== JmhzPreparationSnapshotBuilder::BUILDER_VERSION) {
            return $this->resolver->resolve($preparation, null);
        }
        try {
            $pvpoj = $this->pvpoj->preview(
                $supplierId,
                $preparation->sourceRevisionId,
            );
            return $this->resolver->resolve($preparation, $pvpoj);
        } catch (JmhzPvpojPreviewException $exception) {
            return $this->resolver->resolve(
                $preparation,
                null,
                $exception->validationCode === 'jmhz_pvpoj_source_not_found'
                    ? 'jmhz_scenario1_pvpoj_unavailable'
                    : 'jmhz_scenario1_pvpoj_source_mismatch',
            );
        }
    }
}
