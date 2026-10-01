<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final readonly class PayrollRunValidation
{
    /**
     * @param list<int> $subjectIds osoby souhrnného varování („N osob…"), jinak prázdné;
     *     podle nich jde souhrn skrýt po osobách ({@see PayrollWarningSuppressionCatalog})
     */
    public function __construct(
        public string $severity,
        public string $code,
        public string $entityType,
        public ?int $entityId,
        public string $message,
        public ?string $remediationPath = null,
        public bool $requiresOverride = false,
        public array $subjectIds = [],
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
