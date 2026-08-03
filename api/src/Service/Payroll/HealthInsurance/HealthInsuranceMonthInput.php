<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class HealthInsuranceMonthInput
{
    /** @var non-empty-list<HealthPersonMonthInput> */
    public array $people;

    /** @param array<mixed> $people */
    public function __construct(
        public string $calculationDate,
        array $people,
    ) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $calculationDate);
        if ($date === false || $date->format('Y-m-d') !== $calculationDate) {
            throw new InvalidArgumentException(
                'Health insurance calculation date must use YYYY-MM-DD.',
            );
        }
        if (!array_is_list($people) || $people === []) {
            throw new InvalidArgumentException('Health insurance month requires people.');
        }
        $ids = [];
        foreach ($people as $person) {
            if (!$person instanceof HealthPersonMonthInput) {
                throw new InvalidArgumentException(
                    'Health insurance people must use the dedicated input type.',
                );
            }
            $ids[] = $person->personId;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException(
                'Health insurance person IDs must be unique within a month.',
            );
        }

        $this->people = $people;
    }
}
