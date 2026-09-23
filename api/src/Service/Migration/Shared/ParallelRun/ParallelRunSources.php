<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

/**
 * Programy, proti kterým jde kontrola souběhu pustit. Money S3 umí navíc zálohu agendy;
 * POHODA, PREMIER a ostatní programy dodávají sestavy a podání z EPO.
 */
final class ParallelRunSources
{
    /** @var array<string,ParallelRunSource> */
    private array $sources;

    /** @param list<ParallelRunSource>|null $sources */
    public function __construct(?array $sources = null)
    {
        $sources ??= [
            new MoneyS3Source(),
            new ExportFileSource('pohoda'),
            new ExportFileSource('premier'),
            new ExportFileSource('other'),
        ];
        $this->sources = [];
        foreach ($sources as $source) {
            $this->sources[$source->key()] = $source;
        }
    }

    public function get(string $key): ParallelRunSource
    {
        return $this->sources[$key] ?? throw new ParallelRunException('source_unknown', "Neznámý zdrojový program: {$key}.", ['source' => $key]);
    }

    /** @return list<array{key:string,inputs:list<string>,reads_backup:bool}> */
    public function describe(): array
    {
        return array_values(array_map(static fn (ParallelRunSource $s): array => [
            'key' => $s->key(),
            'inputs' => $s->inputs(),
            'reads_backup' => $s->readsBackup(),
        ], $this->sources));
    }
}
