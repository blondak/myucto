<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Premier;

/**
 * Zápis archivu Microsoft Cabinet (`.icab`) s jednou složkou a kompresí MSZIP pro testy.
 *
 * CFHEADER (36 B) → CFFOLDER (8 B) → CFFILE záznamy → CFDATA bloky. Rozbalený proud složky
 * (soubory za sebou) se dělí po 32 KB; každý blok je samostatný raw deflate proud
 * se signaturou `CK`, komprimovaný se slovníkem = předchozích až 32 KB rozbalených dat -
 * blok tak smí odkazovat do dat předchozího bloku, stejně jako to dělá MSZIP.
 */
final class CabWriter
{
    public const COMPRESSION_NONE = 0;
    public const COMPRESSION_MSZIP = 1;
    public const COMPRESSION_LZX = 3;

    private const BLOCK = 32768;

    /**
     * @param array<string,string> $files jméno v archivu => obsah
     * @param int $typeCompress CFFOLDER.typeCompress (LZX = 3 jen pro test odmítnutí)
     * @param int $flags CFHEADER.flags (0x0001 předchozí / 0x0002 další díl = vícedílný)
     */
    public static function write(string $path, array $files, int $typeCompress = self::COMPRESSION_MSZIP, int $flags = 0): void
    {
        $stream = implode('', $files);
        $blocks = '';
        $count = 0;
        $history = '';
        foreach (str_split($stream, self::BLOCK) as $chunk) {
            if ($typeCompress === self::COMPRESSION_NONE) {
                $data = $chunk;
            } else {
                $ctx = deflate_init(ZLIB_ENCODING_RAW, $history !== '' ? ['dictionary' => $history] : []);
                $data = 'CK' . deflate_add($ctx, $chunk, ZLIB_FINISH);
            }
            if (strlen($data) > 0xFFFF) {
                throw new \LengthException('Blok CFDATA je větší než 64 KB.');
            }
            $blocks .= pack('V', 0) . pack('v', strlen($data)) . pack('v', strlen($chunk)) . $data;
            $history = substr($history . $chunk, -self::BLOCK);
            $count++;
        }

        $entries = '';
        $offset = 0;
        foreach ($files as $name => $content) {
            $entries .= pack('V', strlen($content)) . pack('V', $offset) . pack('v', 0)
                . pack('v', ((2025 - 1980) << 9) | (6 << 5) | 15) . pack('v', (12 << 11) | (30 << 5)) . pack('v', 0x20)
                . str_replace('/', '\\', $name) . "\0";
            $offset += strlen($content);
        }

        $filesOffset = 36 + 8;
        $dataOffset = $filesOffset + strlen($entries);
        $total = $dataOffset + strlen($blocks);

        $header = 'MSCF' . pack('V', 0) . pack('V', $total) . pack('V', 0) . pack('V', $filesOffset) . pack('V', 0)
            . chr(3) . chr(1) . pack('v', 1) . pack('v', count($files)) . pack('v', $flags) . pack('v', 0x1234) . pack('v', 0);
        $folder = pack('V', $dataOffset) . pack('v', $count) . pack('v', $typeCompress);

        file_put_contents($path, $header . $folder . $entries . $blocks);
    }
}
