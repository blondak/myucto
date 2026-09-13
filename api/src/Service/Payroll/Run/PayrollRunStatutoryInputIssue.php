<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final readonly class PayrollRunStatutoryInputIssue
{
    public function __construct(
        public string $domain,
        public string $code,
        public ?string $personReference = null,
        public ?string $relationshipReference = null,
    ) {
        if (!in_array(
            $domain,
            ['snapshot', 'social_insurance', 'health_insurance', 'income_tax'],
            true,
        )) {
            throw new \InvalidArgumentException('Doména zákonného vstupu není podporována.');
        }
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Kód problému zákonného vstupu není kanonický.');
        }
        foreach ([$personReference, $relationshipReference] as $reference) {
            if ($reference !== null
                && preg_match('/^[a-z]+:[1-9]\d*$/D', $reference) !== 1
            ) {
                throw new \InvalidArgumentException(
                    'Reference problému zákonného vstupu není kanonická.',
                );
            }
        }
    }

    /**
     * Problém, který nejde přičíst jediné osobě: vadný tvar snímku, duplicitní
     * osoba nebo vztah (nevíme, komu výsledek patří), prázdný běh. Takový
     * blokuje celý zákonný výpočet. Všechno ostatní je problém konkrétní osoby
     * a vyřadí jen ji.
     */
    public function isGlobal(): bool
    {
        return $this->domain === 'snapshot' || $this->personReference === null;
    }

    /** Stejný tvar, jaký nese kořen zablokovaného výsledku i validace běhu. */
    public function toIssueString(): string
    {
        return implode(':', array_filter([
            $this->domain,
            $this->code,
            $this->personReference,
            $this->relationshipReference,
        ], static fn (?string $value): bool => $value !== null));
    }

    /** @return array{domain:string,code:string,person_reference:?string,relationship_reference:?string} */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'code' => $this->code,
            'person_reference' => $this->personReference,
            'relationship_reference' => $this->relationshipReference,
        ];
    }

    public function sortKey(): string
    {
        return implode('|', [
            $this->domain,
            $this->code,
            $this->personReference ?? '',
            $this->relationshipReference ?? '',
        ]);
    }
}
