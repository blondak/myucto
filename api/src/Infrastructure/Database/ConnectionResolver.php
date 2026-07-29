<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;

/**
 * Epic F0 — seam pro budoucí shard-routing per supplier.
 *
 * Dnes vrací sdílené PDO z Connection (všechny firmy žijí v jedné databázi).
 * Až se účetní data (Epic F1+: účtová osnova, deník, hlavní kniha) rozrostou,
 * může tato třída směrovat každou firmu na vlastní databázi/shard — např.
 * lookup v mapovací tabulce supplier_id → DSN + pool spojení.
 *
 * Pravidlo: NOVÝ účetní kód (F1+) si PDO bere výhradně přes forSupplier(),
 * nikdy přímo z Connection — přepnutí na shardy pak nevyžaduje žádné změny
 * volajících. Stávající (pre-F0) kód zůstává na Connection beze změny.
 */
final class ConnectionResolver
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Vrátí PDO spojení pro data dané firmy. Dnes vždy sdílené spojení;
     * $supplierId je součástí kontraktu kvůli budoucímu routingu.
     */
    public function forSupplier(int $supplierId): PDO
    {
        return $this->db->pdo();
    }
}
