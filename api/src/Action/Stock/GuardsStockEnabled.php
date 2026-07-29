<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Sdílený guard pro moduly Skladu (Epic SKLAD) — modul je opt-in přes
 * supplier.stock_enabled (viz SettingsAction). Vypnutá firma dostane 403
 * na KAŽDOU akci (i čtecí), aby FE nedostal data z modulu, který si firma
 * nezapnula (konzistentní s ostatními opt-in přepínači v appce).
 */
trait GuardsStockEnabled
{
    protected function guardStockEnabled(Connection $db, int $supplierId, Response $response, ?Response &$err): bool
    {
        $stmt = $db->pdo()->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $enabled = (bool) $stmt->fetchColumn();
        if (!$enabled) {
            $err = Json::error($response, 'stock_disabled', 'Skladový modul není pro tuto firmu zapnutý.', 403);
            return false;
        }
        $err = null;
        return true;
    }
}
