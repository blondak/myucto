<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportMap;
use MyInvoice\Service\Migration\StereoNx\StereoNxJournalLinks;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

#[Group('integration')]
final class StereoNxJournalLinksTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxImporter $importer;
    private StereoNxImportMap $map;
    private StereoNxJournalLinks $links;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(StereoNxImporter::class);
        $this->map = $container->get(StereoNxImportMap::class);
        $this->links = new StereoNxJournalLinks($this->db, $this->map);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $identity = SyntheticStereoNxTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name = ?, ic = ?, dic = ?, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)")->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
            ->execute([(int) $pdo->lastInsertId(), $this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testLinksEntriesByDateAndIsIdempotent(): void
    {
        [$journalPlan, $documentPlan, $documentId, $laterId, $earlierId] = $this->scenario();

        self::assertSame(['journal_documents_linked' => 1],
            $this->links->write($journalPlan, $documentPlan, $this->supplierId, $this->userId));
        self::assertSame(['source_type' => 'invoice', 'source_id' => $documentId], $this->source($earlierId));
        self::assertSame(['source_type' => 'manual', 'source_id' => null], $this->source($laterId));
        $expectedLinks = [$earlierId, $laterId];
        sort($expectedLinks);
        self::assertSame($expectedLinks, array_map('intval', $this->column(
            'SELECT entry_id FROM journal_entry_document_links WHERE supplier_id = ? AND doc_type = "invoice" AND doc_id = ? ORDER BY entry_id',
            [$this->supplierId, $documentId],
        )));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = "invoice" AND source_id = ?', [$this->supplierId, $documentId]));

        self::assertSame(['journal_documents_linked' => 0],
            $this->links->write($journalPlan, $documentPlan, $this->supplierId, $this->userId));
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM journal_entry_document_links WHERE supplier_id = ? AND doc_type = "invoice" AND doc_id = ?', [$this->supplierId, $documentId]));
    }

    public function testChangedMapAndCrossSupplierTargetFailClosed(): void
    {
        [$journalPlan, $documentPlan] = $this->scenario();
        $key = $journalPlan['accounting_plan']['entries'][0]['source_key'];
        $this->db->pdo()->prepare("UPDATE stereo_nx_import_map SET source_hash = ? WHERE supplier_id = ? AND kind = 'accounting_journal' AND source_key = ?")
            ->execute([str_repeat('a', 64), $this->supplierId, $key]);
        try {
            $this->links->write($journalPlan, $documentPlan, $this->supplierId, $this->userId);
            self::fail('Změněný otisk zdrojového zápisu musí převod zastavit.');
        } catch (StereoNxException $e) {
            self::assertSame('source_changed', $e->errorCode);
        }

        $entry = $journalPlan['accounting_plan']['entries'][0];
        $this->db->pdo()->prepare("UPDATE stereo_nx_import_map SET source_hash = ? WHERE supplier_id = ? AND kind = 'accounting_journal' AND source_key = ?")
            ->execute([$entry['source_hash'], $this->supplierId, $key]);
        $otherSupplier = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        $period = $this->period($otherSupplier, 2025);
        $foreignEntry = $this->entry($otherSupplier, $period, '2025-01-01');
        $this->db->pdo()->prepare("UPDATE stereo_nx_import_map SET target_id = ? WHERE supplier_id = ? AND kind = 'accounting_journal' AND source_key = ?")
            ->execute([$foreignEntry, $this->supplierId, $key]);

        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('změněn nebo odstraněn');
        $this->links->write($journalPlan, $documentPlan, $this->supplierId, $this->userId);
    }

    /** @return array{array<string,mixed>,array<string,mixed>,int,int,int} */
    private function scenario(): array
    {
        $source = StereoNxSourcePlan::fromTables(
            SyntheticStereoNxTables::tables(), SyntheticStereoNxTables::identity(), true, true,
        );
        $source['source_company_index'] = 1;
        $this->importer->writeAccountingPartners($source['clients'], $source['identity'], 1, $this->supplierId);
        $this->importer->writeAccountingDocuments($source, $this->supplierId, $this->userId);
        $document = array_values($source['issued'])[0];
        $mapped = $this->map->get($this->supplierId, $source['identity']['ico'], 1, 'issued', $document['source_key']);
        self::assertNotNull($mapped);
        $parts = json_decode($document['source_key'], true, flags: JSON_THROW_ON_ERROR);
        $period = $this->period($this->supplierId, 2025);
        $later = $this->journalEntry($parts, '2025-05-02', '2');
        $earlier = $this->journalEntry($parts, '2025-05-01', '1');
        $laterId = $this->entry($this->supplierId, $period, $later['date']);
        $earlierId = $this->entry($this->supplierId, $period, $earlier['date']);
        foreach ([[$later, $laterId], [$earlier, $earlierId]] as [$record, $id]) {
            $this->map->put($this->supplierId, $source['identity']['ico'], 1, 'accounting_journal',
                $record['source_key'], $record['source_hash'], $id);
        }
        return [
            ['identity' => $source['identity'], 'company_index' => 1,
                'accounting_plan' => ['entries' => [$later, $earlier]]],
            ['records' => ['issued' => [$document], 'purchases' => []]],
            $mapped['target_id'], $laterId, $earlierId,
        ];
    }

    /** @param list<string> $documentParts @return array<string,mixed> */
    private function journalEntry(array $documentParts, string $date, string $order): array
    {
        $record = [
            'source_key' => json_encode(['VF', $documentParts[1], $documentParts[2], 'synthetic', $order], JSON_THROW_ON_ERROR),
            'date' => $date, 'is_opening' => false,
        ];
        $record['source_hash'] = StereoNxImportMap::fingerprint($record);
        return $record;
    }

    private function period(int $supplierId, int $year): int
    {
        $this->db->pdo()->prepare('INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $year, "$year-01-01", "$year-12-31"]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function entry(int $supplierId, int $periodId, string $date): int
    {
        $this->db->pdo()->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, description) VALUES (?, ?, ?, 'manual', 'Syntetický zápis')")
            ->execute([$supplierId, $periodId, $date]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{source_type:string,source_id:?int} */
    private function source(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT source_type, source_id FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$entryId, $this->supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return ['source_type' => (string) $row['source_type'], 'source_id' => $row['source_id'] === null ? null : (int) $row['source_id']];
    }

    /** @return list<string> */
    private function column(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function countRows(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql); $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
