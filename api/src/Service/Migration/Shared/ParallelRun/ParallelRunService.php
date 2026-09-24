<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ParallelRunCheckRepository;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads;

/**
 * Měsíční cyklus kontroly souběhu: spuštění kontroly nad výstupy starého programu,
 * uložení protokolu, zařazení rozdílů a uzavření cyklu.
 *
 * Zařazení rozdílu (rekonciliační kritéria převodu):
 *   source         rozdíl je už ve starém programu, převod ho věrně převzal,
 *   migration      převod přenesl data jinak — vada, opraví se a kontrola se pustí znovu,
 *   interpretation MyÚčto vykazuje položku jinak a předpis připouští obojí.
 * Cyklus měsíce jde uzavřít, když žádné kritérium neskončilo chybou a každý rozdíl je
 * zařazený jako `source` nebo `interpretation`. Rozdíl převodu uzavření blokuje.
 */
final class ParallelRunService
{
    public const CATEGORIES = ['source', 'migration', 'interpretation'];

    /** Strop jednoho nahraného výstupu. */
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly ParallelRunReconciliation $reconciliation,
        private readonly ParallelRunCheckRepository $checks,
        private readonly Connection $db,
        private readonly ParallelRunSources $sources = new ParallelRunSources(),
    ) {}

    /** @return list<array{key:string,inputs:list<string>,reads_backup:bool}> */
    public function sources(): array
    {
        return $this->sources->describe();
    }

    /**
     * Zálohy agend Money S3 nahrané v průvodci převodu, které jde porovnat bez zápisu.
     *
     * @return list<array{token:string,file_name:string,uploaded_at:string,agenda_name:string,agenda_ico:string,years:list<int>}>
     */
    public function moneyBackups(int $supplierId): array
    {
        $out = [];
        foreach (glob(MoneyS3Uploads::base($supplierId) . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $token = basename($dir);
            if (preg_match(MoneyS3Uploads::TOKEN_PATTERN, $token) !== 1 || !MoneyS3Uploads::hasMeta($supplierId, $token) || !is_dir(MoneyS3Uploads::agendaDir($supplierId, $token))) {
                continue;
            }
            try {
                $meta = MoneyS3Uploads::meta($supplierId, $token);
            } catch (MoneyS3Exception) {
                continue;
            }
            $agenda = (array) ($meta['agenda'] ?? []);
            $out[] = [
                'token' => $token,
                'file_name' => (string) ($meta['file_name'] ?? ''),
                'uploaded_at' => (string) ($meta['uploaded_at'] ?? ''),
                'agenda_name' => (string) ($agenda['name'] ?? ''),
                'agenda_ico' => (string) ($agenda['ico'] ?? ''),
                'years' => array_values(array_map('intval', array_filter(array_column((array) ($agenda['years'] ?? []), 'fiscal_year')))),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['uploaded_at'], $a['uploaded_at']));
        return $out;
    }

    /**
     * Spustí kontrolu měsíce a uloží ji do historie.
     *
     * @param array<string,string> $files druh => obsah
     * @param array<string,string> $names druh => jméno souboru
     * @param array{statement_unit?:float,backup_token?:string} $options
     * @return array<string,mixed> uložená kontrola s výsledkem
     */
    public function check(int $supplierId, ?int $userId, string $month, string $sourceKey, array $files, array $names, array $options = []): array
    {
        [$year, $monthNo] = self::parseMonth($month);
        $source = $this->sources->get($sourceKey);
        foreach ($files as $kind => $content) {
            if (strlen($content) > self::MAX_FILE_BYTES) {
                throw new ParallelRunException('input_too_large', 'Soubor je větší než 20 MB.', ['input' => $kind]);
            }
        }
        $sourceOptions = ['statement_unit' => (float) ($options['statement_unit'] ?? 1.0)];
        $token = trim((string) ($options['backup_token'] ?? ''));
        if ($token !== '') {
            if (!$source->readsBackup()) {
                throw new ParallelRunException('backup_not_supported', 'Zálohu agendy umí kontrola číst jen u Money S3.');
            }
            if (preg_match(MoneyS3Uploads::TOKEN_PATTERN, $token) !== 1 || !MoneyS3Uploads::hasMeta($supplierId, $token)) {
                throw new ParallelRunException('backup_missing', 'Záloha agendy nebyla nalezena. Nahrajte ji v průvodci převodu z Money S3.', [], 404);
            }
            $meta = MoneyS3Uploads::meta($supplierId, $token);
            $sourceOptions['backup_dir'] = MoneyS3Uploads::agendaDir($supplierId, $token);
            $sourceOptions['backup_sha256'] = (string) ($meta['sha256'] ?? '');
            MoneyS3Uploads::store()->touch($supplierId, $token);
        }
        if ($files === [] && $token === '') {
            throw new ParallelRunException('inputs_missing', 'Nahrajte aspoň jeden výstup starého programu.');
        }

        $snapshot = $source->snapshot($year, $monthNo, $files, $names, $sourceOptions);
        $result = $this->reconciliation->run($supplierId, $year, $monthNo, $snapshot);
        if ($token !== '') {
            $warning = $this->agendaIcoWarning($supplierId, $token);
            if ($warning !== null) {
                $result['warnings'][] = $warning;
            }
        }
        $previous = $this->checks->latestForMonth($supplierId, $result['month']);
        $id = $this->checks->create($supplierId, $result['month'], $source->key(), $result['status'], $snapshot->inputs, $result, $userId);
        // Opakovaná kontrola téhož měsíce převezme zařazení rozdílů, které trvají: rozdíl
        // „už ve zdroji" se po opravě převodu nemá zařazovat znovu.
        if ($previous !== null) {
            $ids = array_flip(self::differenceIds(['result' => $result]));
            $carried = [];
            foreach ((array) $previous['classifications'] as $diffId => $c) {
                if (isset($ids[$diffId]) && ($c['category'] ?? null) !== 'migration') {
                    $carried[$diffId] = $c + ['carried_from' => $previous['id']];
                }
            }
            if ($carried !== []) {
                $this->checks->saveClassifications($supplierId, $id, $carried);
            }
        }
        return $this->get($supplierId, $id);
    }

    /** @return list<array<string,mixed>> */
    public function history(int $supplierId): array
    {
        return array_map(static fn (array $c): array => $c + ['classification' => self::classificationSummaryOf($c)], $this->checks->list($supplierId));
    }

    /** @return array<string,mixed> */
    public function get(int $supplierId, int $id): array
    {
        $check = $this->checks->find($supplierId, $id) ?? throw new ParallelRunException('check_not_found', 'Kontrola nebyla nalezena.', [], 404);
        return $check + ['classification' => self::classificationSummary($check)];
    }

    /**
     * Zařadí rozdíl (nebo zařazení zruší, když je `$category` null).
     *
     * @return array<string,mixed>
     */
    public function classify(int $supplierId, int $id, string $differenceId, ?string $category, ?string $note, ?int $userId): array
    {
        $check = $this->get($supplierId, $id);
        if ($check['cycle_status'] === 'closed') {
            throw new ParallelRunException('cycle_closed', 'Cyklus je uzavřený, rozdíly už nejde zařazovat. Nejdřív ho znovu otevřete.', [], 409);
        }
        if ($category !== null && !in_array($category, self::CATEGORIES, true)) {
            throw new ParallelRunException('category_invalid', 'Neznámé zařazení rozdílu.');
        }
        if (!in_array($differenceId, self::differenceIds($check), true)) {
            throw new ParallelRunException('difference_not_found', 'Rozdíl v kontrole není.', [], 404);
        }
        $classifications = (array) $check['classifications'];
        if ($category === null) {
            unset($classifications[$differenceId]);
        } else {
            $classifications[$differenceId] = [
                'category' => $category,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null,
                'by' => $userId,
                'at' => date('c'),
            ];
        }
        $this->checks->saveClassifications($supplierId, $id, $classifications);
        return $this->get($supplierId, $id);
    }

    /** @return array<string,mixed> */
    public function close(int $supplierId, int $id, ?int $userId, ?string $note): array
    {
        $check = $this->get($supplierId, $id);
        $summary = $check['classification'];
        if ($summary['errors'] > 0) {
            throw new ParallelRunException('cycle_has_errors', 'Některé kritérium skončilo chybou. Opravte vstup a pusťte kontrolu znovu.', [], 409);
        }
        if ($summary['unclassified'] > 0) {
            throw new ParallelRunException('cycle_unclassified', "Zbývá zařadit {$summary['unclassified']} rozdílů.", ['unclassified' => $summary['unclassified']], 409);
        }
        if ($summary['migration'] > 0) {
            throw new ParallelRunException('cycle_migration_differences', 'Rozdíly převodu je potřeba opravit a kontrolu pustit znovu.', ['migration' => $summary['migration']], 409);
        }
        $this->checks->setCycle($supplierId, $id, 'closed', $userId, $note !== null && trim($note) !== '' ? trim($note) : null);
        return $this->get($supplierId, $id);
    }

    /** @return array<string,mixed> */
    public function reopen(int $supplierId, int $id): array
    {
        $this->get($supplierId, $id);
        $this->checks->setCycle($supplierId, $id, 'open', null, null);
        return $this->get($supplierId, $id);
    }

    public function delete(int $supplierId, int $id): void
    {
        $check = $this->get($supplierId, $id);
        if ($check['cycle_status'] === 'closed') {
            throw new ParallelRunException('cycle_closed', 'Uzavřený cyklus nejde smazat. Nejdřív ho znovu otevřete.', [], 409);
        }
        $this->checks->delete($supplierId, $id);
    }

    /**
     * Protokol kontroly jako CSV (středník, UTF-8 s BOM) — kritérium, rozdíl, hodnoty, zařazení.
     */
    public function exportCsv(int $supplierId, int $id): string
    {
        $check = $this->get($supplierId, $id);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Kontrola souběhu', $check['month'], $check['source'], $check['status'], $check['cycle_status']], ';', '"', '');
        fputcsv($out, ['Kritérium', 'Stav', 'Předmět', 'Popis', 'Pole', 'MyÚčto', 'Zdroj', 'Rozdíl', 'Důvod', 'Zařazení', 'Poznámka'], ';', '"', '');
        $classifications = (array) $check['classifications'];
        foreach ((array) ($check['result']['criteria'] ?? []) as $c) {
            if ($c['differences'] === []) {
                fputcsv($out, [$c['key'], $c['status'], '', (string) ($c['message'] ?? ''), '', '', '', '', '', '', ''], ';', '"', '');
                continue;
            }
            foreach ($c['differences'] as $d) {
                $cl = $classifications[$d['id']] ?? null;
                foreach ($d['values'] as $v) {
                    $mine = $v['mine'];
                    $theirs = $v['theirs'];
                    fputcsv($out, [
                        $c['key'], $c['status'], $d['subject'], (string) ($d['label'] ?? ''), $v['field'],
                        self::csvNumber($mine), self::csvNumber($theirs),
                        $mine !== null && $theirs !== null ? self::csvNumber(round((float) $mine - (float) $theirs, 2)) : '',
                        (string) ($d['note'] ?? ''), (string) ($cl['category'] ?? ''), (string) ($cl['note'] ?? ''),
                    ], ';', '"', '');
                }
            }
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /** @return array{0:int,1:int} */
    public static function parseMonth(string $month): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', trim($month), $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw new ParallelRunException('month_invalid', 'Měsíc zadejte ve tvaru RRRR-MM.');
        }
        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * @param array<string,mixed> $check
     * @return array{differences:int,unclassified:int,source:int,migration:int,interpretation:int,errors:int,can_close:bool}
     */
    public static function classificationSummary(array $check): array
    {
        $summary = ['differences' => 0, 'unclassified' => 0, 'source' => 0, 'migration' => 0, 'interpretation' => 0, 'errors' => 0];
        $classifications = (array) ($check['classifications'] ?? []);
        foreach ((array) ($check['result']['criteria'] ?? []) as $c) {
            if (($c['status'] ?? '') === 'error') {
                $summary['errors']++;
            }
            // Rozdíly nad stropem uloženého seznamu nejdou zařadit jednotlivě — počítají se jako nezařazené.
            $hidden = max(0, (int) ($c['difference_count'] ?? 0) - count((array) ($c['differences'] ?? [])));
            $summary['differences'] += (int) ($c['difference_count'] ?? 0);
            $summary['unclassified'] += $hidden;
            foreach ((array) ($c['differences'] ?? []) as $d) {
                $category = $classifications[$d['id']]['category'] ?? null;
                if ($category === null) {
                    $summary['unclassified']++;
                } else {
                    $summary[$category]++;
                }
            }
        }
        $summary['can_close'] = $summary['errors'] === 0 && $summary['unclassified'] === 0 && $summary['migration'] === 0 && ($check['result'] ?? null) !== null;
        return $summary;
    }

    /**
     * Souhrn zařazení u hlavičky historie (bez výsledku) — jen počty zařazených rozdílů.
     *
     * @param array<string,mixed> $check
     * @return array<string,int>
     */
    private static function classificationSummaryOf(array $check): array
    {
        $out = ['source' => 0, 'migration' => 0, 'interpretation' => 0];
        foreach ((array) ($check['classifications'] ?? []) as $c) {
            $category = (string) ($c['category'] ?? '');
            if (isset($out[$category])) {
                $out[$category]++;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $check
     * @return list<string>
     */
    private static function differenceIds(array $check): array
    {
        $ids = [];
        foreach ((array) ($check['result']['criteria'] ?? []) as $c) {
            foreach ((array) ($c['differences'] ?? []) as $d) {
                $ids[] = (string) $d['id'];
            }
        }
        return $ids;
    }

    private function agendaIcoWarning(int $supplierId, string $token): ?string
    {
        $meta = MoneyS3Uploads::meta($supplierId, $token);
        $agendaIco = preg_replace('/\D+/', '', (string) ($meta['agenda']['ico'] ?? '')) ?? '';
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ico = preg_replace('/\D+/', '', (string) $stmt->fetchColumn()) ?? '';
        if ($agendaIco === '' || $ico === '' || ltrim($agendaIco, '0') === ltrim($ico, '0')) {
            return null;
        }
        return "Záloha je agenda IČO {$agendaIco}, firma v MyÚčtu má IČO {$ico}.";
    }

    private static function csvNumber(mixed $v): string
    {
        return $v === null ? '' : str_replace('.', ',', (string) round((float) $v, 2));
    }
}
