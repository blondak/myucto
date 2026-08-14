<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Vytažení první přílohy (originálního dokladu dodavatele) z Fakturoid expense
 * JSON — issue #261: import přijatých faktur četl skalární pole `attachment`,
 * které Fakturoid API v3 nevrací, takže se přílohy nikdy nestáhly.
 *
 * Fakturoid API v3 vrací přílohy v poli `attachments` (seznam objektů, každý
 * s `download_url` a `filename`). Bereme první — výdaj má typicky jeden doklad.
 */
final class FakturoidExpenseAttachment
{
    /**
     * @param array<string,mixed> $exp Fakturoid expense JSON
     * @return ?array{download_url:string, filename:string}  null = výdaj bez přílohy
     */
    public static function resolve(array $exp): ?array
    {
        $att = ($exp['attachments'] ?? [])[0] ?? null;
        if (!is_array($att)) {
            return null;
        }
        $url = (string) ($att['download_url'] ?? '');
        if ($url === '') {
            return null;
        }
        // Fakturoid posílá `filename`; `file_name` je defenzivní fallback.
        $name = (string) ($att['filename'] ?? $att['file_name'] ?? '');
        if ($name === '') {
            $name = ((string) ($exp['number'] ?? 'expense')) . '.pdf';
        }

        return ['download_url' => $url, 'filename' => $name];
    }
}
