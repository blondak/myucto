<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use JsonSerializable;

final readonly class PayrollStatutoryBlockedPerson implements JsonSerializable
{
    /** @var non-empty-list<string> */
    public array $issues;

    /** @param array<mixed> $issues */
    public function __construct(
        public string $personReference,
        public string $status,
        array $issues,
        public ?string $rulesetId = null,
        public ?string $rulesetHash = null,
        public ?string $policyId = null,
        public ?string $policyHash = null,
    ) {
        if (preg_match('/^employee:[1-9][0-9]*$/D', $personReference) !== 1) {
            throw new \InvalidArgumentException(
                'Blokovaná osoba musí mít kanonický identifikátor employee:{id}.',
            );
        }
        if (!in_array($status, ['manual_review', 'error'], true)) {
            throw new \InvalidArgumentException(
                'Blokovaná osoba musí vyžadovat ruční kontrolu nebo nést chybu.',
            );
        }
        if ($issues === [] || !array_is_list($issues)) {
            throw new \InvalidArgumentException(
                'Blokovaná osoba musí obsahovat seznam alespoň jednoho důvodu.',
            );
        }
        foreach ($issues as $issue) {
            if (!is_string($issue) || trim($issue) === '') {
                throw new \InvalidArgumentException(
                    'Důvody blokace musí být neprázdné texty.',
                );
            }
        }
        if (count(array_unique($issues)) !== count($issues)) {
            throw new \InvalidArgumentException(
                'Důvody blokace nesmí obsahovat duplicity.',
            );
        }
        $identityValues = [
            $rulesetId,
            $rulesetHash,
            $policyId,
            $policyHash,
        ];
        $identityCount = count(array_filter(
            $identityValues,
            static fn (?string $value): bool => $value !== null,
        ));
        if ($identityCount !== 0 && $identityCount !== count($identityValues)) {
            throw new \InvalidArgumentException(
                'Identita pravidel blokované osoby musí být úplná.',
            );
        }
        if ($identityCount !== 0) {
            if ($rulesetId === '' || $policyId === '') {
                throw new \InvalidArgumentException(
                    'Identifikátory pravidel blokované osoby nesmí být prázdné.',
                );
            }
            foreach ([$rulesetHash, $policyHash] as $hash) {
                if (preg_match('/^[0-9a-f]{64}$/D', (string) $hash) !== 1) {
                    throw new \InvalidArgumentException(
                        'Otisky pravidel blokované osoby musí být SHA-256.',
                    );
                }
            }
        }
        sort($issues, SORT_STRING);
        $this->issues = $issues;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'issues' => $this->issues,
            'person_reference' => $this->personReference,
            'policy_hash' => $this->policyHash,
            'policy_id' => $this->policyId,
            'ruleset_hash' => $this->rulesetHash,
            'ruleset_id' => $this->rulesetId,
            'status' => $this->status,
        ];
    }
}
