<?php

declare(strict_types=1);

/**
 * Čtečka tabulky Visual FoxPro (.dbf + .fpt) pro výpis podání ze zálohy PREMIER.
 *
 * Na rozdíl od api/src/Service/Migration/Premier/Dbf/DbfTable vrací memo pole vždy jako
 * surové bajty (včetně binárních) a umí je přečíst bez ohledu na typ bloku. Texty (C, M) se
 * převádějí z kódové stránky hlavičky tabulky (PREMIER používá Windows-1250). Smazané záznamy
 * se nevracejí. Žádná data v kódu, jen formát.
 */
final class PremierRawDbf
{
    /** @var list<array{name:string,type:string,length:int,decimals:int,offset:int}> */
    private array $fields = [];
    private int $recordCount;
    private int $headerLength;
    private int $recordLength;
    private string $charset = 'CP1250';
    /** @var resource */
    private $handle;
    /** @var resource|null */
    private $memo = null;
    private int $memoBlock = 64;

    public function __construct(public readonly string $path)
    {
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException('Nelze otevřít ' . basename($path));
        }
        $this->handle = $h;
        $head = (string) fread($h, 32);
        $this->recordCount = (int) unpack('V', substr($head, 4, 4))[1];
        $this->headerLength = (int) unpack('v', substr($head, 8, 2))[1];
        $this->recordLength = (int) unpack('v', substr($head, 10, 2))[1];
        $offset = 1;
        while (true) {
            $d = (string) fread($h, 32);
            if (strlen($d) < 32 || $d[0] === "\r") {
                break;
            }
            $len = ord($d[16]);
            $this->fields[] = [
                'name' => strtoupper(rtrim(substr($d, 0, 11), "\0 ")),
                'type' => strtoupper($d[11]),
                'length' => $len,
                'decimals' => ord($d[17]),
                'offset' => $offset,
            ];
            $offset += $len;
        }
        $base = substr($path, 0, -4);
        foreach (['.fpt', '.FPT', '.Fpt'] as $ext) {
            if (is_file($base . $ext)) {
                $m = fopen($base . $ext, 'rb');
                if ($m !== false) {
                    $mh = (string) fread($m, 8);
                    $size = strlen($mh) === 8 ? (int) unpack('n', substr($mh, 6, 2))[1] : 0;
                    $this->memo = $m;
                    $this->memoBlock = $size > 0 ? $size : 64;
                }
                break;
            }
        }
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

    /** @return list<array{name:string,type:string,length:int,decimals:int,offset:int}> */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @param bool $rawMemo true = memo pole jako surové bajty (string), false = převedené do UTF-8
     * @return \Generator<int,array<string,mixed>> klíč = pořadí záznamu v tabulce (0-based)
     */
    public function rows(bool $rawMemo = false): \Generator
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
                    continue;
                }
                $row[$f['name']] = $this->value($f, substr($raw, $f['offset'], $f['length']), $rawMemo);
            }
            yield $i => $row;
        }
    }

    public function toUtf8(string $value): string
    {
        if ($value === '' || preg_match('/[\x80-\xFF]/', $value) !== 1) {
            return $value;
        }
        $c = @iconv($this->charset, 'UTF-8//IGNORE', $value);
        return $c === false ? '' : $c;
    }

    /** @param array{name:string,type:string,length:int,decimals:int,offset:int} $f */
    private function value(array $f, string $raw, bool $rawMemo): mixed
    {
        switch ($f['type']) {
            case 'C':
            case 'V':
                return $this->toUtf8(rtrim(rtrim($raw, "\0"), ' '));
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
                $bytes = $this->memoBytes($raw);
                if ($bytes === null) {
                    return null;
                }
                return $rawMemo ? $bytes : $this->toUtf8($bytes);
            default:
                return null;
        }
    }

    private function memoBytes(string $raw): ?string
    {
        if ($this->memo === null) {
            return null;
        }
        $block = strlen($raw) === 4 ? (int) unpack('V', $raw)[1] : (int) trim($raw);
        if ($block <= 0) {
            return null;
        }
        fseek($this->memo, $block * $this->memoBlock);
        $head = (string) fread($this->memo, 8);
        if (strlen($head) < 8) {
            return null;
        }
        $length = (int) unpack('N', substr($head, 4, 4))[1];
        if ($length <= 0 || $length > 64 * 1024 * 1024) {
            return null;
        }
        return self::readExact($this->memo, $length);
    }

    private static function dateTime(string $raw): ?string
    {
        $julian = (int) unpack('l', substr($raw, 0, 4))[1];
        $ms = (int) unpack('l', substr($raw, 4, 4))[1];
        if ($julian <= 0) {
            return null;
        }
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
        $s = intdiv(max(0, $ms), 1000);
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, intdiv($s, 3600) % 24, intdiv($s, 60) % 60, $s % 60);
    }

    /** @param resource $h */
    private static function readExact($h, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($h, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }
}
