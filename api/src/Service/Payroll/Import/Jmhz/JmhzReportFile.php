<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

/**
 * Hlavička měsíčního hlášení JMHZ a jeho formuláře osob.
 *
 * `lenient` = soubor neprošel připnutým XSD, ale všechny chyby schématu leží
 * v částech, ze kterých import nečte ({@see JmhzReportReader}); důvod stojí
 * ve `warnings`.
 */
final readonly class JmhzReportFile
{
    /**
     * @param list<string> $warnings
     * @param list<JmhzReportForm> $forms
     */
    public function __construct(
        public string $submissionGuid,
        public string $submissionType,
        public int $year,
        public int $month,
        public string $filledAt,
        public ?int $packageOrdinal,
        public ?int $packageCount,
        public ?string $vendor,
        public bool $lenient,
        public array $warnings,
        public array $forms,
    ) {}

    public function period(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function periodStart(): string
    {
        return $this->period() . '-01';
    }

    public function periodEnd(): string
    {
        return (new \DateTimeImmutable($this->periodStart()))->modify('last day of this month')->format('Y-m-d');
    }

    public function typeLabel(): string
    {
        return match ($this->submissionType) {
            'O' => 'opravné',
            'S' => 'storno',
            default => 'řádné',
        };
    }
}
