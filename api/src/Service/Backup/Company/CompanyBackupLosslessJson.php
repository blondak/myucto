<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Přepisuje jen vybrané JSON integer tokeny; ostatní bajty ponechává beze změny. */
final class CompanyBackupLosslessJson
{
    public const DEFAULT_MAX_BYTES = 8_388_608;
    private const MAX_DEPTH = 64;

    private int $position = 0;
    private int $copiedUntil = 0;
    private string $output = '';
    private readonly int $length;

    /** @param callable(list<int|string>,string):mixed $mapper */
    private function __construct(private readonly string $json, private readonly mixed $mapper)
    {
        $this->length = strlen($json);
    }

    /**
     * Volá mapper pro každý skalární token; null znamená žádnou změnu. Náhrada smí
     * být kladné int, nebo Defer pro dočasné JSON null při prvním průchodu obnovy,
     * a to jen pro kladný integer token původního JSON.
     *
     * @param callable(list<int|string>,string):mixed $mapper Vrací jen int, Defer nebo null.
     */
    public static function rewriteIntegerTokens(
        string $json,
        callable $mapper,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): string {
        if ($maxBytes < 1 || strlen($json) > $maxBytes) {
            throw new CompanyBackupPreflightException('lossless_json_limit_exceeded');
        }
        $parser = new self($json, $mapper);
        $parser->whitespace();
        $parser->value([], 0);
        $parser->whitespace();
        if ($parser->position !== $parser->length) {
            throw self::invalid();
        }
        return $parser->output . substr($json, $parser->copiedUntil);
    }

    /** @param list<int|string> $path */
    private function value(array $path, int $depth): void
    {
        $char = $this->json[$this->position] ?? null;
        if ($char === '{') {
            $this->containerDepth($depth);
            $this->object($path, $depth + 1);
            return;
        }
        if ($char === '[') {
            $this->containerDepth($depth);
            $this->array($path, $depth + 1);
            return;
        }

        $start = $this->position;
        $integer = false;
        if ($char === '"') {
            $this->string();
        } elseif ($char === '-' || ($char !== null && $char >= '0' && $char <= '9')) {
            $integer = $this->number();
        } elseif ($char === 't') {
            $this->literal('true');
        } elseif ($char === 'f') {
            $this->literal('false');
        } elseif ($char === 'n') {
            $this->literal('null');
        } else {
            throw self::invalid();
        }
        $token = substr($this->json, $start, $this->position - $start);
        $replacement = ($this->mapper)($path, $token);
        if ($replacement === null) {
            return;
        }
        if (!$integer
            || filter_var($token, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new CompanyBackupPreflightException('lossless_json_replacement_invalid');
        }
        if ($replacement === CompanyBackupReferenceRemapDirective::Defer) {
            $replacementToken = 'null';
        } elseif (is_int($replacement) && $replacement > 0) {
            $replacementToken = (string) $replacement;
        } else {
            throw new CompanyBackupPreflightException('lossless_json_replacement_invalid');
        }
        $this->output .= substr($this->json, $this->copiedUntil, $start - $this->copiedUntil)
            . $replacementToken;
        $this->copiedUntil = $this->position;
    }

    /** @param list<int|string> $path */
    private function object(array $path, int $depth): void
    {
        $this->position++;
        $this->whitespace();
        if (($this->json[$this->position] ?? null) === '}') {
            $this->position++;
            return;
        }
        $seen = [];
        while (true) {
            if (($this->json[$this->position] ?? null) !== '"') {
                throw self::invalid();
            }
            $start = $this->position;
            $this->string();
            $key = $this->decodedString(substr($this->json, $start, $this->position - $start));
            if (array_key_exists($key, $seen)) {
                throw self::invalid();
            }
            $seen[$key] = true;
            $this->whitespace();
            $this->expect(':');
            $this->whitespace();
            $this->value([...$path, $key], $depth);
            $this->whitespace();
            if (($this->json[$this->position] ?? null) === '}') {
                $this->position++;
                return;
            }
            $this->expect(',');
            $this->whitespace();
        }
    }

    /** @param list<int|string> $path */
    private function array(array $path, int $depth): void
    {
        $this->position++;
        $this->whitespace();
        if (($this->json[$this->position] ?? null) === ']') {
            $this->position++;
            return;
        }
        $index = 0;
        while (true) {
            $this->value([...$path, $index++], $depth);
            $this->whitespace();
            if (($this->json[$this->position] ?? null) === ']') {
                $this->position++;
                return;
            }
            $this->expect(',');
            $this->whitespace();
        }
    }

    private function string(): void
    {
        $start = $this->position++;
        while ($this->position < $this->length) {
            $char = $this->json[$this->position++];
            if ($char === '"') {
                $this->decodedString(substr($this->json, $start, $this->position - $start));
                return;
            }
            if (ord($char) < 0x20) {
                throw self::invalid();
            }
            if ($char !== '\\') {
                continue;
            }
            if ($this->position >= $this->length) {
                throw self::invalid();
            }
            $escape = $this->json[$this->position++];
            if ($escape === 'u') {
                $hex = substr($this->json, $this->position, 4);
                if (strlen($hex) !== 4 || !ctype_xdigit($hex)) {
                    throw self::invalid();
                }
                $this->position += 4;
            } elseif (!str_contains('"\\/bfnrt', $escape)) {
                throw self::invalid();
            }
        }
        throw self::invalid();
    }

    private function decodedString(string $token): string
    {
        try {
            $value = json_decode($token, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid();
        }
        if (!is_string($value)) {
            throw self::invalid();
        }
        return $value;
    }

    private function number(): bool
    {
        if (($this->json[$this->position] ?? null) === '-') {
            $this->position++;
        }
        if (($this->json[$this->position] ?? null) === '0') {
            $this->position++;
        } else {
            $this->digits(nonzeroFirst: true);
        }
        $integer = true;
        if (($this->json[$this->position] ?? null) === '.') {
            $integer = false;
            $this->position++;
            $this->digits();
        }
        if (in_array($this->json[$this->position] ?? null, ['e', 'E'], true)) {
            $integer = false;
            $this->position++;
            if (in_array($this->json[$this->position] ?? null, ['+', '-'], true)) {
                $this->position++;
            }
            $this->digits();
        }
        return $integer;
    }

    private function digits(bool $nonzeroFirst = false): void
    {
        $first = $this->json[$this->position] ?? null;
        if ($first === null || $first < ($nonzeroFirst ? '1' : '0') || $first > '9') {
            throw self::invalid();
        }
        do {
            $this->position++;
            $char = $this->json[$this->position] ?? null;
        } while ($char !== null && $char >= '0' && $char <= '9');
    }

    private function literal(string $literal): void
    {
        if (substr($this->json, $this->position, strlen($literal)) !== $literal) {
            throw self::invalid();
        }
        $this->position += strlen($literal);
    }

    private function expect(string $char): void
    {
        if (($this->json[$this->position] ?? null) !== $char) {
            throw self::invalid();
        }
        $this->position++;
    }

    private function whitespace(): void
    {
        while (isset($this->json[$this->position])
            && str_contains(" \t\r\n", $this->json[$this->position])) {
            $this->position++;
        }
    }

    private function containerDepth(int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new CompanyBackupPreflightException('lossless_json_limit_exceeded');
        }
    }

    private static function invalid(): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException('lossless_json_invalid');
    }
}
