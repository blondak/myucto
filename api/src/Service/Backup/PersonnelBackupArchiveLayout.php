<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Payroll\Personnel\PayrollPersonnelFileStorage;
use PDO;

/**
 * Rozvržení zálohy personálních spisů (`storage/payroll-personnel/`).
 *
 * Personální spis se zálohuje odděleně od mzdového úložiště i od sekce
 * Dokumenty: jsou to soukromá data zaměstnanců a provozovatel může chtít tuhle
 * zálohu držet jinde nebo jinak dlouho.
 *
 * Soubory jdou do ZIPu tak, jak leží — šifrované datovým klíčem osoby a pod
 * otiskem obsahu — ze stejného důvodu jako u {@see PayrollBackupArchiveLayout}:
 * čitelná kopie pracovních smluv by byla hromada osobních údajů v jednom
 * souboru a obnova potřebuje přesně původní cesty. Manifest proto nese jen
 * identifikátory (firma, zaměstnanec, druh dokumentu), žádná jména.
 */
final class PersonnelBackupArchiveLayout
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array{source:string,entry:string}> */
    public function all(): array
    {
        $base = RuntimePaths::storage(PayrollPersonnelFileStorage::ROOT);
        if (!is_dir($base)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile() || $item->isLink()) {
                continue;
            }
            // Rozepsané a dočasné soubory (rozpracovaný upload) do zálohy nepatří.
            if (str_starts_with($item->getFilename(), '.')) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($base) + 1));
            $files[] = [
                'source' => $item->getPathname(),
                'entry' => 'storage/' . PayrollPersonnelFileStorage::ROOT . '/' . $relative,
            ];
        }
        usort($files, static fn (array $a, array $b): int => strcmp($a['entry'], $b['entry']));

        return $files;
    }

    public function manifestCsv(): string
    {
        $known = [];
        $stmt = $this->pdo->query(
            'SELECT id, supplier_id, employee_id, category, file_sha256, created_at
               FROM payroll_personnel_documents',
        );
        foreach ($stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['supplier_id'] . '/' . $row['employee_id'] . '/' . $row['file_sha256'];
            $known[$key] = $row;
        }

        $lines = ['cesta;firma;zamestnanec;dokument;druh;velikost_b;vytvoreno'];
        foreach ($this->all() as $file) {
            $row = null;
            if (preg_match('#/sup-(\d+)/subj-(\d+)/[0-9a-f]{2}/([0-9a-f]{64})$#', $file['entry'], $m) === 1) {
                $row = $known[$m[1] . '/' . $m[2] . '/' . $m[3]] ?? null;
            }
            $lines[] = implode(';', [
                $file['entry'],
                $row !== null ? (string) $row['supplier_id'] : '',
                $row !== null ? (string) $row['employee_id'] : '',
                $row !== null ? (string) $row['id'] : '',
                $row !== null ? (string) $row['category'] : 'neznamy',
                (string) (@filesize($file['source']) ?: 0),
                $row !== null ? (string) $row['created_at'] : '',
            ]);
        }

        return implode("\n", $lines) . "\n";
    }
}
