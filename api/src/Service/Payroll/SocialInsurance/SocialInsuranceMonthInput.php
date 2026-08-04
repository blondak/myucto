<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use InvalidArgumentException;

final readonly class SocialInsuranceMonthInput
{
    /** @var list<SocialPersonMonthInput> */
    public array $people;

    /** @param array<mixed> $people */
    public function __construct(
        public string $calculationDate,
        array $people,
    ) {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $calculationDate);
        if ($date === false || $date->format('Y-m-d') !== $calculationDate) {
            throw new InvalidArgumentException(
                'Social insurance calculation date must use YYYY-MM-DD.',
            );
        }
        if (!array_is_list($people)) {
            throw new InvalidArgumentException('Social insurance people must be a list.');
        }
        foreach ($people as $person) {
            if (!$person instanceof SocialPersonMonthInput) {
                throw new InvalidArgumentException(
                    'Social insurance people must use the dedicated input type.',
                );
            }
        }
        $ids = array_map(
            static fn (SocialPersonMonthInput $person): string => $person->personId,
            $people,
        );
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException(
                'Social insurance person IDs must be unique in a monthly calculation.',
            );
        }

        $this->people = $people;
    }
}
