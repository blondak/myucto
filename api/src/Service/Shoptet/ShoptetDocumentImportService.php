<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\ImportedIssuedDocumentPolicy;
use MyInvoice\Service\Import\InvoiceImportService;
use PDO;

/**
 * Import dokladů vystavených Shoptetem (režim „Doklady vystavuje Shoptet").
 *
 * Formáty: Shoptet exportuje daňové doklady, dobropisy a doklady k přijaté platbě
 * ve formátu ISDOC 6.0.2 (podpora.shoptet.cz/fakturace/, volba „Používat formát
 * ISDOC" v Nastavení → Objednávky → Doklady → Export dokladů) — to je hlavní cesta.
 * Zálohové faktury ISDOC nemají, ty jdou přes XML Pohoda (podpora.shoptet.cz/
 * export-dokladu/). Obojí zpracuje existující import vydaných faktur
 * ({@see InvoiceImportService}) se směrem napevno `issued`: doklad, jehož dodavatel
 * nemá IČO firmy, se odmítne a nic se nezaeviduje jako přijatá faktura.
 *
 * Co tahle vrstva přidává k obecnému importu:
 *   - číslo objednávky (ISDOC OrderReference, Pohoda numberOrder) NENÍ číslo zakázky
 *     — obecný import by pro každou objednávku založil zakázku; tady se odloží
 *     a faktura se naváže na importovanou objednávku ze Shoptetu,
 *   - druhá faktura k objednávce, která už fakturu má, se odmítne DŘÍV, než vznikne
 *     (dvojí tržba). Dobropis, doklad k přijaté platbě i opakovaný import téže faktury
 *     projdou,
 *   - pojistka proti dvojí fakturaci: v režimu „Doklady vystavuje MyÚčto" se import
 *     odmítne (oba systémy by vedly tutéž tržbu).
 * Kontrolu součtu řádků a odpočtu záloh dělá obecný import jednotně pro všechny
 * vydané doklady ({@see ImportedIssuedDocumentPolicy}).
 * Číslo dokladu ze Shoptetu se nepřečíslovává (je zároveň variabilní symbol).
 */
final class ShoptetDocumentImportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceImportService $importer,
        private readonly ShoptetSettingsService $settings,
    ) {}

    /**
     * @param list<array{name:string, content:string}> $files
     * @return array<string,mixed>
     */
    public function import(int $supplierId, array $files, int $userId): array
    {
        if ($this->settings->documentsIssuer($supplierId) !== ShoptetSettingsService::DOCUMENTS_SHOPTET) {
            throw new ShoptetImportException(
                'shoptet_documents_mode_myucto',
                'Podle nastavení vystavuje doklady MyÚčto. Import faktur ze Shoptetu by tutéž tržbu zaevidoval podruhé. '
                    . 'Pokud doklady opravdu vystavuje Shoptet, přepněte to v záložce Nastavení.',
                409,
            );
        }
        if ($files === []) {
            throw new ShoptetImportException('shoptet_file_required', 'Vyberte soubor s doklady (ISDOC, ZIP nebo XML Pohoda).');
        }

        // Kód objednávky podle DRUHU a čísla dokladu. Doklad k přijaté platbě a konečná
        // faktura běžně nesou týž variabilní symbol, klíč jen podle symbolu by jeden
        // přepsal druhým a faktura by se navázala podle cizího dokladu.
        /** @var array<string,?string> $refs */
        $refs = [];
        /** @var array<string,string> $claimed kód objednávky → VS faktury z téže dávky */
        $claimed = [];
        $transform = function (array $inv) use (&$refs, &$claimed, $supplierId): array {
            $order = trim((string) ($inv['project_number'] ?? ''));
            $order = $order !== '' ? $order : null;
            $inv['project_number'] = null;
            $type = (string) ($inv['invoice_type'] ?? 'invoice');
            $varsymbol = trim((string) ($inv['varsymbol'] ?? ''));
            if ($order !== null && $type === 'invoice') {
                $this->assertOrderNotInvoiced($supplierId, $order, $varsymbol, $claimed);
                $claimed[$order] = $varsymbol;
            }
            foreach ([trim((string) ($inv['document_number'] ?? '')), $varsymbol] as $key) {
                if ($key !== '' && !array_key_exists($type . '|' . $key, $refs)) {
                    $refs[$type . '|' . $key] = $order;
                }
            }

            return $inv;
        };

        $report = $this->importer->importBundle($files, $supplierId, $userId, 'issued', null, null, 'draft', $transform);

        $linked = 0;
        foreach ($report['results'] as &$result) {
            $invoiceId = (int) ($result['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }
            $invoice = $this->invoice($supplierId, $invoiceId);
            if ($invoice === null) {
                continue;
            }
            $type = (string) $invoice['invoice_type'];
            $order = null;
            foreach ([(string) ($result['document_number'] ?? ''), (string) ($result['varsymbol'] ?? ''), (string) $invoice['varsymbol']] as $key) {
                if ($key !== '' && array_key_exists($type . '|' . $key, $refs)) {
                    $order = $refs[$type . '|' . $key];
                    break;
                }
            }
            if ($order === null) {
                continue;
            }
            $link = $this->linkOrder($supplierId, $invoiceId, $type, $order);
            if ($link !== null) {
                $result['notes'][] = $link;
                $linked += str_starts_with($link, 'Navázáno') ? 1 : 0;
            }
            $result['shoptet_order_code'] = $order;
        }
        unset($result);

        $summary = $report['summary'] + ['linked_orders' => $linked];
        $this->db->pdo()->prepare(
            "INSERT INTO shoptet_import_batches
                (supplier_id, kind, source, status, file_name, summary_json, report_json, invoice_batch_id, created_by, applied_at)
             VALUES (?, 'documents', 'upload', 'applied', ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $supplierId,
            mb_substr(implode(', ', array_map(static fn (array $f): string => basename($f['name']), $files)), 0, 255),
            json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode($report['results'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $report['batch_id'] ?? null,
            $userId,
        ]);

        return ['summary' => $summary, 'results' => $report['results'], 'batch_id' => $report['batch_id'] ?? null,
            'shoptet_batch_id' => (int) $this->db->pdo()->lastInsertId()];
    }

    /**
     * Druhá faktura k objednávce by zaevidovala tutéž tržbu dvakrát, proto se odmítne
     * dřív, než vznikne. Projde jen faktura, která JE tou navázanou (opakovaný import se
     * pak přeskočí jako duplicita), a faktura k objednávce, jejíž faktura je stornovaná.
     *
     * @param array<string,string> $claimed faktury k objednávkám z téže dávky
     */
    private function assertOrderNotInvoiced(int $supplierId, string $orderCode, string $varsymbol, array $claimed): void
    {
        if (isset($claimed[$orderCode]) && $claimed[$orderCode] !== $varsymbol) {
            throw new ShoptetImportException(
                'shoptet_order_already_invoiced',
                sprintf(
                    'Fakturu %s jsme nenaimportovali: k objednávce %s je v téže dávce už faktura %s a tatáž tržba '
                        . 'by se zaevidovala dvakrát. Opravu vyfakturované objednávky importujte jako dobropis.',
                    $varsymbol,
                    $orderCode,
                    $claimed[$orderCode],
                ),
            );
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol
               FROM sales_orders o
               JOIN sales_order_invoice_links l ON l.order_id = o.id AND l.supplier_id = o.supplier_id
               JOIN invoices i ON i.id = l.invoice_id AND i.supplier_id = o.supplier_id
              WHERE o.supplier_id = ? AND o.external_source = 'shoptet' AND o.external_id = ?
                AND i.status <> 'cancelled'"
        );
        $stmt->execute([$supplierId, $orderCode]);
        $linked = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($linked === false || (string) $linked['varsymbol'] === $varsymbol) {
            return;
        }
        throw new ShoptetImportException(
            'shoptet_order_already_invoiced',
            sprintf(
                'Fakturu %s jsme nenaimportovali: objednávka %s už má navázanou fakturu %s (#%d) a tatáž tržba '
                    . 'by se zaevidovala dvakrát. Opravu vyfakturované objednávky importujte jako dobropis.',
                $varsymbol,
                $orderCode,
                (string) ($linked['varsymbol'] ?? '—'),
                (int) $linked['id'],
            ),
        );
    }

    /** @return array<string,mixed>|null */
    private function invoice(int $supplierId, int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, varsymbol, invoice_type, total_with_vat FROM invoices WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Naváže řádnou fakturu na objednávku ze Shoptetu. Jen `invoice`: vazba je jedna
     * na objednávku a patří konečnému dokladu, ne zálohovému listu ani dobropisu.
     */
    private function linkOrder(int $supplierId, int $invoiceId, string $invoiceType, string $orderCode): ?string
    {
        if ($invoiceType !== 'invoice') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT o.id, l.invoice_id FROM sales_orders o
          LEFT JOIN sales_order_invoice_links l ON l.order_id = o.id AND l.supplier_id = o.supplier_id
              WHERE o.supplier_id = ? AND o.external_source = 'shoptet' AND o.external_id = ?"
        );
        $stmt->execute([$supplierId, $orderCode]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order === false) {
            return sprintf('Objednávka %s ze Shoptetu v MyÚčtu není — fakturu jsme na ni nenavázali.', $orderCode);
        }
        if ($order['invoice_id'] !== null) {
            // Sem se dostane jen faktura k objednávce, jejíž navázaná faktura je
            // stornovaná; jinou druhou fakturu odmítl assertOrderNotInvoiced().
            return (int) $order['invoice_id'] === $invoiceId
                ? null
                : sprintf('Objednávka %s už má navázanou jinou fakturu (#%d).', $orderCode, (int) $order['invoice_id']);
        }
        $taken = $this->db->pdo()->prepare('SELECT order_id FROM sales_order_invoice_links WHERE supplier_id = ? AND invoice_id = ?');
        $taken->execute([$supplierId, $invoiceId]);
        if ($taken->fetchColumn() !== false) {
            return null;
        }
        $this->db->pdo()->prepare('INSERT INTO sales_order_invoice_links (supplier_id, order_id, invoice_id) VALUES (?, ?, ?)')
            ->execute([$supplierId, (int) $order['id'], $invoiceId]);

        return sprintf('Navázáno na objednávku %s ze Shoptetu.', $orderCode);
    }
}
