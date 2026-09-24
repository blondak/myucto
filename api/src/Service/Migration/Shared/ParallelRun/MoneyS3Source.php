<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;

/**
 * Money S3: sestavy a podání jako u ostatních programů, navíc záloha agendy čtená bez
 * zápisu ({@see MoneyS3BackupReader}). Ze zálohy se doplní předvaha (K1) a počty dokladů
 * (K5), pokud je nedodal soubor — sestava vyexportovaná z Money má přednost, protože ji
 * účetní v Money vidí a podepisuje.
 */
final class MoneyS3Source implements ParallelRunSource
{
    public const KEY = 'money_s3';

    public function __construct(
        private readonly ExportInputParser $parser = new ExportInputParser(),
        private readonly MoneyS3BackupReader $backupReader = new MoneyS3BackupReader(),
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function inputs(): array
    {
        return ParallelRunInput::fileKinds();
    }

    public function readsBackup(): bool
    {
        return true;
    }

    public function snapshot(int $year, int $month, array $files, array $names, array $options): SourceSnapshot
    {
        $snapshot = $this->parser->snapshot(self::KEY, $files, $names, $options);
        $dir = (string) ($options['backup_dir'] ?? '');
        if ($dir === '') {
            return $snapshot;
        }
        $backup = Ms3Backup::open($dir);
        $read = $this->backupReader->read($backup, $year, $month);
        if (!$read['year_found']) {
            throw new ParallelRunException('backup_year_missing', "Záloha agendy neobsahuje účetní rok {$year}.", ['year' => $year]);
        }
        $info = $backup->agendaInfoIni();
        $inputs = $snapshot->inputs;
        $inputs[] = [
            'kind' => ParallelRunInput::BACKUP,
            'name' => trim($info['name'] . ' ' . $info['backup_at']) ?: 'Money S3',
            'sha256' => (string) ($options['backup_sha256'] ?? ''),
            'size' => 0,
            'note' => $info['version'] !== '' ? 'Money ' . $info['version'] : '',
        ];

        return new SourceSnapshot(
            source: self::KEY,
            trialBalance: $snapshot->trialBalance ?? $read['trial_balance'],
            documentCounts: $snapshot->documentCounts ?? $read['document_counts'],
            saldo: $snapshot->saldo,
            bankBalances: $snapshot->bankBalances,
            vatReturn: $snapshot->vatReturn,
            controlStatement: $snapshot->controlStatement,
            assets: $snapshot->assets,
            costCenters: $snapshot->costCenters,
            balanceSheet: $snapshot->balanceSheet,
            incomeStatement: $snapshot->incomeStatement,
            inputs: $inputs,
            warnings: $snapshot->warnings,
        );
    }
}
