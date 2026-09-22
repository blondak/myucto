<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Číslo převzatého pokladního dokladu, které nekoliduje s `uq_cashdoc_supplier_number`.
 *
 * Zdrojové programy číslují pokladnu po letech nebo po řadách, MyÚčto má číslo jedinečné
 * v celé firmě. Převod zkouší kandidáty zdroje v pořadí (číslo, číslo s rokem, s datem…);
 * když jsou obsazení všichni (dva doklady se stejnou řadou, číslem i datem), dostane
 * poslední kandidát příponu -2, -3… v limitu sloupce. Bez toho by kolize shodila celý
 * rok převodu.
 */
final class MigratedCashNumber
{
    public const MAX_LENGTH = 30;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param non-empty-list<string> $candidates kandidáti v pořadí zdroje (zkrátí se na 30 znaků)
     */
    public function allocate(int $supplierId, array $candidates, ImportProtocol $protocol, string $step, string $label): string
    {
        $taken = $this->db->pdo()->prepare('SELECT 1 FROM cash_documents WHERE supplier_id = ? AND doc_number = ? LIMIT 1');
        $isTaken = static function (string $number) use ($taken, $supplierId): bool {
            $taken->execute([$supplierId, $number]);
            $found = $taken->fetchColumn() !== false;
            $taken->closeCursor();
            return $found;
        };
        $last = '';
        foreach ($candidates as $candidate) {
            $last = mb_substr($candidate, 0, self::MAX_LENGTH);
            if (!$isTaken($last)) {
                return $last;
            }
        }
        for ($n = 2; ; $n++) {
            $suffix = '-' . $n;
            $number = mb_substr($last, 0, self::MAX_LENGTH - mb_strlen($suffix)) . $suffix;
            if (!$isTaken($number)) {
                $protocol->warn($step, 'cash_number_duplicate', sprintf(
                    'Pokladní doklad %s: číslo %s už v MyÚčtu má jiný doklad, převzat pod číslem %s. Zkontrolujte, zda nejde o duplicitu ve zdroji.',
                    $label, $last, $number
                ), ['document_no' => $number]);
                return $number;
            }
        }
    }
}
