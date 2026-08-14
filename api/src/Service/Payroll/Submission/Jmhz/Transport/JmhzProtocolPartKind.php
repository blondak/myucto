<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

enum JmhzProtocolPartKind: string
{
    case General = 'general';
    case Summary = 'SOUHRN';
    case Insurance = 'PVPOJ';
    case Form = 'FORM';

    /**
     * Ukázky ČSSZ mají u `subtype` jednou prázdnou hodnotu, jindy mezeru
     * a v jedné ukázce i vedoucí mezeru před `FORM`, takže se před porovnáním
     * ořezává.
     */
    public static function fromSubtype(string $subtype): self
    {
        $normalized = trim($subtype);
        if ($normalized === '') {
            return self::General;
        }
        $kind = self::tryFrom($normalized);
        if ($kind === null || $kind === self::General) {
            throw new JmhzTransportException(
                'jmhz_protocol_part_unknown',
                "Druh části protokolu `{$normalized}` není doložený.",
            );
        }

        return $kind;
    }
}
