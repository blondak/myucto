<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class GarnishmentBatchCalculator
{
    public function __construct(private GarnishmentCalculator $calculator) {}

    /**
     * Back pay attributable to different calendar months must be supplied as
     * separate inputs; this method deliberately never merges their income.
     *
     * @param list<GarnishmentInput> $inputs
     * @return array<string, GarnishmentResult>
     */
    public function calculate(array $inputs): array
    {
        $results = [];
        foreach ($inputs as $input) {
            if (isset($results[$input->period])) {
                throw new InvalidArgumentException("Duplicate garnishment period {$input->period}.");
            }
            $results[$input->period] = $this->calculator->calculate($input);
        }
        ksort($results, SORT_STRING);

        return $results;
    }
}
