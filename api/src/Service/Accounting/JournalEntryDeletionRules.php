<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Kdy smí zápis z deníku zmizet bez protizápisu — jediné místo pravidla pro ruční
 * mazání v deníku ({@see \MyInvoice\Action\Accounting\JournalAction::delete()},
 * {@see \MyInvoice\Action\Accounting\JournalAction::deleteReversalPair()}) i pro
 * doklady, které odklízejí svůj zápis samy ({@see DocumentJournalPurge}).
 *
 * Řádek zápisu musí nést `period_status`, `entry_date`, `reversed_by` a pro
 * {@see blockSingle()} i `is_reversal`.
 */
final class JournalEntryDeletionRules
{
    public const SINGLE_SOURCE_TYPES = ['manual', 'invoice', 'purchase_invoice', 'bank', 'depreciation'];

    /**
     * Storno dvojice: shodné s mazáním jednoho zápisu, bez odpisů. U nich se maže i řádek
     * v `depreciation_entries` a dvojice po stornu odpisu vzniká jinou cestou.
     */
    public const PAIR_SOURCE_TYPES   = ['manual', 'invoice', 'purchase_invoice', 'bank'];

    /**
     * Brána období a zámku společná pro jeden zápis i storno dvojici.
     *
     * @param array<string,mixed> $entry
     * @return array{code:string, message:string}|null
     */
    public static function blockPeriod(array $entry, ?string $lockedUntil): ?array
    {
        if ((string) $entry['period_status'] !== 'open') {
            return [
                'code'    => 'period_not_open',
                'message' => 'Zápis #' . (int) $entry['id'] . ' je v období „' . $entry['period_status']
                    . '“ — smazat lze jen zápis v otevřeném období.',
            ];
        }
        if ($lockedUntil !== null && (string) $entry['entry_date'] <= $lockedUntil) {
            return [
                'code'    => 'date_locked',
                'message' => 'Datum zápisu spadá do uzamčené části účetnictví.',
            ];
        }
        return null;
    }

    /**
     * Jeden zápis: kromě období nesmí být stornovaný ani sám protizápisem.
     *
     * @param array<string,mixed> $entry
     * @return array{code:string, message:string}|null
     */
    public static function blockSingle(array $entry, ?string $lockedUntil): ?array
    {
        if ($block = self::blockPeriod($entry, $lockedUntil)) {
            return $block;
        }
        if ($entry['reversed_by'] !== null || (bool) $entry['is_reversal']) {
            return [
                'code'    => 'entry_has_reversal',
                'message' => 'Stornovaný zápis ani jeho protizápis nelze smazat samostatně.',
            ];
        }
        return null;
    }
}
