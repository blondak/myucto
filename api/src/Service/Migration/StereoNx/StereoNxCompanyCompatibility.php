<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** Stejná kontrola zdrojového režimu pro náhled, job i oba převodníky. */
final class StereoNxCompanyCompatibility
{
    public static function assertAccountingMode(array $identity, string $targetMode): void
    {
        $sourceMode = $identity['accounting_mode'] ?? null;
        if (!in_array($sourceMode, ['tax_evidence', 'double_entry'], true) || $sourceMode === $targetMode) return;
        $label = static fn (string $mode): string => $mode === 'double_entry' ? 'podvojné účetnictví' : 'daňová evidence';
        throw new StereoNxException('source_accounting_mode_mismatch',
            'Záloha obsahuje režim „' . $label($sourceMode) . '“, ale cílová firma má nastaven režim „'
            . $label($targetMode) . '“. Vyberte odpovídající firmu nebo zkontrolujte režim v Nastavení firmy. '
            . 'Po změně zopakujte načtení zálohy a zkoušku nanečisto.');
    }
}
