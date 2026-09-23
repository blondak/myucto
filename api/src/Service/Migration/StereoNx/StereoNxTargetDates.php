<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;

/** Stejná ochrana účetních/daňových dat pro oba režimy převodu Stereo. */
final class StereoNxTargetDates
{
    /** @param list<string> $dates @return list<array{level:string,code:string,message:string}> */
    public static function findings(Connection $db, int $supplierId, array $dates): array
    {
        $period = $db->pdo()->prepare("SELECT 1 FROM accounting_periods WHERE supplier_id = ?
            AND starts_on <= ? AND ends_on >= ? AND status <> 'open' LIMIT 1");
        $lock = $db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        $out = [];
        foreach (array_unique($dates) as $date) {
            $period->execute([$supplierId, $date, $date]);
            if ($period->fetchColumn() !== false) {
                $out['period_closed'] = ['level' => 'error', 'code' => 'period_closed',
                    'message' => 'Cílové účetní období je uzavřené.'];
            }
            if (is_string($lockedUntil) && $date <= $lockedUntil) {
                $out['date_locked'] = ['level' => 'error', 'code' => 'date_locked',
                    'message' => 'Datum zdrojového dokladu nebo pohybu spadá do uzamčeného období.'];
            }
        }
        return array_values($out);
    }
}
