<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final readonly class PayrollRunValidation
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $entityType,
        public ?int $entityId,
        public string $message,
        public ?string $remediationPath = null,
        public bool $requiresOverride = false,
    ) {
        if (!in_array($severity, ['blocker', 'warning', 'info'], true)) {
            throw new \InvalidArgumentException('Neplatná závažnost validace běhu.');
        }
        if (trim($code) === '' || trim($entityType) === '' || trim($message) === '') {
            throw new \InvalidArgumentException('Validace běhu není úplná.');
        }
        if ($requiresOverride && $severity !== 'warning') {
            throw new \InvalidArgumentException(
                'Ruční override lze vyžadovat pouze u varování.',
            );
        }
    }
}
