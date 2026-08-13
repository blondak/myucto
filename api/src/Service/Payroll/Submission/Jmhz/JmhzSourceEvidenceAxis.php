<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzSourceEvidenceAxis
{
    public function __construct(
        public string $key,
        public string $kind,
        public string $sourceColumn,
        public string $sourceSheet,
        public string $labelRaw,
        public int $dimensionCount,
        public int $nonemptyCount,
        public int $blankCount,
        public int $zeroCount,
        public int $oneCount,
        public string $rawVectorSha256,
        public int $dictionaryFormulaCount,
        public ?string $dictionaryFormulaVectorSha256,
        public ?string $dictionaryCachedVectorSha256,
        public int $masterMatchCount,
        public int $masterMismatchCount,
        public string $reconciliationStatus,
    ) {}
}
