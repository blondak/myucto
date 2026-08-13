<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzControlDefinition
{
    /**
     * @param list<string> $attributeIds
     * @param list<string> $symbolicAttributeRefs
     */
    public function __construct(
        public JmhzControlId $id,
        public string $name,
        public JmhzControlScope $scope,
        public JmhzControlSystem $portalSystem,
        public JmhzControlPassability $portalPassability,
        public JmhzControlSystem $remoteSystem,
        public JmhzControlPassability $remotePassability,
        public string $detail,
        public string $errorMessage,
        public array $attributeIds,
        public array $symbolicAttributeRefs,
    ) {}
}
