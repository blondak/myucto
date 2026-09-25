<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Minimální čtečka přímých PDF objektů (ISO 32000-1 § 7.3) pro slovníky, které
 * nesou binární řetězce — typicky `/Encrypt`, kde `/O` a `/U` obsahují libovolné
 * bajty včetně `>>` a závorek, takže regex na ně nestačí.
 *
 * Mapování: slovník → asociativní pole (klíče bez lomítka), pole → list,
 * jméno → řetězec s lomítkem (`/Standard`), řetězec → dekódované bajty,
 * nepřímý odkaz → `['_ref' => 'N G']`. Streamy nečte.
 */
final class PdfObjectReader
{
    private const MAX_DEPTH = 32;

    private int $pos = 0;

    public function __construct(private readonly string $pdf)
    {
    }

    public function readAt(int $offset): mixed
    {
        $this->pos = $offset;
        try {
            return $this->readValue(0);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    private function readValue(int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \UnexpectedValueException('too deep');
        }
        $this->skipWhitespace();
        $c = $this->pdf[$this->pos] ?? '';
        if ($c === '') {
            throw new \UnexpectedValueException('eof');
        }
        if ($c === '<' && ($this->pdf[$this->pos + 1] ?? '') === '<') {
            return $this->readDict($depth);
        }
        if ($c === '<') {
            return $this->readHexString();
        }
        if ($c === '(') {
            return $this->readLiteralString();
        }
        if ($c === '[') {
            return $this->readArray($depth);
        }
        if ($c === '/') {
            return $this->readName();
        }
        if (preg_match('/\G(\d+)\s+(\d+)\s+R\b/', $this->pdf, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            return ['_ref' => $m[1] . ' ' . $m[2]];
        }
        if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $this->pdf, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            return str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0];
        }
        if (preg_match('/\G(true|false|null)\b/', $this->pdf, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            return match ($m[1]) {
                'true'  => true,
                'false' => false,
                default => null,
            };
        }
        throw new \UnexpectedValueException('unexpected token');
    }

    /** @return array<string,mixed> */
    private function readDict(int $depth): array
    {
        $this->pos += 2;
        $out = [];
        while (true) {
            $this->skipWhitespace();
            if (substr($this->pdf, $this->pos, 2) === '>>') {
                $this->pos += 2;
                return $out;
            }
            $key = $this->readValue($depth + 1);
            if (!is_string($key) || !str_starts_with($key, '/')) {
                throw new \UnexpectedValueException('dict key');
            }
            $out[substr($key, 1)] = $this->readValue($depth + 1);
        }
    }

    /** @return list<mixed> */
    private function readArray(int $depth): array
    {
        $this->pos++;
        $out = [];
        while (true) {
            $this->skipWhitespace();
            if (($this->pdf[$this->pos] ?? '') === ']') {
                $this->pos++;
                return $out;
            }
            $out[] = $this->readValue($depth + 1);
        }
    }

    private function readName(): string
    {
        preg_match('#\G/[^\s/<>\[\]()%{}]*#', $this->pdf, $m, 0, $this->pos);
        $this->pos += strlen($m[0]);
        return $m[0];
    }

    private function readHexString(): string
    {
        $end = strpos($this->pdf, '>', $this->pos);
        if ($end === false) {
            throw new \UnexpectedValueException('hex string');
        }
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($this->pdf, $this->pos + 1, $end - $this->pos - 1));
        $this->pos = $end + 1;
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        return (string) hex2bin($hex);
    }

    private function readLiteralString(): string
    {
        $this->pos++;
        $out = '';
        $nesting = 0;
        $len = strlen($this->pdf);
        while ($this->pos < $len) {
            $c = $this->pdf[$this->pos++];
            if ($c === '\\') {
                $n = $this->pdf[$this->pos++] ?? '';
                if ($n >= '0' && $n <= '7') {
                    $oct = $n;
                    while (strlen($oct) < 3 && ($d = $this->pdf[$this->pos] ?? '') >= '0' && $d <= '7') {
                        $oct .= $d;
                        $this->pos++;
                    }
                    $out .= chr(octdec($oct) & 0xFF);
                    continue;
                }
                if ($n === "\r") {
                    if (($this->pdf[$this->pos] ?? '') === "\n") {
                        $this->pos++;
                    }
                    continue;
                }
                $out .= match ($n) {
                    'n'     => "\n",
                    'r'     => "\r",
                    't'     => "\t",
                    'b'     => "\x08",
                    'f'     => "\f",
                    "\n"    => '',
                    default => $n,
                };
                continue;
            }
            if ($c === '(') {
                $nesting++;
            } elseif ($c === ')') {
                if ($nesting === 0) {
                    return $out;
                }
                $nesting--;
            }
            $out .= $c;
        }
        throw new \UnexpectedValueException('literal string');
    }

    private function skipWhitespace(): void
    {
        $len = strlen($this->pdf);
        while ($this->pos < $len) {
            $c = $this->pdf[$this->pos];
            if ($c === '%') {
                while ($this->pos < $len && $this->pdf[$this->pos] !== "\n" && $this->pdf[$this->pos] !== "\r") {
                    $this->pos++;
                }
                continue;
            }
            if (!str_contains(" \t\r\n\f\0", $c)) {
                return;
            }
            $this->pos++;
        }
    }
}
