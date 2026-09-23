<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\JournalEntryLinker;

/** Vazby používají zdrojovou agendu a řadu/číslo, nikoliv podobnost popisu. */
final class StereoNxJournalLinks
{
    public function __construct(private readonly Connection $db, private readonly StereoNxImportMap $map) {}

    public function write(array $journalPlan, array $documentPlan, int $supplierId, int $userId): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new StereoNxException('transaction_required', 'Vazby účetních dokladů vyžadují transakci převodu.');
        }
        $ico = $journalPlan['identity']['ico'];
        $company = $journalPlan['company_index'];
        $documents = [];
        foreach (['issued' => ['VF', 'invoice'], 'purchases' => ['PF', 'purchase_invoice']] as $part => [$agenda, $sourceType]) {
            $mapKind = $part === 'issued' ? 'issued' : 'purchase';
            foreach ($documentPlan['records'][$part] ?? [] as $document) {
                $parts = json_decode($document['source_key'], true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($parts) || count($parts) < 3 || !isset($parts[1], $parts[2])) {
                    throw new StereoNxException('import_identity_invalid', 'Doklad nemá platnou zdrojovou identitu.');
                }
                $bareKey = json_encode([$agenda, $parts[1], $parts[2]], JSON_THROW_ON_ERROR);
                if (isset($documents[$bareKey])) {
                    throw new StereoNxException('journal_document_ambiguous', 'Vazba deníku na doklad není jednoznačná.');
                }
                $target = $this->map->get($supplierId, $ico, $company, $mapKind, $document['source_key']);
                if ($target === null) throw new StereoNxException('document_missing', 'Převáděný účetní doklad chybí.');
                $this->assertDocumentTarget($supplierId, $sourceType, $target['target_id']);
                $documents[$bareKey] = ['type' => $sourceType, 'kind' => $mapKind, 'id' => $target['target_id'],
                    'key' => $document['source_key'], 'entries' => []];
            }
        }
        foreach ($journalPlan['accounting_plan']['entries'] ?? [] as $entry) {
            if ($entry['is_opening']) continue;
            $parts = json_decode($entry['source_key'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($parts) || count($parts) < 5) {
                throw new StereoNxException('import_identity_invalid', 'Účetní zápis nemá platnou zdrojovou identitu.');
            }
            $bareKey = json_encode(array_slice($parts, 0, 3), JSON_THROW_ON_ERROR);
            if (!isset($documents[$bareKey])) continue;
            $target = $this->map->get($supplierId, $ico, $company, 'accounting_journal', $entry['source_key']);
            if ($target === null) throw new StereoNxException('journal_missing', 'Převáděný účetní zápis chybí.');
            if (!isset($entry['source_hash']) || !hash_equals((string) $target['source_hash'], (string) $entry['source_hash'])) {
                throw new StereoNxException('source_changed', 'Zdrojový účetní zápis se změnil.');
            }
            $document = $documents[$bareKey];
            $this->assertJournalTarget($supplierId, $target['target_id'], (string) $entry['date'],
                (string) $document['type'], (int) $document['id']);
            $documents[$bareKey]['entries'][$entry['source_key']] = [
                'id' => $target['target_id'], 'date' => (string) $entry['date'], 'key' => (string) $entry['source_key'],
            ];
        }
        $linker = new JournalEntryLinker($this->db, 'Stereo NX', true);
        $linked = 0;
        foreach ($documents as $document) {
            if ($document['entries'] === []) continue;
            $entries = array_values($document['entries']);
            usort($entries, static fn (array $a, array $b): int => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);
            foreach ($entries as $entry) {
                $key = $entry['key'];
                $kind = 'accounting_' . $document['kind'] . '_link';
                $hash = StereoNxImportMap::fingerprint(['document_key' => $document['key'], 'entry_key' => $key]);
                $previous = $this->map->get($supplierId, $ico, $company, $kind, $key);
                if ($previous !== null && ($previous['target_id'] !== $document['id'] || !hash_equals($previous['source_hash'], $hash))) {
                    throw new StereoNxException('source_changed', 'Zdrojová vazba účetního dokladu se změnila.');
                }
                if ($previous === null) $this->map->put($supplierId, $ico, $company, $kind, $key, $hash, $document['id']);
            }
            if ($linker->attach($supplierId, $userId, $document['type'], 'manual', $document['type'],
                $document['id'], array_column($entries, 'id'))) $linked++;
        }
        return ['journal_documents_linked' => $linked];
    }

    /** U prvního zápisu dokladu standardní linker nahradí zdroj manual původem dokladu. */
    public static function acceptsLinkedSource(Connection $db, int $supplierId, int $entryId, string $entryKey, string $sourceType, int $sourceId): bool
    {
        $kind = match ($sourceType) {
            'invoice' => 'accounting_issued_link',
            'purchase_invoice' => 'accounting_purchase_link',
            'bank' => 'accounting_bank_link',
            'cash' => 'accounting_cash_link',
            default => null,
        };
        if ($kind === null || $sourceId <= 0) return false;
        $query = $db->pdo()->prepare('SELECT 1 FROM stereo_nx_import_map j
            JOIN stereo_nx_import_map l ON l.supplier_id = j.supplier_id AND l.source_ico = j.source_ico
                AND l.source_company_index = j.source_company_index AND l.source_key = j.source_key
            JOIN journal_entry_document_links dl ON dl.supplier_id = j.supplier_id AND dl.entry_id = j.target_id
                AND dl.doc_type = ? AND dl.doc_id = l.target_id
            WHERE j.supplier_id = ? AND j.kind = ? AND j.source_key = ? AND j.target_id = ?
                AND l.kind = ? AND l.target_id = ? LIMIT 1');
        $query->execute([$sourceType, $supplierId, 'accounting_journal', $entryKey, $entryId, $kind, $sourceId]);
        return $query->fetchColumn() !== false;
    }

    private function assertDocumentTarget(int $supplierId, string $sourceType, int $targetId): void
    {
        $table = $sourceType === 'invoice' ? 'invoices' : 'purchase_invoices';
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$targetId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new StereoNxException('mapped_target_changed', 'Převedený účetní doklad byl změněn nebo odstraněn.');
        }
    }

    private function assertJournalTarget(
        int $supplierId,
        int $targetId,
        string $date,
        string $documentType,
        int $documentId,
    ): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT entry_date, source_type, source_id, reversed_by FROM journal_entries WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$targetId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $sourceIsExpected = $row !== false && (
            ((string) $row['source_type'] === 'manual' && $row['source_id'] === null)
            || ((string) $row['source_type'] === $documentType && (int) $row['source_id'] === $documentId)
        );
        if ($row === false || (string) $row['entry_date'] !== $date || $row['reversed_by'] !== null || !$sourceIsExpected) {
            throw new StereoNxException('mapped_target_changed', 'Převedený účetní zápis byl změněn nebo odstraněn.');
        }
    }
}
