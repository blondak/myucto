<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Premier;

/**
 * Zápis tabulky Visual FoxPro (`.dbf` + memo `.fpt`) pro testy převodu z PREMIER.
 *
 * Hlavička 32 bajtů (verze 0x30, datum, počet záznamů, délka hlavičky a záznamu, kódová
 * stránka 0xC8 = Windows-1250 v bajtu 29), popisy polí po 32 bajtech, terminátor 0x0D
 * a 263 bajtů backlinku VFP. Záznam začíná příznakem smazání (` ` / `*`).
 *
 * Pole: `[název, typ, délka, desetinná místa]`, typy C, N, D, L, I, M. Hodnota `null`
 * = prázdné pole. Řádek s klíčem `_deleted => true` se zapíše jako smazaný.
 */
final class DbfWriter
{
    public const MEMO_BLOCK = 64;

    /**
     * @param list<array{0:string,1:string,2?:int,3?:int}> $fields
     * @param list<array<string,mixed>> $rows
     */
    public static function write(string $path, array $fields, array $rows): void
    {
        $fields = array_map(self::normalizeField(...), $fields);
        $hasMemo = in_array('M', array_column($fields, 'type'), true);
        $memo = new \ArrayObject(['blocks' => '', 'next' => intdiv(512, self::MEMO_BLOCK)]);

        $recordLength = 1 + array_sum(array_column($fields, 'length'));
        $headerLength = 32 + 32 * count($fields) + 1 + 263;

        $header = chr(0x30) . chr(125) . chr(9) . chr(21)
            . pack('V', count($rows))
            . pack('v', $headerLength)
            . pack('v', $recordLength)
            . str_repeat("\0", 16)
            . chr($hasMemo ? 0x02 : 0x00)
            . chr(0xC8)
            . "\0\0";

        $descriptors = '';
        $offset = 1;
        foreach ($fields as $f) {
            $descriptors .= str_pad(substr($f['name'], 0, 10), 11, "\0")
                . $f['type']
                . pack('V', $offset)
                . chr($f['length'])
                . chr($f['decimals'])
                . "\0"
                . str_repeat("\0", 13);
            $offset += $f['length'];
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= !empty($row['_deleted']) ? '*' : ' ';
            foreach ($fields as $f) {
                $body .= self::encode($f, $row[$f['name']] ?? null, $memo);
            }
        }

        file_put_contents($path, $header . $descriptors . "\r" . str_repeat("\0", 263) . $body . "\x1A");

        if ($hasMemo) {
            $fpt = pack('N', $memo['next']) . "\0\0" . pack('n', self::MEMO_BLOCK);
            $fpt = str_pad($fpt, 512, "\0") . $memo['blocks'];
            file_put_contents(substr($path, 0, -4) . '.FPT', $fpt);
        }
    }

    /**
     * @param array{0:string,1:string,2?:int,3?:int} $f
     * @return array{name:string,type:string,length:int,decimals:int}
     */
    private static function normalizeField(array $f): array
    {
        $type = strtoupper($f[1]);
        $length = match ($type) {
            'D' => 8,
            'L' => 1,
            'I', 'M' => 4,
            default => (int) ($f[2] ?? 10),
        };
        return ['name' => strtoupper($f[0]), 'type' => $type, 'length' => $length, 'decimals' => (int) ($f[3] ?? 0)];
    }

    /**
     * @param array{name:string,type:string,length:int,decimals:int} $f
     * @param \ArrayObject<string,mixed> $memo
     */
    private static function encode(array $f, mixed $value, \ArrayObject $memo): string
    {
        $len = $f['length'];
        switch ($f['type']) {
            case 'C':
                $text = $value === null ? '' : self::cp1250((string) $value);
                return str_pad(substr($text, 0, $len), $len, ' ');
            case 'N':
                if ($value === null || $value === '') {
                    return str_repeat(' ', $len);
                }
                $text = $f['decimals'] > 0 ? number_format((float) $value, $f['decimals'], '.', '') : (string) (int) $value;
                if (strlen($text) > $len) {
                    throw new \LengthException("Hodnota {$text} se do pole {$f['name']} ({$len}) nevejde.");
                }
                return str_pad($text, $len, ' ', STR_PAD_LEFT);
            case 'D':
                return $value === null || $value === '' ? str_repeat(' ', 8) : str_replace('-', '', (string) $value);
            case 'L':
                return $value === null ? ' ' : ($value ? 'T' : 'F');
            case 'I':
                return pack('V', (int) ($value ?? 0));
            case 'M':
                if ($value === null || $value === '') {
                    return pack('V', 0);
                }
                $data = self::cp1250((string) $value);
                $block = $memo['next'];
                $chunk = pack('N', 1) . pack('N', strlen($data)) . $data;
                $blocks = (int) ceil(strlen($chunk) / self::MEMO_BLOCK);
                $memo['blocks'] .= str_pad($chunk, $blocks * self::MEMO_BLOCK, "\0");
                $memo['next'] = $block + $blocks;
                return pack('V', $block);
            default:
                throw new \InvalidArgumentException("Typ pole {$f['type']} fixture neumí.");
        }
    }

    private static function cp1250(string $utf8): string
    {
        $out = iconv('UTF-8', 'CP1250//TRANSLIT', $utf8);
        if ($out === false) {
            throw new \RuntimeException('Text nejde převést do CP1250: ' . $utf8);
        }
        return $out;
    }
}
