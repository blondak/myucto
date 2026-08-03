<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use InvalidArgumentException;

final readonly class RulesetTechnicalReview
{
    public function __construct(
        public string $checkedBy,
        public string $checkedOn,
        public string $evidence,
    ) {
        if ($checkedBy === '' || $evidence === '') {
            throw new InvalidArgumentException('Ruleset technical check identity and evidence are required.');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $checkedOn);
        if ($parsed === false || $parsed->format('Y-m-d') !== $checkedOn) {
            throw new InvalidArgumentException('Ruleset technical check date must use YYYY-MM-DD.');
        }
    }

    /** @return array{checked_by:string,checked_on:string,evidence:string} */
    public function toCanonicalArray(): array
    {
        return [
            'checked_by' => $this->checkedBy,
            'checked_on' => $this->checkedOn,
            'evidence' => $this->evidence,
        ];
    }
}
