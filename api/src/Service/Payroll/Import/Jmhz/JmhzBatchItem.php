<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

/** Formulář osoby spolu se souborem, ze kterého pochází. */
final readonly class JmhzBatchItem
{
    public function __construct(
        public string $key,
        public JmhzReportFile $file,
        public JmhzReportForm $form,
        public string $fileName,
        public string $fileSha256,
        public int $fileIndex,
    ) {}

    public function period(): string
    {
        return $this->file->period();
    }

    /** Pořadí zpracování: starší měsíc dřív, v rámci měsíce podle souborů. */
    public function sortKey(): array
    {
        return [$this->file->period(), $this->file->filledAt, $this->fileIndex, $this->form->position];
    }
}
