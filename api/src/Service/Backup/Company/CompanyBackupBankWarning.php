<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Upozornění neblokuje přenos na jinou instanci ani nemění bankovní vlastnictví. */
final class CompanyBackupBankWarning
{
    /** @return array{code:string,message:string} */
    public static function export(): array
    {
        return [
            'code' => 'bank_account_restore_same_instance_risk',
            'message' => 'Záloha je určena především k obnově do jiné instance. '
                . 'Při obnově vedle původní firmy může stejný bankovní účet způsobit '
                . 'nejednoznačné vlastnictví jejích historických výpisů bez supplier_id '
                . 'a znepřístupnit je. Doplnění vlastníka v záloze nemění původní data.',
        ];
    }

    /** @return array{code:string,message:string} */
    public static function collision(): array
    {
        return [
            'code' => 'bank_account_restore_target_collision',
            'message' => 'Na cílové instanci je již evidován bankovní účet ze zálohy. '
                . 'Obnova může znepřístupnit původní historické výpisy bez supplier_id '
                . 'kvůli nejednoznačnému vlastnictví. Doporučujeme obnovu do jiné instance. '
                . 'Jde o varování, obnova není blokována a původní data se nemění.',
        ];
    }
}
