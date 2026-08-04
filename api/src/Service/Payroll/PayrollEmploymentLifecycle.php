<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollEmploymentLifecycle
{
    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'planned' => ['preregistered', 'no_show'],
        'preregistered' => ['active', 'no_show'],
        'active' => ['suspended', 'ended'],
        'suspended' => ['active', 'ended'],
        'ended' => ['archived'],
        'no_show' => ['archived'],
        'archived' => [],
    ];

    /** @return list<string> */
    public function allowedTargets(string $status): array
    {
        return self::TRANSITIONS[$status]
            ?? throw new \InvalidArgumentException('Neznámý stav pracovního vztahu.');
    }

    public function assertTransition(string $from, string $to): void
    {
        if (!in_array($to, $this->allowedTargets($from), true)) {
            throw new \DomainException("Přechod pracovního vztahu {$from} → {$to} není povolen.");
        }
    }
}
