<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

final readonly class CompanyBackupCompatibilityResult
{
    /** @var list<CompanyBackupCompatibilityIssue> */
    public array $issues;

    /** @var list<string> */
    public array $upcasterIds;

    /** @var list<string> */
    public array $warnings;

    /**
     * @param array<mixed> $issues
     * @param array<mixed> $upcasterIds
     * @param array<mixed> $warnings
     */
    public function __construct(array $issues, array $upcasterIds = [], array $warnings = [])
    {
        foreach ($issues as $issue) {
            if (!$issue instanceof CompanyBackupCompatibilityIssue) {
                throw new \InvalidArgumentException('Výsledek obsahuje neplatnou kompatibilitní chybu.');
            }
        }
        foreach ([$upcasterIds, $warnings] as $strings) {
            foreach ($strings as $value) {
                if (!is_string($value) || $value === '') {
                    throw new \InvalidArgumentException('Výsledek kompatibility obsahuje neplatný text.');
                }
            }
        }
        $this->issues = array_values($issues);
        $this->upcasterIds = array_values($upcasterIds);
        $this->warnings = array_values($warnings);
    }

    public function isCompatible(): bool
    {
        return $this->issues === [];
    }

    /**
     * @return array{
     *   compatible:bool,
     *   upcasters:list<string>,
     *   warnings:list<string>,
     *   issues:list<array{code:string,field:string,message:string,value:?string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'compatible' => $this->isCompatible(),
            'upcasters' => $this->upcasterIds,
            'warnings' => $this->warnings,
            'issues' => array_map(
                static fn (CompanyBackupCompatibilityIssue $issue): array => $issue->toArray(),
                $this->issues,
            ),
        ];
    }
}
