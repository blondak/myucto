<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

/**
 * Program, jehož výstupy jsou sestavy v tabulkovém tvaru a podání z EPO. Platí pro
 * POHODU, PREMIER i jakýkoli jiný program; Money S3 k tomu umí navíc zálohu agendy
 * ({@see MoneyS3Source}).
 */
final class ExportFileSource implements ParallelRunSource
{
    public function __construct(
        private readonly string $key,
        private readonly ExportInputParser $parser = new ExportInputParser(),
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function inputs(): array
    {
        return ParallelRunInput::fileKinds();
    }

    public function readsBackup(): bool
    {
        return false;
    }

    public function snapshot(int $year, int $month, array $files, array $names, array $options): SourceSnapshot
    {
        return $this->parser->snapshot($this->key, $files, $names, $options);
    }
}
