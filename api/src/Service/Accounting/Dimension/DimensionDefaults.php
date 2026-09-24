<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionDefaultRepository;
use MyInvoice\Repository\DimensionRepository;
use PDO;

/**
 * Výchozí dimenze klienta a zakázky → hlavička dokladu (Firma → Dimenze).
 *
 * Jediné místo, které rozhoduje o přednosti: zakázka > klient, typ po typu. Volá ho
 * předvyplnění v editorech ({@see resolve()}) i účtování ({@see forSource()}), aby
 * editor nenabídl něco jiného, než co by doplnilo účtování.
 *
 * Doplňuje se jen typ, pro který doklad hodnotu nemá — dimenze uvedená na dokladu
 * vždy vyhrává. Neaktivní typ, uzavřená hodnota a hodnota, kterou firma nevidí
 * (firma opustila skupinu s globálním typem), se přeskočí.
 */
final class DimensionDefaults
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{header:array<int,int>, sources:array<int,string>} typ => hodnota, typ => 'project'|'client'
     */
    public function resolve(int $supplierId, ?int $clientId, ?int $projectId): array
    {
        $repo = new DimensionDefaultRepository($this->db);
        $layers = [];
        if ($projectId !== null && $projectId > 0) {
            $layers['project'] = $repo->forEntity($supplierId, 'project', $projectId);
        }
        if ($clientId !== null && $clientId > 0) {
            $layers['client'] = $repo->forEntity($supplierId, 'client', $clientId);
        }
        return $this->merge($supplierId, $layers);
    }

    /**
     * Náhradní hlavička zdrojového dokladu účetního zápisu.
     *
     *   • faktura: zakázka faktury > odběratel
     *   • přijatá faktura: zakázka > dodavatel (vendor_id)
     *   • pokladní doklad: zakázka dokladu > dimenze placené (přijaté) faktury,
     *     včetně jejích výchozích
     *   • bankovní pohyb: dimenze hrazených faktur (vystavených i přijatých) včetně
     *     jejich výchozích; u více faktur jen hodnoty společné všem
     *
     * @return array<int,int> typ => hodnota
     */
    public function forSource(int $supplierId, string $sourceType, int $sourceId): array
    {
        return match ($sourceType) {
            'invoice' => $this->forDocument($supplierId, 'invoice', $sourceId),
            'purchase_invoice' => $this->forDocument($supplierId, 'purchase_invoice', $sourceId),
            'cash' => $this->forCash($supplierId, $sourceId),
            'bank' => $this->forBank($supplierId, $sourceId),
            default => [],
        };
    }

    /**
     * Hlavička doklad + náhradní hodnoty pro chybějící typy.
     *
     * @param array<int,int> $header
     * @param array<int,int> $defaults
     * @return array<int,int>
     */
    public static function fill(array $header, array $defaults): array
    {
        foreach ($defaults as $typeId => $valueId) {
            if (!isset($header[$typeId])) {
                $header[$typeId] = $valueId;
            }
        }
        ksort($header);
        return $header;
    }

    /** @return array<int,int> */
    private function forDocument(int $supplierId, string $docType, int $docId): array
    {
        [$clientId, $projectId] = $this->documentParties($supplierId, $docType, $docId);
        return $this->resolve($supplierId, $clientId, $projectId)['header'];
    }

    /** @return array<int,int> */
    private function forCash(int $supplierId, int $cashId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT project_id, invoice_id, purchase_invoice_id FROM cash_documents WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$cashId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [];
        }
        $own = $row['project_id'] !== null ? $this->resolve($supplierId, null, (int) $row['project_id'])['header'] : [];
        $linked = match (true) {
            $row['invoice_id'] !== null => $this->effectiveHeader($supplierId, 'invoice', (int) $row['invoice_id']),
            $row['purchase_invoice_id'] !== null => $this->effectiveHeader($supplierId, 'purchase_invoice', (int) $row['purchase_invoice_id']),
            default => [],
        };
        return self::fill($own, $linked);
    }

    /**
     * Hlavička společná všem dokladům, které pohyb hradí. Hradí-li doklady s různou
     * hodnotou typu, typ tu chybí — rozdělí ho {@see DimensionStamper} po řádcích.
     *
     * @return array<int,int>
     */
    private function forBank(int $supplierId, int $transactionId): array
    {
        $documents = $this->bankDocuments($supplierId, $transactionId)['documents'];
        if ($documents === []) {
            return [];
        }
        $common = array_shift($documents)['header'];
        foreach ($documents as $doc) {
            $common = array_intersect_assoc($common, $doc['header']);
        }
        return $common;
    }

    /**
     * Doklady, které bankovní pohyb hradí, s částkou alokace a efektivní hlavičkou.
     * Stejné zdroje jako úhrada v BankPostingService: vystavené faktury z invoice_payments
     * (bez nich z párování, jinak matched_invoice_id), přijaté faktury z párování.
     * Více alokací na tentýž doklad se sečte.
     *
     * @return array{incoming:bool, documents:list<array{doc_type:string, doc_id:int, amount:float, header:array<int,int>}>}
     */
    public function bankDocuments(int $supplierId, int $transactionId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT bt.amount, bt.matched_invoice_id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ? AND ' . BankStatementOwnershipResolver::sql()
        );
        $stmt->execute([$transactionId, ...BankStatementOwnershipResolver::params($supplierId)]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tx === false) {
            return ['incoming' => false, 'documents' => []];
        }
        $payments = $pdo->prepare(
            'SELECT ip.invoice_id, SUM(ip.amount) AS amount
               FROM invoice_payments ip
               JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = ?
              WHERE ip.bank_transaction_id = ?
           GROUP BY ip.invoice_id
           ORDER BY MIN(ip.id)'
        );
        $payments->execute([$supplierId, $transactionId]);
        $invoices = [];
        foreach ($payments->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $invoices[(int) $r['invoice_id']] = (float) $r['amount'];
        }
        $matches = $pdo->prepare(
            'SELECT invoice_id, purchase_invoice_id, SUM(amount) AS amount
               FROM payment_matches
              WHERE bank_transaction_id = ? AND supplier_id = ?
           GROUP BY invoice_id, purchase_invoice_id
           ORDER BY MIN(id)'
        );
        $matches->execute([$transactionId, $supplierId]);
        $matchedInvoices = [];
        $purchases = [];
        foreach ($matches->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['purchase_invoice_id'] !== null) {
                $purchases[(int) $r['purchase_invoice_id']] = (float) $r['amount'];
            } elseif ($r['invoice_id'] !== null) {
                $matchedInvoices[(int) $r['invoice_id']] = (float) $r['amount'];
            }
        }
        if ($invoices === []) {
            $invoices = $matchedInvoices;
        }
        if ($invoices === [] && $tx['matched_invoice_id'] !== null) {
            $invoices[(int) $tx['matched_invoice_id']] = abs((float) $tx['amount']);
        }
        $documents = [];
        foreach (['invoice' => $invoices, 'purchase_invoice' => $purchases] as $docType => $ids) {
            foreach ($ids as $docId => $amount) {
                $documents[] = [
                    'doc_type' => $docType,
                    'doc_id' => $docId,
                    'amount' => round(abs($amount), 2),
                    'header' => $this->effectiveHeader($supplierId, $docType, $docId),
                ];
            }
        }
        return ['incoming' => (float) $tx['amount'] > 0, 'documents' => $documents];
    }

    /**
     * Hlavička dokladu, jak ji vidí účtování: vlastní dimenze + výchozí.
     *
     * @return array<int,int>
     */
    public function effectiveHeader(int $supplierId, string $docType, int $docId): array
    {
        $own = (new DimensionAssignmentRepository($this->db))->documentDimensions($supplierId, $docType, $docId)['header'];
        return self::fill($own, $this->forDocument($supplierId, $docType, $docId));
    }

    /** @return array{0:?int,1:?int} klient, zakázka */
    private function documentParties(int $supplierId, string $docType, int $docId): array
    {
        $sql = match ($docType) {
            'invoice' => 'SELECT client_id, project_id FROM invoices WHERE id = ? AND supplier_id = ?',
            'purchase_invoice' => 'SELECT vendor_id AS client_id, project_id FROM purchase_invoices WHERE id = ? AND supplier_id = ?',
            default => null,
        };
        if ($sql === null) {
            return [null, null];
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$docId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [null, null];
        }
        return [
            $row['client_id'] !== null ? (int) $row['client_id'] : null,
            $row['project_id'] !== null ? (int) $row['project_id'] : null,
        ];
    }

    /**
     * @param array<string,array<int,int>> $layers zdroj => typ => hodnota, v pořadí přednosti
     * @return array{header:array<int,int>, sources:array<int,string>}
     */
    private function merge(int $supplierId, array $layers): array
    {
        $all = [];
        foreach ($layers as $dims) {
            array_push($all, ...array_values($dims));
        }
        if ($all === []) {
            return ['header' => [], 'sources' => []];
        }
        $dimRepo = new DimensionRepository($this->db);
        $types = [];
        foreach ($dimRepo->listTypes($supplierId, false) as $t) {
            $types[$t['id']] = true;
        }
        $values = $dimRepo->valuesByIds($supplierId, $all);
        $header = [];
        $sources = [];
        foreach ($layers as $source => $dims) {
            foreach ($dims as $typeId => $valueId) {
                $value = $values[$valueId] ?? null;
                if (isset($header[$typeId]) || $value === null || !$value['is_active']
                    || $value['type_id'] !== $typeId || !isset($types[$typeId])) {
                    continue;
                }
                $header[$typeId] = $valueId;
                $sources[$typeId] = $source;
            }
        }
        ksort($header);
        ksort($sources);
        return ['header' => $header, 'sources' => $sources];
    }
}
