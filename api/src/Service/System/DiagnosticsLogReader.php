<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Výřez aplikačního logu pro diagnostický balíček.
 *
 * Čte jen `app-YYYY-MM-DD.log` (Monolog RotatingFileHandler). `php-errors.log`
 * se záměrně NEČTE vůbec: nerotuje, bývá v řádu desítek MB, z velké části je to
 * šum vendoru a nese stack trace s argumenty funkcí.
 *
 * Před vydáním se z každého záznamu odstraní:
 *   - `params` z kontextu DB chyb (obsahují navázané hodnoty, tj. obsah řádků),
 *   - všechno za `Trace:` (argumenty funkcí ve stack trace),
 *   - celé záznamy `mail.smtp_transcript` (obsahují `AUTH` v base64).
 *
 * Zbytek se NEUPRAVUJE. Jména, adresy ani volný text v logu spolehlivě rozpoznat
 * nejde, takže se o to nepokoušíme a uživatel dostane obsah k prohlédnutí.
 */
final class DiagnosticsLogReader
{
    /** Monolog úrovně od nejnižší; index = váha. */
    private const LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** Začátek nového záznamu: `[2026-08-14T13:54:10.620525+02:00] myinvoice.INFO: …` */
    private const RECORD_RE = '/^\[(\d{4}-\d{2}-\d{2}T[^\]]+)\]\s+([A-Za-z0-9_.-]+)\.([A-Z]+):\s?(.*)$/s';

    /** Záznamy, které se do výřezu nedostanou nikdy — nesou tajemství. */
    private const DROP_PREFIXES = ['mail.smtp_transcript'];

    public const MAX_DAYS      = 14;
    public const DEFAULT_DAYS  = 7;
    public const DEFAULT_LEVEL = 'WARNING';

    /** Strop na jeden den, aby jeden zacyklený proces neutopil celý balíček. */
    private const MAX_BYTES_PER_DAY = 4 * 1024 * 1024;

    /** @return list<string> dostupné dny (YYYY-MM-DD), od nejnovějšího */
    public function availableDays(): array
    {
        $days = [];
        foreach (glob(RuntimePaths::log() . DIRECTORY_SEPARATOR . 'app-*.log') ?: [] as $file) {
            if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m) === 1) {
                $days[] = $m[1];
            }
        }
        rsort($days, SORT_STRING);

        return $days;
    }

    /**
     * Dny spadající do okna posledních `$days` dnů, od nejnovějšího.
     *
     * @return list<string>
     */
    public function daysInWindow(int $days): array
    {
        $days   = max(1, min(self::MAX_DAYS, $days));
        $oldest = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        return array_values(array_filter($this->availableDays(), static fn (string $d) => $d >= $oldest));
    }

    /**
     * Sanitizovaný obsah jednoho dne.
     *
     * @return array{day:string,lines:list<string>,total:int,dropped:int,truncated:bool}
     */
    public function readDay(string $day, string $minLevel = self::DEFAULT_LEVEL): array
    {
        $empty = ['day' => $day, 'lines' => [], 'total' => 0, 'dropped' => 0, 'truncated' => false];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return $empty;
        }
        $path = RuntimePaths::log() . DIRECTORY_SEPARATOR . 'app-' . $day . '.log';
        if (!is_file($path)) {
            return $empty;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $empty;
        }

        $threshold = self::levelWeight($minLevel);
        $lines     = [];
        $dropped   = 0;
        $bytes     = 0;
        $truncated = false;

        /** @var list<string> $buffer aktuální (víceřádkový) záznam */
        $buffer   = [];
        $keep     = false;
        $seenAny  = false;

        $flush = function () use (&$buffer, &$keep, &$lines, &$bytes, &$truncated): void {
            if ($buffer === [] || !$keep) {
                $buffer = [];
                return;
            }
            $record = self::sanitize(implode("\n", $buffer));
            $bytes += strlen($record) + 1;
            if ($bytes > self::MAX_BYTES_PER_DAY) {
                $truncated = true;
            } else {
                $lines[] = $record;
            }
            $buffer = [];
        };

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if (preg_match(self::RECORD_RE, $line, $m) === 1) {
                $flush();
                $seenAny = true;
                $level   = $m[3];
                $message = $m[4];
                $keep    = self::levelWeight($level) >= $threshold && !self::isDropped($message);
                if (!$keep) {
                    $dropped++;
                } else {
                    $buffer[] = $line;
                }
                continue;
            }
            // Pokračovací řádek patří k předchozímu záznamu; text před prvním
            // záznamem (např. ořezaný začátek souboru) zahodíme.
            if ($seenAny && $keep) {
                $buffer[] = $line;
            }
        }
        $flush();
        fclose($handle);

        return [
            'day'       => $day,
            'lines'     => $lines,
            'total'     => count($lines),
            'dropped'   => $dropped,
            'truncated' => $truncated,
        ];
    }

    /**
     * Stránkovaný náhled pro UI — uživatel musí mít reálnou možnost si obsah
     * prohlédnout, ne jen odklikat varování.
     *
     * @return array{day:string,page:int,per_page:int,total:int,lines:list<string>,truncated:bool}
     */
    public function preview(string $day, string $minLevel = self::DEFAULT_LEVEL, int $page = 1, int $perPage = 100): array
    {
        $perPage = max(10, min(500, $perPage));
        $page    = max(1, $page);
        $data    = $this->readDay($day, $minLevel);
        $slice   = array_slice($data['lines'], ($page - 1) * $perPage, $perPage);

        return [
            'day'       => $day,
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $data['total'],
            'lines'     => array_values($slice),
            'truncated' => $data['truncated'],
        ];
    }

    /** Odhad velikosti výřezu bez jeho sestavení do paměti (pro náhled balíčku). */
    public function estimateBytes(int $days, string $minLevel = self::DEFAULT_LEVEL): int
    {
        $total = 0;
        foreach ($this->daysInWindow($days) as $day) {
            foreach ($this->readDay($day, $minLevel)['lines'] as $line) {
                $total += strlen($line) + 1;
            }
        }

        return $total;
    }

    // ── Sanitizace ───────────────────────────────────────────────────────────

    private static function isDropped(string $message): bool
    {
        foreach (self::DROP_PREFIXES as $prefix) {
            if (str_starts_with($message, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Odstraní navázané parametry a stack trace. Veřejná kvůli testu — je to
     * bezpečnostní hranice balíčku, ne detail implementace.
     */
    public static function sanitize(string $record): string
    {
        $record = self::stripParams($record);

        $pos = strpos($record, ' Trace:');
        if ($pos !== false) {
            $record = substr($record, 0, $pos) . ' Trace: <odstraněno>';
        }

        return $record;
    }

    /**
     * Nahradí hodnotu klíče `"params":` značkou. Regex by tu selhal — pole může
     * obsahovat vnořené struktury i uvozovky, takže se hodnota scanuje po
     * znacích s ohledem na řetězce a escapování.
     */
    private static function stripParams(string $record): string
    {
        $needle = '"params":';
        $out    = '';
        $offset = 0;

        while (($pos = strpos($record, $needle, $offset)) !== false) {
            $valueStart = $pos + strlen($needle);
            $end        = self::endOfJsonValue($record, $valueStart);
            if ($end === null) {
                break;
            }
            $out   .= substr($record, $offset, $pos - $offset) . $needle . '"<odstraněno>"';
            $offset = $end;
        }

        return $out . substr($record, $offset);
    }

    /** Index prvního znaku ZA hodnotou JSON začínající na `$start`, nebo null. */
    private static function endOfJsonValue(string $s, int $start): ?int
    {
        $len = strlen($s);
        $i   = $start;
        while ($i < $len && ($s[$i] === ' ' || $s[$i] === "\t")) {
            $i++;
        }
        if ($i >= $len) {
            return null;
        }

        $char = $s[$i];
        if ($char === '[' || $char === '{') {
            $close  = $char === '[' ? ']' : '}';
            $depth  = 0;
            $inStr  = false;
            $escape = false;
            for (; $i < $len; $i++) {
                $c = $s[$i];
                if ($inStr) {
                    if ($escape) {
                        $escape = false;
                    } elseif ($c === '\\') {
                        $escape = true;
                    } elseif ($c === '"') {
                        $inStr = false;
                    }
                    continue;
                }
                if ($c === '"') {
                    $inStr = true;
                } elseif ($c === $char) {
                    $depth++;
                } elseif ($c === $close) {
                    $depth--;
                    if ($depth === 0) {
                        return $i + 1;
                    }
                }
            }

            return null;
        }

        if ($char === '"') {
            $escape = false;
            for ($i++; $i < $len; $i++) {
                $c = $s[$i];
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    return $i + 1;
                }
            }

            return null;
        }

        // Skalár (číslo, true/false/null) — končí čárkou nebo koncem objektu.
        for (; $i < $len; $i++) {
            if ($s[$i] === ',' || $s[$i] === '}' || $s[$i] === ']') {
                return $i;
            }
        }

        return null;
    }

    public static function levelWeight(string $level): int
    {
        $index = array_search(strtoupper($level), self::LEVELS, true);

        return $index === false ? 0 : (int) $index;
    }

    /** @return list<string> */
    public static function levels(): array
    {
        return self::LEVELS;
    }
}
