<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\AutomationProvenanceService;
use PDO;

/**
 * Export účetního deníku (audit 2026-07, nález „Export a tisk účetního deníku" —
 * §13 ZoÚ, deník je zákonná kniha). Sestavuje data pro PDF/XLSX export přesně nad
 * filtry, které dnes podporuje GET /accounting/journal (období, rozsah dat, zdroj,
 * stav) — žádné vlastní filtrování navíc.
 *
 * Export NENÍ stránkovaný (na rozdíl od listu deníku) — kniha musí obsahovat
 * všechny odpovídající zápisy. MAX_ROWS je tvrdý strop proti obřímu/neúmyslnému
 * exportu celé historie firmy v jednom requestu; při překročení Action vrátí 422
 * s pokynem zúžit rozsah dat (stejný vzor jako BULK_POST_LIMIT v JournalAction).
 */
final class JournalExportService
{
    public const MAX_ROWS = 5000;

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly Connection $db,
        private readonly AutomationProvenanceService $automationProvenance,
    ) {}

    /**
     * @param array{document_no?:string, period_id?:int, date_from?:string, date_to?:string, source_type?:string, source_id?:int, posted?:bool, automation?:string} $filters
     * @return array<string,mixed>
     * @throws ReportException 422 too_many_rows
     */
    public function build(int $supplierId, array $filters): array
    {
        $entries = $this->journal->forExport($supplierId, $filters, self::MAX_ROWS);
        if (count($entries) > self::MAX_ROWS) {
            throw new ReportException(
                'too_many_rows',
                'Filtru odpovídá příliš mnoho zápisů (nad ' . self::MAX_ROWS . ') pro jeden export — zúžte rozsah dat (období/datum).',
                422,
            );
        }

        $ids = array_map(static fn (array $e): int => (int) $e['id'], $entries);
        $linesByEntry = $this->journal->linesForEntries($ids, $supplierId);
        $provenance = $this->automationProvenance->forJournalEntries($supplierId, $ids);

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($entries as &$entry) {
            $lines = $linesByEntry[$entry['id']] ?? [];
            $entry['lines'] = $lines;
            $entry['automation'] = $provenance[(int) $entry['id']] ?? null;
            $entry['automation_origin'] = match ($entry['automation']['mode'] ?? null) {
                'auto' => 'automaticky',
                'approved' => 'potvrzeno',
                default => 'ručně',
            };
            foreach ($lines as $line) {
                if ($line['side'] === 'debit') {
                    $totalDebit += $line['amount'];
                } else {
                    $totalCredit += $line['amount'];
                }
            }
        }
        unset($entry);

        return [
            'entity'  => $this->loadEntity($supplierId),
            'filters' => [
                'document_no' => $filters['document_no'] ?? null,
                'date_from'   => $filters['date_from'] ?? null,
                'date_to'     => $filters['date_to'] ?? null,
                'source_type' => $filters['source_type'] ?? null,
                'posted'      => array_key_exists('posted', $filters) ? $filters['posted'] : null,
                'automation'  => $filters['automation'] ?? null,
            ],
            'entries' => $entries,
            'totals'  => [
                'debit'  => round($totalDebit, 2),
                'credit' => round($totalCredit, 2),
                'count'  => count($entries),
            ],
        ];
    }

    /**
     * @return array{name:string, ico:?string, address:string, prepared_at:string}
     */
    private function loadEntity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, street, city, zip, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $addressParts = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim((string) ($row['zip'] ?? '') . ' ' . (string) ($row['city'] ?? '')),
        ], static fn (string $p): bool => $p !== '');

        return [
            'name'        => (string) ($row['company_name'] ?? ''),
            'ico'         => $row['ic'] !== null && $row['ic'] !== '' ? (string) $row['ic'] : null,
            'address'     => implode(', ', $addressParts),
            'prepared_at' => (new \DateTimeImmutable())->format('d.m.Y H:i'),
        ];
    }
}
