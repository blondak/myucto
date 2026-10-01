<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionRepository;
use PDO;

/**
 * Návrh dimenzí hlavičky přijatého dokladu z historie dodavatele (Účtování podle
 * dimenzí, F5): hodnoty z posledního nestornovaného dokladu téhož dodavatele, který
 * nějakou dimenzi hlavičky má. Bez AI, jen dotaz do vlastní evidence firmy.
 *
 * Je to nejslabší vrstva předvyplnění — výchozí dimenze dodavatele a zakázky ji
 * přebíjí ({@see DimensionService::prefill()}) a ruční volba uživatele vždy. Bere se
 * jen explicitní hlavička dokladu (document_dimensions), ne to, co doplnilo účtování:
 * výchozí hodnoty karty se nabídnou samy a pravidla by se tu jen opakovala.
 *
 * Doklad cizí firmy se nikdy nepoužije (predikát supplier_id na dokladu i na
 * dimenzích), hodnota, kterou firma nevidí, uzavřená hodnota a neaktivní typ se
 * přeskočí.
 */
final class VendorDimensionHistory
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array<int,int> typ => hodnota
     */
    public function lastHeader(int $supplierId, int $vendorId, ?int $excludePurchaseInvoiceId = null): array
    {
        if ($vendorId <= 0) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT dd.dimension_type_id, dd.dimension_value_id
               FROM document_dimensions dd
              WHERE dd.supplier_id = ? AND dd.doc_type = 'purchase_invoice' AND dd.item_no = 0
                AND dd.doc_id = (
                    SELECT pi.id
                      FROM purchase_invoices pi
                     WHERE pi.supplier_id = ? AND pi.vendor_id = ? AND pi.status <> 'cancelled' AND pi.id <> ?
                       AND EXISTS (SELECT 1 FROM document_dimensions h
                                    WHERE h.supplier_id = pi.supplier_id AND h.doc_type = 'purchase_invoice'
                                      AND h.doc_id = pi.id AND h.item_no = 0)
                     ORDER BY pi.issue_date DESC, pi.id DESC
                     LIMIT 1)"
        );
        $stmt->execute([$supplierId, $supplierId, $vendorId, $excludePurchaseInvoiceId ?? 0]);
        $raw = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $raw[(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
        }
        if ($raw === []) {
            return [];
        }
        $repo = new DimensionRepository($this->db);
        $types = [];
        foreach ($repo->listTypes($supplierId, false) as $t) {
            $types[$t['id']] = true;
        }
        $values = $repo->valuesByIds($supplierId, array_values($raw));
        $out = [];
        foreach ($raw as $typeId => $valueId) {
            $value = $values[$valueId] ?? null;
            if ($value !== null && $value['is_active'] && $value['type_id'] === $typeId && isset($types[$typeId])) {
                $out[$typeId] = $valueId;
            }
        }
        ksort($out);
        return $out;
    }
}
