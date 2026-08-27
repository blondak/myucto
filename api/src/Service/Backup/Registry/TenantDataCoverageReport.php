<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

final readonly class TenantDataCoverageReport
{
    /** @var list<TenantDataCoverageIssue> */
    public array $issues;

    /** @param array<mixed> $issues */
    public function __construct(array $issues)
    {
        foreach ($issues as $issue) {
            if (!$issue instanceof TenantDataCoverageIssue) {
                throw new \InvalidArgumentException('Coverage report obsahuje neplatnou chybu.');
            }
        }
        $this->issues = array_values($issues);
    }

    public function isSafe(): bool
    {
        return $this->issues === [];
    }

    /** @return array{safe:bool,issues:list<array{code:string,object:string,message:string}>} */
    public function toArray(): array
    {
        return [
            'safe' => $this->isSafe(),
            'issues' => array_map(
                static fn (TenantDataCoverageIssue $issue): array => $issue->toArray(),
                $this->issues,
            ),
        ];
    }
}
