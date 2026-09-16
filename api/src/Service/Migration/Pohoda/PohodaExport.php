<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Support\CompanyIdNormalizer;

/**
 * Jedna agenda (účetní jednotka a rok) vyexportovaná z Pohody do XML.
 *
 * Pohoda vede každý účetní rok jako samostatnou databázi (`StwPh_<IČO>_<rok>`) a export
 * ji ukládá do složky `<IČO>_<rok>` s pevně pojmenovanými soubory po agendách
 * (`01_ucetni_denik.xml` …). Soubor chybějící agendy nebo agendy, kterou účetní jednotka
 * nepoužívá (odpověď se stavem `error`), se čte jako prázdný.
 */
final class PohodaExport
{
    public const FILES = [
        'journal' => '01_ucetni_denik.xml',
        'chart' => '02_uctova_osnova.xml',
        'posting_rules' => '03_predkontace_pu.xml',
        'balance' => '05_saldo.xml',
        'vat_classes' => '06_cleneni_dph.xml',
        'issued' => '12_faktury_issuedInvoice.xml',
        'issued_credit' => '13_faktury_issuedCreditNotice.xml',
        'issued_debit' => '14_faktury_issuedDebitNote.xml',
        'issued_advance' => '15_faktury_issuedAdvanceInvoice.xml',
        'issued_proforma' => '16_faktury_issuedProformaInvoice.xml',
        'issued_corrective' => '17_faktury_issuedCorrectiveTax.xml',
        'receivable' => '18_faktury_receivable.xml',
        'penalty' => '19_faktury_penalty.xml',
        'received' => '20_faktury_receivedInvoice.xml',
        'received_credit' => '21_faktury_receivedCreditNotice.xml',
        'received_debit' => '22_faktury_receivedDebitNote.xml',
        'received_advance' => '23_faktury_receivedAdvanceInvoice.xml',
        'received_proforma' => '24_faktury_receivedProformaInvoice.xml',
        'received_corrective' => '25_faktury_receivedCorrectiveTax.xml',
        'commitment' => '26_faktury_commitment.xml',
        'internal' => '27_interni_doklady.xml',
        'cash' => '28_pokladna.xml',
        'bank' => '29_banka.xml',
        'addressbook' => '30_adresar.xml',
        'bank_accounts' => '31_bankovni_ucty.xml',
        'cash_registers' => '32_pokladny.xml',
        // Tabulky z datového souboru POHODY (XML export je nemá), vytváří je exportní nástroj.
        'assets' => '90_majetek.xml',
        'payroll' => '91_mzdy.xml',
    ];

    /** Agendy, bez kterých převod nemá smysl. */
    private const REQUIRED = ['journal', 'chart', 'vat_classes'];

    /** Přehled účetních jednotek v kořeni exportu (název firmy k IČO a roku). */
    public const UNITS_FILE = '00_ucetni_jednotky.xml';

    public const MAX_UNCOMPRESSED_BYTES = 2 * 1024 * 1024 * 1024;
    private const MAX_ARCHIVE_ENTRIES = 20000;
    /** Agend (IČO a rok) v jednom ZIP: pár firem za pár let, ne tisíce náhledů. */
    private const MAX_AGENDAS = 30;
    private const MIN_YEAR = 1990;

    /**
     * @param array{ico:string,program:string,state:string,item_state:string,note:string,timestamp:string} $info
     */
    private function __construct(
        public readonly string $dir,
        public readonly string $ico,
        public readonly int $year,
        public readonly array $info,
    ) {}

    public static function open(string $dir): self
    {
        $dir = rtrim($dir, '/\\');
        if (!is_dir($dir)) {
            throw new PohodaException('export_not_found', 'Složka exportu ' . basename($dir) . ' neexistuje.');
        }
        foreach (self::REQUIRED as $key) {
            if (!is_file($dir . DIRECTORY_SEPARATOR . self::FILES[$key])) {
                throw new PohodaException('export_incomplete', 'Ve složce ' . basename($dir) . ' chybí ' . self::FILES[$key] . ' - není to úplný export agendy Pohody.');
            }
        }
        $info = PohodaXml::packInfo($dir . DIRECTORY_SEPARATOR . self::FILES['journal']);
        $year = preg_match('/_(\d{4})$/', basename($dir), $m) === 1 ? (int) $m[1] : self::yearFromJournal($dir);
        return new self($dir, CompanyIdNormalizer::ic($info['ico']) ?? $info['ico'], $year, $info);
    }

    /** Složka `<kořen>\<IČO>_<rok>`; bez roku nejnovější rok, který v kořeni je. */
    public static function locate(string $root, string $ico, ?int $year): self
    {
        $root = rtrim($root, '/\\');
        $found = [];
        foreach (glob($root . DIRECTORY_SEPARATOR . $ico . '_*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/_(\d{4})$/', basename($dir), $m) === 1) {
                $found[(int) $m[1]] = $dir;
            }
        }
        if ($found === []) {
            throw new \RuntimeException("V {$root} není export agendy IČO {$ico} (složka {$ico}_<rok>).");
        }
        ksort($found);
        if ($year !== null && !isset($found[$year])) {
            throw new \RuntimeException("V {$root} není export IČO {$ico} za rok {$year} (jsou: " . implode(', ', array_keys($found)) . ').');
        }
        return self::open($found[$year ?? array_key_last($found)]);
    }

    public function path(string $key): ?string
    {
        $file = self::FILES[$key] ?? null;
        if ($file === null) {
            throw new \InvalidArgumentException("Neznámá agenda exportu: {$key}");
        }
        $path = $this->dir . DIRECTORY_SEPARATOR . $file;
        return is_file($path) ? $path : null;
    }

    /**
     * @return \Generator<int,array<string,mixed>>
     */
    public function records(string $key, string $tag): \Generator
    {
        $path = $this->path($key);
        if ($path === null) {
            return;
        }
        yield from PohodaXml::records($path, $tag);
    }

    /**
     * Stav každého souboru exportu: chybí / odpověď Pohody ok / chyba (agenda se v jednotce
     * nepoužívá) / jiné IČO v hlavičce.
     *
     * @return list<array{key:string,file:string,exists:bool,state:string,note:string,ico:string}>
     */
    public function files(): array
    {
        $out = [];
        foreach (self::FILES as $key => $file) {
            $path = $this->path($key);
            $info = $path !== null ? PohodaXml::packInfo($path) : null;
            $out[] = [
                'key' => $key,
                'file' => $file,
                'exists' => $path !== null,
                'state' => $info === null ? 'missing' : (($info['item_state'] ?: $info['state']) ?: 'unknown'),
                'note' => $info['note'] ?? '',
                'ico' => $info !== null ? (CompanyIdNormalizer::ic($info['ico']) ?? $info['ico']) : '',
            ];
        }
        return $out;
    }

    /** Otisk exportu (obsah všech souborů převodu) - k protokolu běhu. */
    public function fingerprint(): string
    {
        $ctx = hash_init('sha256');
        foreach (self::FILES as $key => $file) {
            $path = $this->path($key);
            if ($path !== null) {
                hash_update($ctx, $file . ':' . hash_file('sha256', $path) . "\n");
            }
        }
        return hash_final($ctx);
    }

    /**
     * Rozbalí ZIP exportu (výstup nástroje `tools/pohoda-export`) do `$targetDir`. Bere jen
     * XML agend (`<IČO>_<rok>/NN_agenda.xml`) a přehled účetních jednotek; cílovou cestu
     * skládá sám z ověřených částí jména, takže jméno z archivu nikdy nevede mimo cíl.
     * ZIP může mít agendy v kořeni i v jedné nadřazené složce.
     */
    public static function extractArchive(string $zipPath, string $targetDir): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new PohodaException('export_not_zip', 'Soubor není platný ZIP. Nahrajte ZIP, který vytvořil exportní nástroj.');
        }
        try {
            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new PohodaException('export_too_many_files', 'ZIP obsahuje příliš mnoho souborů.');
            }
            $plan = [];
            $seen = [];
            $agendas = [];
            $total = 0;
            $maxYear = (int) date('Y') + 1;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $parts = explode('/', str_replace('\\', '/', (string) $stat['name']));
                $file = (string) array_pop($parts);
                $agenda = null;
                foreach ($parts as $part) {
                    if (preg_match('/^\d{6,8}_(\d{4})$/', $part, $m) === 1 && (int) $m[1] >= self::MIN_YEAR && (int) $m[1] <= $maxYear) {
                        $agenda = $part;
                    }
                }
                if ($agenda !== null && preg_match('/^\d{2}_[A-Za-z_]{1,60}\.xml$/', $file) === 1) {
                    $target = $agenda . '/' . $file;
                    $agendas[$agenda] = true;
                } elseif ($agenda === null && $file === self::UNITS_FILE && count($parts) <= 1) {
                    $target = $file;
                } else {
                    continue;
                }
                // Na souborovém systému bez rozlišení velikosti písmen by se druhý soubor tiše přepsal.
                $key = strtolower($target);
                if (isset($seen[$key])) {
                    throw new PohodaException('export_duplicate_file', 'ZIP obsahuje soubor ' . $target . ' víckrát. Nahrajte ZIP, který vytvořil exportní nástroj.');
                }
                $seen[$key] = true;
                $total += (int) $stat['size'];
                $plan[$i] = $target;
            }
            if (count($agendas) > self::MAX_AGENDAS) {
                throw new PohodaException('export_too_many_agendas', 'ZIP obsahuje víc než ' . self::MAX_AGENDAS . ' agend. Exportujte jen firmu a roky, které převádíte.');
            }
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new PohodaException('export_too_large', 'Export je po rozbalení příliš velký.');
            }
            $written = 0;
            foreach ($plan as $index => $target) {
                $path = rtrim($targetDir, '/\\') . '/' . $target;
                if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
                    throw new PohodaException('storage_not_writable', 'Úložiště pro exporty není zapisovatelné.', [], 500);
                }
                $in = $zip->getStream((string) $zip->getNameIndex($index));
                $out = @fopen($path, 'wb');
                if ($in === false || $out === false) {
                    throw new PohodaException('export_unreadable', 'Soubor ' . $target . ' z exportu nejde rozbalit.');
                }
                $copied = stream_copy_to_stream($in, $out, self::MAX_UNCOMPRESSED_BYTES - $written + 1);
                fclose($in);
                fclose($out);
                $written += (int) $copied;
                if ($written > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new PohodaException('export_too_large', 'Export je po rozbalení příliš velký.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Přehled agend v rozbaleném exportu pro náhled průvodce: IČO, rok, název jednotky
     * (z přehledu účetních jednotek), verze Pohody, počty záznamů a stav souborů.
     *
     * @return list<array<string,mixed>>
     */
    public static function overview(string $root): array
    {
        $root = rtrim($root, '/\\');
        $names = [];
        if (is_file($root . '/' . self::UNITS_FILE)) {
            foreach (PohodaXml::records($root . '/' . self::UNITS_FILE, 'itemAccountingUnit') as $unit) {
                $ico = CompanyIdNormalizer::ic((string) self::firstValue($unit, 'ico')) ?? '';
                $year = (int) self::firstValue($unit, 'year');
                $names[$ico . '_' . $year] = (string) self::firstValue($unit, 'company');
            }
        }
        $out = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/^(\d{6,8})_(\d{4})$/', basename($dir), $m) !== 1) {
                continue;
            }
            $payrollFile = $dir . '/' . self::FILES['payroll'];
            $payroll = null;
            if (is_file($payrollFile)) {
                try {
                    $payroll = self::payrollSummary($payrollFile, (int) $m[2]);
                } catch (\Throwable) {
                    $payroll = null;
                }
            }
            if (is_file($dir . '/' . self::FILES['journal'])) {
                try {
                    $export = self::open($dir);
                } catch (\Throwable) {
                    continue;
                }
                $out[] = [
                    'dir' => basename($dir),
                    'ico' => $export->ico,
                    'year' => $export->year,
                    'company' => $names[$export->ico . '_' . $export->year] ?? '',
                    'program' => $export->info['program'],
                    'exported_at' => $export->info['timestamp'] !== '' ? $export->info['timestamp'] : null,
                    'counts' => $export->counts(),
                    'files' => array_map(static fn (array $f): array => ['file' => $f['file'], 'state' => $f['state'], 'note' => $f['note']], $export->files()),
                    'has_accounting' => true,
                    'has_payroll' => $payroll !== null,
                    'payroll' => $payroll,
                ];
                continue;
            }
            // Jen mzdy (například z programu PAMICA): IČO z exportu mezd, jinak ze jména složky.
            if ($payroll === null) {
                continue;
            }
            $ico = CompanyIdNormalizer::ic($payroll['ico'] !== '' ? $payroll['ico'] : $m[1]) ?? $m[1];
            $year = (int) $m[2];
            $out[] = [
                'dir' => basename($dir),
                'ico' => $ico,
                'year' => $year,
                'company' => $names[$ico . '_' . $year] ?? '',
                'program' => 'POHODA Mzdy',
                'exported_at' => null,
                'counts' => ['journal' => 0, 'opening' => 0, 'first_date' => null, 'last_date' => null, 'issued' => 0, 'purchase' => 0, 'internal' => 0, 'cash' => 0, 'bank' => 0, 'partners' => 0],
                'files' => [['file' => self::FILES['payroll'], 'state' => 'ok', 'note' => '']],
                'has_accounting' => false,
                'has_payroll' => true,
                'payroll' => $payroll,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['ico'], $a['year']] <=> [$b['ico'], $b['year']]);
        return $out;
    }

    /**
     * Přehled mezd z datového souboru (`91_mzdy.xml`) za rok agendy: zaměstnanci v exportu,
     * měsíce a počet zpracovaných mezd, IČO z hlavičky exportu (může chybět).
     *
     * @return array{ico:string,employees:int,months:int,payslips:int,first:?string,last:?string}
     */
    public static function payrollSummary(string $file, int $year): array
    {
        $info = PohodaXml::packInfo($file);
        $periods = [];
        foreach (PohodaXml::records($file, 'MZ') as $row) {
            if ((int) PohodaXml::text($row, 'Rok') === $year) {
                $period = sprintf('%04d-%02d', $year, (int) PohodaXml::text($row, 'RelMes'));
                $periods[$period] = ($periods[$period] ?? 0) + 1;
            }
        }
        ksort($periods);
        return [
            'ico' => (string) preg_replace('/\D/', '', $info['ico']),
            'employees' => PohodaXml::count($file, 'ZAM'),
            'months' => count($periods),
            'payslips' => array_sum($periods),
            'first' => $periods === [] ? null : (string) array_key_first($periods),
            'last' => $periods === [] ? null : (string) array_key_last($periods),
        ];
    }

    /**
     * Počty záznamů agendy pro náhled: řádky deníku (z toho počáteční stavy), rozsah dat,
     * vydané a přijaté doklady, interní doklady, pokladna, banka a adresář.
     *
     * @return array{journal:int,opening:int,first_date:?string,last_date:?string,issued:int,purchase:int,internal:int,cash:int,bank:int,partners:int}
     */
    public function counts(): array
    {
        $journal = 0;
        $opening = 0;
        $first = null;
        $last = null;
        foreach ($this->records('journal', 'accountingItem') as $item) {
            $journal++;
            if (PohodaJournal::isOpening($item)) {
                $opening++;
                continue;
            }
            $date = PohodaXml::date($item, 'date');
            if ($date !== null) {
                $first = $first === null || $date < $first ? $date : $first;
                $last = $last === null || $date > $last ? $date : $last;
            }
        }
        $sum = function (array $keys, string $tag): int {
            $n = 0;
            foreach ($keys as $key) {
                $path = $this->path($key);
                $n += $path !== null ? PohodaXml::count($path, $tag) : 0;
            }
            return $n;
        };
        return [
            'journal' => $journal,
            'opening' => $opening,
            'first_date' => $first,
            'last_date' => $last,
            'issued' => $sum(['issued', 'issued_credit', 'issued_debit', 'issued_advance', 'issued_proforma', 'issued_corrective', 'receivable'], 'invoice'),
            'purchase' => $sum(['received', 'received_credit', 'received_debit', 'received_advance', 'received_proforma', 'received_corrective', 'commitment'], 'invoice'),
            'internal' => $sum(['internal'], 'intDoc'),
            'cash' => $sum(['cash'], 'voucher'),
            'bank' => $sum(['bank'], 'bank'),
            'partners' => $sum(['addressbook'], 'addressbook'),
        ];
    }

    /** První hodnota klíče v libovolné hloubce záznamu (přehled účetních jednotek). */
    private static function firstValue(mixed $node, string $key): ?string
    {
        if (!is_array($node)) {
            return null;
        }
        foreach ($node as $k => $value) {
            if ($k === $key && (is_string($value) || (is_array($value) && isset($value['#'])))) {
                return trim(is_string($value) ? $value : (string) $value['#']);
            }
        }
        foreach ($node as $value) {
            $found = self::firstValue($value, $key);
            if ($found !== null && $found !== '') {
                return $found;
            }
        }
        return null;
    }

    /** Rok agendy podle většiny dat v deníku (složka bez `_<rok>` v názvu). */
    private static function yearFromJournal(string $dir): int
    {
        $years = [];
        foreach (PohodaXml::records($dir . DIRECTORY_SEPARATOR . self::FILES['journal'], 'accountingItem') as $item) {
            $date = PohodaXml::date($item, 'date');
            if ($date !== null) {
                $y = (int) substr($date, 0, 4);
                $years[$y] = ($years[$y] ?? 0) + 1;
            }
        }
        if ($years === []) {
            throw new PohodaException('export_no_year', 'Z deníku ve složce ' . basename($dir) . ' nejde určit účetní rok.');
        }
        arsort($years);
        return (int) array_key_first($years);
    }
}
