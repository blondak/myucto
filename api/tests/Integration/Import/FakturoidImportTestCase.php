<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Import\FakturoidClient;
use MyInvoice\Service\Import\FakturoidImportService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Celý běh importu z Fakturoidu nad syntetickými odpověďmi API. HTTP vrstva klienta
 * se nahradí handlerem, který podle cesty vrací připravená data — import pak projde
 * stejnou cestou jako u uživatele (úloha, dry-run, logy, stav úlohy).
 */
abstract class FakturoidImportTestCase extends StockTestCase
{
    protected int $sid = 0;

    /** @var array<string, list<array<string,mixed>>> */
    protected array $api = ['subjects.json' => [], 'invoices.json' => [], 'expenses.json' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sid = $this->createSupplier();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->sid > 0) {
            $this->db->pdo()->prepare('DELETE FROM import_jobs WHERE supplier_id = ?')->execute([$this->sid]);
        }
        parent::tearDown();
    }

    protected function useVatPayer(bool $payer): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET is_vat_payer = ? WHERE id = ?')->execute([$payer ? 1 : 0, $this->sid]);
        $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ?')->execute([$this->sid]);
        $pdo->prepare(
            "INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
             VALUES (?, '1900-01-01', ?, 0)"
        )->execute([$this->sid, $payer ? 1 : 0]);
    }

    /** @return array<string,mixed> */
    protected function subject(int $id, string $type = 'customer'): array
    {
        return [
            'id' => $id, 'type' => $type, 'name' => 'Testovací subjekt ' . $id,
            'street' => 'Testovací 1', 'city' => 'Praha', 'zip' => '11000', 'country' => 'CZ',
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> řádek úlohy po doběhnutí
     */
    protected function runImport(array $params = []): array
    {
        $client = $this->container->get(FakturoidClient::class);
        $client->setCredentials($this->sid, 'testovaci-ucet', 'import@example.test', 'syntheticky-klic');
        $api = $this->api;
        $handler = static function (RequestInterface $request) use ($api) {
            $path = $request->getUri()->getPath();
            parse_str($request->getUri()->getQuery(), $query);
            $page = (int) ($query['page'] ?? 1);
            foreach ($api as $endpoint => $items) {
                if (str_ends_with($path, '/' . $endpoint)) {
                    $chunk = array_slice($items, ($page - 1) * 40, 40);
                    return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($chunk)));
                }
            }
            return Create::promiseFor(new Response(404, [], 'not found'));
        };
        (new \ReflectionProperty($client, 'http'))->setValue($client, new Client([
            'handler' => HandlerStack::create($handler),
            'http_errors' => false,
        ]));

        $jobs = $this->container->get(ImportJobRepository::class);
        $jobId = $jobs->create($this->sid, 'fakturoid', $params + [
            'include_clients' => true, 'include_issued' => true, 'include_received' => true,
        ], $this->userId);
        $this->container->get(FakturoidImportService::class)->run($jobId);

        $job = $jobs->find($jobId, $this->sid);
        self::assertIsArray($job);
        return $job;
    }

    /** @return array{header: array<string,mixed>, items: list<array<string,mixed>>} */
    protected function issued(int $fakturoidId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, invoice_type, prices_include_vat, rounding_mode, total_without_vat, total_vat, total_with_vat, rounding
               FROM invoices WHERE supplier_id = ? AND fakturoid_id = ?'
        );
        $stmt->execute([$this->sid, $fakturoidId]);
        $header = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($header, "Vydaný doklad Fakturoid #{$fakturoidId} nevznikl.");
        $items = $pdo->prepare(
            'SELECT quantity, unit_price_without_vat, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat
               FROM invoice_items WHERE invoice_id = ? ORDER BY order_index, id'
        );
        $items->execute([(int) $header['id']]);
        unset($header['id']);
        return ['header' => $header, 'items' => $items->fetchAll(\PDO::FETCH_ASSOC)];
    }

    /** @return array{header: array<string,mixed>, items: list<array<string,mixed>>} */
    protected function received(int $fakturoidId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, document_kind, prices_include_vat, total_without_vat, total_vat, total_with_vat, rounding,
                    vat_overrides, extraction_warning
               FROM purchase_invoices WHERE supplier_id = ? AND fakturoid_id = ?'
        );
        $stmt->execute([$this->sid, $fakturoidId]);
        $header = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($header, "Přijatý doklad Fakturoid #{$fakturoidId} nevznikl.");
        $items = $pdo->prepare(
            'SELECT quantity, unit_price_without_vat, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat
               FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index, id'
        );
        $items->execute([(int) $header['id']]);
        unset($header['id']);
        return ['header' => $header, 'items' => $items->fetchAll(\PDO::FETCH_ASSOC)];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    protected function document(int $id, int $subjectId, array $lines, array $over = []): array
    {
        return $over + [
            'id' => $id,
            'subject_id' => $subjectId,
            'document_type' => 'invoice',
            'number' => 'FA' . $id,
            'original_number' => 'DOD-' . $id,
            'variable_symbol' => (string) $id,
            'issued_on' => '2094-03-10',
            'taxable_fulfillment_due' => '2094-03-10',
            'due_on' => '2094-03-24',
            'currency' => 'CZK',
            'status' => 'open',
            'lines' => $lines,
        ];
    }
}
