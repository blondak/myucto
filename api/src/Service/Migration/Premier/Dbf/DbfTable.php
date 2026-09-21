<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier\Dbf;

use MyInvoice\Service\Migration\Premier\PremierException;

/**
 * Čtení tabulky Visual FoxPro (`.dbf` + memo `.fpt`), ve kterých PREMIER drží data firmy.
 *
 * Čistě v PHP, bez rozšíření `dbase` (to v Docker image není a neumí typy VFP ani memo
 * `.fpt`). Podporuje typy C, V, N, F, D, L, I, Y, B, T, M a binární pole přeskočí.
 * Smazané záznamy (`*` v prvním bajtu) se nevracejí. Text se převádí z kódové stránky
 * uvedené v hlavičce tabulky (PREMIER používá Windows-1250).
 *
 * Záznamy se čtou postupně po jednom - tabulky záloh mají jednotky MB a celé v paměti
 * být nemusejí.
 */
final class DbfTable
{
    /** Kódová stránka z bajtu 29 hlavičky (language driver) → znaková sada pro iconv. */
    private const CODEPAGES = [
        0x01 => 'CP437', 0x02 => 'CP850', 0x03 => 'CP1252', 0x57 => 'CP1252', 0x58 => 'CP1252',
        0x64 => 'CP852', 0x65 => 'CP866', 0x7D => 'CP1255', 0x7E => 'CP1256',
        0xC8 => 'CP1250', 0xC9 => 'CP1251', 0xCA => 'CP1254', 0xCB => 'CP1253',
    ];

    private const MAX_FIELDS = 1024;

    /** @var list<array{name:string,type:string,length:int,decimals:int,offset:int}> */
    private array $fields = [];

    private int $recordCount;
    private int $headerLength;
    private int $recordLength;
    private string $charset;

    /** @var resource|null */
    private $memo = null;
    private int $memoBlockSize = 64;

    /** @var resource */
    private $handle;

    public function __construct(private readonly string $path)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new PremierException('dbf_unreadable', 'Tabulku ' . basename($path) . ' zálohy PREMIER nejde otevřít.');
        }
        $this->handle = $handle;
        $header = (string) fread($handle, 32);
        if (strlen($header) < 32) {
            throw new PremierException('dbf_invalid', 'Tabulka ' . basename($path) . ' není platná tabulka FoxPro.');
        }
        $this->recordCount = (int) unpack('V', substr($header, 4, 4))[1];
        $this->headerLength = (int) unpack('v', substr($header, 8, 2))[1];
        $this->recordLength = (int) unpack('v', substr($header, 10, 2))[1];
        $this->charset = self::CODEPAGES[ord($header[29])] ?? 'CP1250';

        $offset = 1; // bajt 0 záznamu je příznak smazání
        while (count($this->fields) < self::MAX_FIELDS) {
            $descriptor = (string) fread($handle, 32);
            if ($descriptor === '' || $descriptor[0] === "\r" || strlen($descriptor) < 32) {
                break;
            }
            $length = ord($descriptor[16]);
            $this->fields[] = [
                'name' => strtoupper(rtrim(substr($descriptor, 0, 11), "\0 ")),
                'type' => strtoupper($descriptor[11]),
                'length' => $length,
                'decimals' => ord($descriptor[17]),
                'offset' => $offset,
            ];
            $offset += $length;
        }
        if ($this->recordLength < 1 || $offset > $this->recordLength) {
            throw new PremierException('dbf_invalid', 'Tabulka ' . basename($path) . ' má poškozenou hlavičku.');
        }
        $this->openMemo();
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        if (is_resource($this->memo)) {
            fclose($this->memo);
        }
    }

    public function count(): int
    {
        return $this->recordCount;
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_column($this->fields, 'name');
    }

    public function hasField(string $name): bool
    {
        return in_array(strtoupper($name), $this->fieldNames(), true);
    }

    /**
     * Nesmazané záznamy tabulky. Klíče jsou názvy polí velkými písmeny.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function rows(): \Generator
    {
        for ($i = 0; $i < $this->recordCount; $i++) {
            fseek($this->handle, $this->headerLength + $i * $this->recordLength);
            $raw = self::readExact($this->handle, $this->recordLength);
            if (strlen($raw) < $this->recordLength) {
                return;
            }
            if ($raw[0] === '*') {
                continue;
            }
            $row = [];
            foreach ($this->fields as $f) {
                if ($f['type'] === '0') {
                    continue; // _NullFlags
                }
                $row[$f['name']] = $this->value($f, substr($raw, $f['offset'], $f['length']));
            }
            yield $i => $row;
        }
    }

    /** @param array{name:string,type:string,length:int,decimals:int,offset:int} $f */
    private function value(array $f, string $raw): mixed
    {
        switch ($f['type']) {
            case 'C':
            case 'V':
                return $this->text(rtrim($raw, "\0"));
            case 'N':
            case 'F':
                $t = trim($raw);
                if ($t === '' || !is_numeric($t)) {
                    return null;
                }
                return $f['decimals'] > 0 ? (float) $t : (int) $t;
            case 'D':
                $t = trim($raw);
                return preg_match('/^\d{8}$/', $t) === 1 && $t !== '00000000'
                    ? substr($t, 0, 4) . '-' . substr($t, 4, 2) . '-' . substr($t, 6, 2)
                    : null;
            case 'L':
                return in_array($raw, ['T', 't', 'Y', 'y'], true);
            case 'I':
                return (int) unpack('l', $raw)[1];
            case 'Y':
                return round(((int) unpack('q', $raw)[1]) / 10000, 4);
            case 'B':
                return (float) unpack('e', $raw)[1];
            case 'T':
                return self::dateTime($raw);
            case 'M':
                return $this->memo($raw);
            default:
                return null; // G, W, Q, P - binární data převod nepotřebuje
        }
    }

    private function text(string $value): string
    {
        $value = rtrim($value);
        if ($value === '' || preg_match('/[\x80-\xFF]/', $value) !== 1) {
            return $value;
        }
        $converted = @iconv($this->charset, 'UTF-8//IGNORE', $value);
        return $converted === false ? '' : $converted;
    }

    private static function dateTime(string $raw): ?string
    {
        $julian = (int) unpack('l', substr($raw, 0, 4))[1];
        $ms = (int) unpack('l', substr($raw, 4, 4))[1];
        if ($julian <= 0) {
            return null;
        }
        // Juliánský den → gregoriánské datum (Fliegel & Van Flandern), bez rozšíření calendar.
        $l = $julian + 68569;
        $n = intdiv(4 * $l, 146097);
        $l -= intdiv(146097 * $n + 3, 4);
        $i = intdiv(4000 * ($l + 1), 1461001);
        $l = $l - intdiv(1461 * $i, 4) + 31;
        $j = intdiv(80 * $l, 2447);
        $day = $l - intdiv(2447 * $j, 80);
        $l = intdiv($j, 11);
        $month = $j + 2 - 12 * $l;
        $year = 100 * ($n - 49) + $i + $l;
        $seconds = intdiv(max(0, $ms), 1000);
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, intdiv($seconds, 3600) % 24, intdiv($seconds, 60) % 60, $seconds % 60);
    }

    private function memo(string $raw): ?string
    {
        if ($this->memo === null) {
            return null;
        }
        $block = strlen($raw) === 4 ? (int) unpack('V', $raw)[1] : (int) trim($raw);
        if ($block <= 0) {
            return null;
        }
        fseek($this->memo, $block * $this->memoBlockSize);
        $head = (string) fread($this->memo, 8);
        if (strlen($head) < 8) {
            return null;
        }
        $length = (int) unpack('N', substr($head, 4, 4))[1];
        if ($length <= 0 || $length > 16 * 1024 * 1024) {
            return null;
        }
        $data = self::readExact($this->memo, $length);
        // Typ 1 = text, 0 = obrázek/binární data.
        return unpack('N', substr($head, 0, 4))[1] === 1 ? $this->text($data) : null;
    }

    /**
     * fread() smí vrátit méně bajtů, než je požadováno (stream wrapper, síťový disk) -
     * záznam i memo se čtou do plné délky, jinak by se tiše ořízly.
     *
     * @param resource $handle
     */
    private static function readExact($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($handle, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function openMemo(): void
    {
        $base = substr($this->path, 0, -4);
        foreach (['.fpt', '.FPT', '.Fpt'] as $ext) {
            if (is_file($base . $ext)) {
                $memo = @fopen($base . $ext, 'rb');
                if ($memo === false) {
                    return;
                }
                $head = (string) fread($memo, 8);
                $size = strlen($head) === 8 ? (int) unpack('n', substr($head, 6, 2))[1] : 0;
                $this->memo = $memo;
                $this->memoBlockSize = $size > 0 ? $size : 64;
                return;
            }
        }
    }
}
