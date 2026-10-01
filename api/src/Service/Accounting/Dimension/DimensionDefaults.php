<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionDefaultRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Product\ProductPostingDefaults;
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
     * Výchozí dimenze položek z produktu: produkt > jeho kategorie > nadřízené kategorie,
     * typ po typu (Účtování podle dimenzí, F1). Volá je předvyplnění položky v editoru
     * i {@see DimensionStamper}, takže položka bez vlastních dimenzí dostane při
     * zaúčtování totéž, co by jí nabídl editor.
     *
     * @param list<int> $productIds
     * @return array<int,array{header:array<int,int>, sources:array<int,string>}> produkt => typ => hodnota, typ => 'product'|'product_category'
     */
    public function forProducts(int $supplierId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($productIds === []) {
            return [];
        }
        $repo = new DimensionDefaultRepository($this->db);
        $own = $repo->forEntities($supplierId, 'product', $productIds);
        $chains = (new ProductPostingDefaults($this->db))->categoryChains($supplierId, $productIds);
        $categoryIds = $chains === [] ? [] : array_merge(...array_values($chains));
        $categories = $repo->forEntities($supplierId, 'product_category', $categoryIds);
        if ($own === [] && $categories === []) {
            return [];
        }
        $out = [];
        foreach ($productIds as $productId) {
            $layers = ['product' => $own[$productId] ?? []];
            foreach ($chains[$productId] ?? [] as $categoryId) {
                $layers['product_category:' . $categoryId] = $categories[$categoryId] ?? [];
            }
            $merged = $this->merge($supplierId, $layers);
            if ($merged['header'] === []) {
                continue;
            }
            $merged['sources'] = array_map(static fn (string $s): string => explode(':', $s)[0], $merged['sources']);
            $out[$productId] = $merged;
        }
        return $out;
    }

    /**
     * Náhradní hlavička zdrojového dokladu účetního zápisu.
     *
     *   • faktura: zakázka faktury > odběratel
     *   • přijatá faktura: zakázka > dodavatel (vendor_id)
     *   • pokladní doklad: zakázka dokladu > dimenze placené (přijaté) faktury,
     *     včetně jejích výchozích
     *   • bankovní pohyb: dimenze hrazených faktur (vystavených i přijatých)
     *     a ostatních pohledávek a závazků včetně jejich výchozích; u více dokladů
     *     jen hodnoty společné všem
     *   • ostatní pohledávka / závazek: protistrana
     *   • vzájemný zápočet: protistrana (dimenze započtených dokladů nesou řádky,
     *     {@see DimensionStamper})
     *   • zápočet proti účtu: dimenze vyrovnávané faktury včetně jejích výchozích
     *   • majetek (zařazení, odpis, vyřazení): položka přijaté faktury, ze které
     *     karta vznikla (položka > produkt > kategorie > hlavička faktury)
     *
     * @return array<int,int> typ => hodnota
     */
    public function forSource(int $supplierId, string $sourceType, int $sourceId): array
    {
        return $this->sourceDimensions($supplierId, $sourceType, $sourceId)['header'];
    }

    /**
     * {@see forSource()} i s rozpadem: zápočty a karta majetku přebírají z faktury
     * i její rozpad (typ => hodnota => podíl). Typ nese buď hodnotu, nebo rozpad.
     *
     * @return array{header:array<int,int>, splits:array<int,array<int,float>>}
     */
    public function sourceDimensions(int $supplierId, string $sourceType, int $sourceId): array
    {
        $header = static fn (array $h): array => ['header' => $h, 'splits' => []];
        return match ($sourceType) {
            'invoice' => $header($this->forDocument($supplierId, 'invoice', $sourceId)),
            'purchase_invoice' => $header($this->forDocument($supplierId, 'purchase_invoice', $sourceId)),
            'cash' => $header($this->forCash($supplierId, $sourceId)),
            'bank' => $header($this->forBank($supplierId, $sourceId)),
            'other_item' => $header($this->forDocument($supplierId, 'other_item', $sourceId)),
            'offset' => $header($this->forOffset($supplierId, $sourceId)),
            'settlement' => $this->forSettlement($supplierId, $sourceId),
            'asset', 'asset_disposal', 'depreciation' => $this->forAsset($supplierId, $this->assetId($supplierId, $sourceType, $sourceId)),
            default => $header([]),
        };
    }

    /** @var array<string,?int> zápis odpisu => karta (vazba se nemění, platí po celý běh) */
    private array $assetIds = [];

    /**
     * Karta majetku zápisu: zařazení a vyřazení mají za zdroj kartu, účetní odpis
     * řádek `depreciation_entries`.
     */
    public function assetId(int $supplierId, string $sourceType, int $sourceId): ?int
    {
        if ($sourceType === 'asset' || $sourceType === 'asset_disposal') {
            return $sourceId;
        }
        if ($sourceType !== 'depreciation') {
            return null;
        }
        $key = $supplierId . ':' . $sourceId;
        if (!array_key_exists($key, $this->assetIds)) {
            $stmt = $this->db->pdo()->prepare('SELECT asset_id FROM depreciation_entries WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$sourceId, $supplierId]);
            $id = $stmt->fetchColumn();
            $this->assetIds[$key] = $id === false ? null : (int) $id;
        }
        return $this->assetIds[$key];
    }

    /**
     * Dimenze karty majetku zděděné z přijaté faktury pořízení: vlastní dimenze
     * a rozpad položky > produkt položky (> kategorie) > hlavička faktury s rozpadem
     * a výchozími hodnotami, typ po typu. Karta bez vazby na fakturu nic nedědí.
     *
     * @return array{header:array<int,int>, splits:array<int,array<int,float>>}
     */
    private function forAsset(int $supplierId, ?int $assetId): array
    {
        if ($assetId === null) {
            return ['header' => [], 'splits' => []];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.purchase_invoice_id, i.stock_item_id,
                    (SELECT COUNT(*) FROM purchase_invoice_items x
                      WHERE x.purchase_invoice_id = i.purchase_invoice_id
                        AND (x.order_index < i.order_index OR (x.order_index = i.order_index AND x.id <= i.id))) AS item_no
               FROM assets a
          LEFT JOIN purchase_invoice_items i ON i.id = a.purchase_invoice_item_id AND i.purchase_invoice_id = a.purchase_invoice_id
              WHERE a.id = ? AND a.supplier_id = ?'
        );
        $stmt->execute([$assetId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['purchase_invoice_id'] === null) {
            return ['header' => [], 'splits' => []];
        }
        $purchaseId = (int) $row['purchase_invoice_id'];
        $layers = [];
        if ((int) ($row['item_no'] ?? 0) > 0) {
            $itemNo = (int) $row['item_no'];
            $assignments = new DimensionAssignmentRepository($this->db);
            $layers[] = [
                'header' => $assignments->documentDimensions($supplierId, 'purchase_invoice', $purchaseId)['items'][$itemNo] ?? [],
                'splits' => $assignments->documentSplits($supplierId, 'purchase_invoice', $purchaseId)[$itemNo] ?? [],
            ];
            if ($row['stock_item_id'] !== null) {
                $productId = (int) $row['stock_item_id'];
                $layers[] = ['header' => $this->forProducts($supplierId, [$productId])[$productId]['header'] ?? [], 'splits' => []];
            }
        }
        $layers[] = $this->effectiveDimensions($supplierId, 'purchase_invoice', $purchaseId);
        return self::layer($layers);
    }

    /** @return array<int,int> */
    private function forOffset(int $supplierId, int $agreementId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT partner_id FROM offset_agreements WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$agreementId, $supplierId]);
        $partner = $stmt->fetchColumn();
        return $partner === false ? [] : $this->resolve($supplierId, (int) $partner, null)['header'];
    }

    /** @return array{header:array<int,int>, splits:array<int,array<int,float>>} */
    private function forSettlement(int $supplierId, int $settlementId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT doc_type, doc_id FROM invoice_settlements WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$settlementId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? ['header' => [], 'splits' => []]
            : $this->effectiveDimensions($supplierId, (string) $row['doc_type'], (int) $row['doc_id']);
    }

    /**
     * Hlavička dokladu i s rozpadem, jak ji vidí účtování: vlastní hodnoty a rozpad
     * hlavičky + výchozí hodnoty pro typy, které doklad nemá ani jako rozpad.
     *
     * @return array{header:array<int,int>, splits:array<int,array<int,float>>}
     */
    public function effectiveDimensions(int $supplierId, string $docType, int $docId): array
    {
        $assignments = new DimensionAssignmentRepository($this->db);
        return self::layer([
            [
                'header' => $assignments->documentDimensions($supplierId, $docType, $docId)['header'],
                'splits' => $assignments->documentSplits($supplierId, $docType, $docId)[0] ?? [],
            ],
            ['header' => $this->forDocument($supplierId, $docType, $docId), 'splits' => []],
        ]);
    }

    /**
     * Vrstvy dimenzí v pořadí přednosti: typ bere první vrstva, která ho má jako
     * hodnotu nebo jako rozpad.
     *
     * @param list<array{header:array<int,int>, splits:array<int,array<int,float>>}> $layers
     * @return array{header:array<int,int>, splits:array<int,array<int,float>>}
     */
    public static function layer(array $layers): array
    {
        $header = [];
        $splits = [];
        foreach ($layers as $layer) {
            foreach ($layer['splits'] as $typeId => $shares) {
                if (!isset($header[$typeId]) && !isset($splits[$typeId]) && $shares !== []) {
                    $splits[(int) $typeId] = $shares;
                }
            }
            foreach ($layer['header'] as $typeId => $valueId) {
                if (!isset($header[$typeId]) && !isset($splits[$typeId])) {
                    $header[(int) $typeId] = (int) $valueId;
                }
            }
        }
        ksort($header);
        ksort($splits);
        return ['header' => $header, 'splits' => $splits];
    }

    /**
     * Doklady vzájemného zápočtu s částkou a efektivní hlavičkou včetně rozpadu.
     * Vydané faktury snižují pohledávku (strana Dal), přijaté závazek (strana Má dáti).
     *
     * @return list<array{doc_type:string, doc_id:int, amount:float, header:array<int,int>, splits:array<int,array<int,float>>}>
     */
    public function offsetDocuments(int $supplierId, int $agreementId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT doc_type, doc_id, SUM(amount) AS amount FROM offset_agreement_items
              WHERE agreement_id = ? AND supplier_id = ?
           GROUP BY doc_type, doc_id
           ORDER BY MIN(id)'
        );
        $stmt->execute([$agreementId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'doc_type' => (string) $r['doc_type'],
                'doc_id' => (int) $r['doc_id'],
                'amount' => round(abs((float) $r['amount']), 2),
            ] + $this->effectiveDimensions($supplierId, (string) $r['doc_type'], (int) $r['doc_id']);
        }
        return $out;
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
        $others = $row['invoice_id'] === null && $row['purchase_invoice_id'] === null
            ? array_keys($this->otherItemAllocations($supplierId, 'cash_document_id', $cashId))
            : [];
        $linked = match (true) {
            $row['invoice_id'] !== null => $this->effectiveHeader($supplierId, 'invoice', (int) $row['invoice_id']),
            $row['purchase_invoice_id'] !== null => $this->effectiveHeader($supplierId, 'purchase_invoice', (int) $row['purchase_invoice_id']),
            // Víc ostatních položek: společné hodnoty, rozdílné typy rozdělí DimensionStamper (jako u banky).
            $others !== [] => self::commonHeader(array_column($this->cashDocuments($supplierId, $cashId)['documents'], 'header')),
            default => [],
        };
        return self::fill($own, $linked);
    }

    /**
     * Ostatní pohledávky a závazky hrazené pokladním dokladem (jen doklad bez vazby
     * na fakturu), ve tvaru {@see bankDocuments()}.
     *
     * @return array{incoming:bool, documents:list<array{doc_type:string, doc_id:int, amount:float, header:array<int,int>}>}
     */
    public function cashDocuments(int $supplierId, int $cashId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT doc_type FROM cash_documents
              WHERE id = ? AND supplier_id = ? AND invoice_id IS NULL AND purchase_invoice_id IS NULL'
        );
        $stmt->execute([$cashId, $supplierId]);
        $docType = $stmt->fetchColumn();
        if ($docType === false) {
            return ['incoming' => false, 'documents' => []];
        }
        $documents = [];
        foreach ($this->otherItemAllocations($supplierId, 'cash_document_id', $cashId) as $itemId => $amount) {
            $documents[] = [
                'doc_type' => 'other_item',
                'doc_id' => $itemId,
                'amount' => round(abs($amount), 2),
                'header' => $this->effectiveHeader($supplierId, 'other_item', $itemId),
            ];
        }
        return ['incoming' => $docType === 'in', 'documents' => $documents];
    }

    /**
     * @param list<array<int,int>> $headers
     * @return array<int,int>
     */
    private static function commonHeader(array $headers): array
    {
        if ($headers === []) {
            return [];
        }
        $common = array_shift($headers);
        foreach ($headers as $header) {
            $common = array_intersect_assoc($common, $header);
        }
        return $common;
    }

    /**
     * Hlavička společná všem dokladům, které pohyb hradí. Hradí-li doklady s různou
     * hodnotou typu, typ tu chybí — rozdělí ho {@see DimensionStamper} po řádcích.
     *
     * @return array<int,int>
     */
    private function forBank(int $supplierId, int $transactionId): array
    {
        return self::commonHeader(array_column($this->bankDocuments($supplierId, $transactionId)['documents'], 'header'));
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
        $others = $this->otherItemAllocations($supplierId, 'bank_transaction_id', $transactionId);
        $documents = [];
        foreach (['invoice' => $invoices, 'purchase_invoice' => $purchases, 'other_item' => $others] as $docType => $ids) {
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
     * Ostatní pohledávky a závazky hrazené platbou (nestornované alokace).
     *
     * @param 'bank_transaction_id'|'cash_document_id' $column
     * @return array<int,float> položka => částka
     */
    private function otherItemAllocations(int $supplierId, string $column, int $paymentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT other_item_id, SUM(amount) AS amount FROM other_item_allocations
              WHERE supplier_id = ? AND {$column} = ? AND reversed_on IS NULL
           GROUP BY other_item_id
           ORDER BY MIN(id)"
        );
        $stmt->execute([$supplierId, $paymentId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['other_item_id']] = (float) $r['amount'];
        }
        return $out;
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
            'other_item' => 'SELECT partner_id AS client_id, NULL AS project_id FROM other_items WHERE id = ? AND supplier_id = ?',
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
