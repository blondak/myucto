<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stock\SalesOrderException;

/**
 * Pojistka proti dvojí fakturaci objednávek ze Shoptetu.
 *
 * V jednom e-shopu smí daňové doklady vystavovat právě jeden systém. Když je firma
 * nastavená na „Doklady vystavuje Shoptet", MyÚčto doklad z importované objednávky
 * nevystaví — faktura přijde importem dokladů ze Shoptetu a na objednávku se naváže.
 * Druhou pojistkou je objednávka označená k ruční kontrole (neshoda DPH údajů Shoptetu
 * s vlastním zařazením MyÚčta nebo rozdíl součtu): doklad vznikne až po potvrzení
 * kontroly.
 *
 * Volá se z KAŽDÉ cesty, která tvoří doklad z objednávky. Dnes je to jediná cesta
 * {@see \MyInvoice\Service\Stock\SalesOrderInvoiceService::createDraft()}.
 */
final class ShoptetInvoicingGuard
{
    public function __construct(private readonly Connection $db) {}

    public function assertCanInvoice(int $supplierId, int $orderId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT o.external_source, so.review_required, s.documents_issuer
               FROM sales_orders o
          LEFT JOIN shoptet_orders so ON so.order_id = o.id AND so.supplier_id = o.supplier_id
          LEFT JOIN shoptet_settings s ON s.supplier_id = o.supplier_id
              WHERE o.supplier_id = ? AND o.id = ?"
        );
        try {
            $stmt->execute([$supplierId, $orderId]);
        } catch (\PDOException $e) {
            // Instance bez migrace napojení Shoptetu objednávky ze Shoptetu mít nemůže.
            if (str_contains($e->getMessage(), 'shoptet_')) {
                return;
            }
            throw $e;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || (string) $row['external_source'] !== 'shoptet') {
            return;
        }
        if ((string) ($row['documents_issuer'] ?? ShoptetSettingsService::DOCUMENTS_MYUCTO) === ShoptetSettingsService::DOCUMENTS_SHOPTET) {
            throw new SalesOrderException(
                'shoptet_documents_issued_by_shoptet',
                'Doklady k objednávkám ze Shoptetu vystavuje podle nastavení Shoptet. Fakturu naimportujte '
                    . 'v E-shop → Shoptet → Doklady, k objednávce se naváže sama. Chcete-li fakturovat '
                    . 'v MyÚčtu, přepněte nastavení a vystavování dokladů v Shoptetu vypněte.',
                409,
            );
        }
        if ((int) ($row['review_required'] ?? 0) === 1) {
            throw new SalesOrderException(
                'shoptet_order_review_required',
                'Objednávka ze Shoptetu čeká na kontrolu DPH (neshoda údajů Shoptetu s vlastním zařazením '
                    . 'nebo rozdíl součtu). Zkontrolujte ji v E-shop → Shoptet → Objednávky a potvrďte kontrolu.',
                422,
            );
        }
    }
}
