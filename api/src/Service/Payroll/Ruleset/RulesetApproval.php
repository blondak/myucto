<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use InvalidArgumentException;

final readonly class RulesetApproval
{
    public function __construct(
        public string $reviewedBy,
        public string $reviewedOn,
        public string $approvedBy,
        public string $approvedOn,
        public string $evidence,
    ) {
        if ($reviewedBy === '' || $approvedBy === '' || $evidence === '') {
            throw new InvalidArgumentException('Ruleset review, approval and evidence are required.');
        }
        if ($reviewedBy === $approvedBy) {
            throw new InvalidArgumentException('Ruleset reviewer and approver must be different identities.');
        }
        self::assertDate($reviewedOn);
        self::assertDate($approvedOn);
        if ($approvedOn < $reviewedOn) {
            throw new InvalidArgumentException('Ruleset approval cannot precede its review.');
        }
    }

    /** @return array{approved_by:string,approved_on:string,evidence:string,reviewed_by:string,reviewed_on:string} */
    public function toCanonicalArray(): array
    {
        return [
            'approved_by' => $this->approvedBy,
            'approved_on' => $this->approvedOn,
            'evidence' => $this->evidence,
            'reviewed_by' => $this->reviewedBy,
            'reviewed_on' => $this->reviewedOn,
        ];
    }

    private static function assertDate(string $value): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Ruleset approval dates must use YYYY-MM-DD.');
        }
    }
}
