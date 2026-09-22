<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Texty chyb úložiště nahraných souborů převodu. Liší se jen rodem a slovem: Money S3
 * a PREMIER nahrávají „zálohu", POHODA „export". Kódy chyb jsou pro všechny zdroje stejné.
 */
final class ChunkedUploadMessages
{
    public function __construct(
        /** Neplatný token nebo firma. */
        public readonly string $notFound,
        /** Chybí `meta.json` (zpracování neproběhlo nebo úklid adresář smazal). */
        public readonly string $metaNotFound,
        /** Chybí stav nahrávání nebo zámek nahrávání. */
        public readonly string $uploadNotFound,
        public readonly string $notUploading,
        public readonly string $offsetMismatch,
        public readonly string $storageNotWritable,
        public readonly string $chunkTooLarge,
        public readonly string $chunkExceedsSize,
        public readonly string $chunkWriteFailed,
        public readonly string $chunkEmpty,
    ) {}

    /** Záloha (Money S3, PREMIER). */
    public static function backup(): self
    {
        return new self(
            'Nahraná záloha nebyla nalezena.',
            'Nahraná záloha nebyla nalezena (mohla být už uklizena).',
            'Nahrávaná záloha nebyla nalezena.',
            'Záloha už je nahraná celá.',
            'Část zálohy nenavazuje na už nahraná data.',
            'Úložiště pro zálohy není zapisovatelné.',
            'Část zálohy je větší, než server přijme.',
            'Nahrávaná data jsou delší než ohlášená velikost zálohy.',
            'Část zálohy se nepodařilo uložit.',
            'Část zálohy je prázdná.',
        );
    }

    /** Export (POHODA). */
    public static function export(): self
    {
        return new self(
            'Nahraný export nebyl nalezen.',
            'Nahraný export nebyl nalezen (mohl být už uklizen).',
            'Nahrávaný export nebyl nalezen.',
            'Export už je nahraný celý.',
            'Část exportu nenavazuje na už nahraná data.',
            'Úložiště pro exporty není zapisovatelné.',
            'Část exportu je větší, než server přijme.',
            'Nahrávaná data jsou delší než ohlášená velikost exportu.',
            'Část exportu se nepodařilo uložit.',
            'Část exportu je prázdná.',
        );
    }
}
