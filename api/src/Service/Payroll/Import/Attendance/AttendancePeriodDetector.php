<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Období, ke kterému se podklady podle názvů souborů a listů hlásí.
 *
 * Docházkové systémy i ručně skládané sešity nesou měsíc v názvu
 * („podklady 11-2025", list „Mzdy 11-25", soubor „1125.xlsx",
 * „dochazka-2025-11.csv"). Import podle toho pozná, že účetní omylem
 * nechala ve výběru jiný měsíc: výchozí období je pracovní měsíc mezd,
 * ne měsíc podkladů.
 *
 * Rozpoznávají se jen tvary s oddělovačem nebo samostatné čtyřčíslí MMRR
 * v celém názvu souboru. Čísla uvnitř jiného textu („pam7953") ani data
 * („15.06.") se za období nepovažují.
 */
final class AttendancePeriodDetector
{
    private const SEPARATOR = '[-_./ ]';

    /**
     * @param list<string> $names názvy souborů (s příponou) a listů
     * @return array<string,list<string>> období RRRR-MM → názvy, ve kterých se našlo
     */
    public static function detect(array $names): array
    {
        $found = [];
        foreach ($names as $name) {
            $period = self::fromName($name);
            if ($period !== null && !in_array($name, $found[$period] ?? [], true)) {
                $found[$period][] = $name;
            }
        }
        ksort($found);

        return $found;
    }

    public static function fromName(string $name): ?string
    {
        $text = trim((string) preg_replace('/\.(xlsx|xls|csv)$/iu', '', trim($name)));
        if ($text === '') {
            return null;
        }
        $sep = self::SEPARATOR;
        $patterns = [
            // 2025-11
            "~(?<!\\d)(20\\d{2}){$sep}(0[1-9]|1[0-2])(?!\\d)~u" => static fn (array $m): string => "{$m[1]}-{$m[2]}",
            // 11-2025, 3/2025
            "~(?<!\\d)(0?[1-9]|1[0-2]){$sep}(20\\d{2})(?!\\d)~u" => static fn (array $m): string => sprintf('%s-%02d', $m[2], (int) $m[1]),
            // Mzdy 11-25
            "~(?<!\\d)(0[1-9]|1[0-2]){$sep}(\\d{2})(?![\\d./-])~u" => static fn (array $m): string => "20{$m[2]}-{$m[1]}",
            // 1125 jako celý název
            '~^(0[1-9]|1[0-2])(\d{2})$~u' => static fn (array $m): string => "20{$m[2]}-{$m[1]}",
        ];
        foreach ($patterns as $pattern => $format) {
            if (preg_match($pattern, $text, $match) === 1) {
                return $format($match);
            }
        }

        return null;
    }
}
