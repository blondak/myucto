<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzScenario1Blocker
{
    /** @param list<string> $attributeIds */
    public function __construct(
        public string $code,
        public string $entityType,
        public ?int $entityId = null,
        public array $attributeIds = [],
    ) {}

    /** @return array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'attribute_ids' => $this->attributeIds,
        ];
    }
}
