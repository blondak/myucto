<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class EldpAnnualStatement
{
    public const SCHEMA_REFERENCE = 'payroll-eldp-statement.v1';

    /** @param array<string,mixed> $payload */
    public function __construct(public array $payload) {}

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->payload);
    }

    /** @return list<array<string,mixed>> */
    public function sections(): array
    {
        $sections = $this->payload['eldp_sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections)) {
            throw new \UnexpectedValueException(
                'Sekce evidenčního listu nejsou seznam.',
            );
        }
        /** @var list<array<string,mixed>> $sections */
        return $sections;
    }

    /** @return array<string,mixed> */
    public function scope(): array
    {
        $scope = $this->payload['scope'] ?? null;
        if (!is_array($scope) || array_is_list($scope)) {
            throw new \UnexpectedValueException(
                'Rozsah evidenčního listu není objekt.',
            );
        }

        return $scope;
    }
}
