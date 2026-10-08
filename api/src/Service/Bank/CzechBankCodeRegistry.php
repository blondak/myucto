<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Číselník kódů platebního styku v ČR (kódy bank) podle České národní banky.
 *
 * Jediný zdroj pravdy pro backend: soubor `api/resources/ciselniky/kody_bank_CR.csv`
 * je beze změny převzatý registr ČNB
 * (https://www.cnb.cz/cs/platebni-styk/.galleries/ucty_kody_bank/download/kody_bank_CR.csv,
 * sloupce kód; poskytovatel; BIC; CERTIS). Aktualizuje ho
 * `cmd/download-bank-codes.{sh,cmd}`. Frontendový `web/src/utils/czBankCodes.ts`
 * drží navíc zaniklé kódy kvůli názvům u starých účtů; že obsahuje všechny
 * platné kódy odsud, hlídá jeho vitest.
 *
 * ## Proč ČNB a ne číselník ČSSZ C_KODBANKY
 *
 * DV NEMPRI25 chce u `platebniSpojeni/ucetCZ/bankaKod` hodnotu z číselníku
 * C_KODBANKY (chyba DIS 06). ČSSZ ho zveřejňuje jako kopii registru ČNB, ale
 * zveřejněná verze má platnost od 1. 9. 2019: chybí v ní banky založené později
 * (Partners Banka 6363) a jsou v ní zaniklé (eBanka 2400, Equa bank 6100).
 * Dávka poukázaná na kód zaniklé banky se nevyplatí, a tak se kontroluje proti
 * aktuálnímu registru ČNB, ze kterého ČSSZ číselník přebírá.
 */
final class CzechBankCodeRegistry
{
    public const RESOURCE = __DIR__ . '/../../../resources/ciselniky/kody_bank_CR.csv';

    public const SOURCE_URL = 'https://www.cnb.cz/cs/platebni-styk/.galleries/ucty_kody_bank/download/kody_bank_CR.csv';

    /** První sloupec hlavičky registru ČNB (bez BOM). */
    public const HEADER = 'Kód platebního styku';

    /** @var array<string,array{name:string,bic:?string}>|null */
    private static ?array $codes = null;

    /** Je kód platným kódem platebního styku podle aktuálního registru ČNB? */
    public static function isValid(string $code): bool
    {
        return preg_match('/^\d{4}$/D', $code) === 1 && isset(self::codes()[$code]);
    }

    public static function name(string $code): ?string
    {
        return self::codes()[$code]['name'] ?? null;
    }

    /** @return array<string,array{name:string,bic:?string}> kód => poskytovatel */
    public static function codes(): array
    {
        if (self::$codes === null) {
            $content = @file_get_contents(self::RESOURCE);
            if ($content === false) {
                throw new \RuntimeException('Číselník kódů bank ČNB chybí: ' . self::RESOURCE);
            }
            self::$codes = self::parse($content);
        }

        return self::$codes;
    }

    /**
     * Registr ČNB tak, jak ho banka publikuje: UTF-8 s BOM, středníky, CRLF.
     *
     * @return array<string,array{name:string,bic:?string}>
     */
    public static function parse(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $header = str_getcsv((string) array_shift($lines), ';', '"', '');
        if (trim((string) ($header[0] ?? '')) !== self::HEADER) {
            throw new \RuntimeException('Číselník kódů bank nemá hlavičku registru ČNB.');
        }
        $codes = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $columns = str_getcsv($line, ';', '"', '');
            $code = trim((string) ($columns[0] ?? ''));
            $name = trim((string) ($columns[1] ?? ''));
            if (preg_match('/^\d{4}$/D', $code) !== 1 || $name === '') {
                throw new \RuntimeException('Neplatný řádek číselníku kódů bank: ' . mb_substr($line, 0, 60));
            }
            $bic = trim((string) ($columns[2] ?? ''));
            $codes[$code] = ['name' => $name, 'bic' => $bic === '' ? null : $bic];
        }
        if (count($codes) < 20) {
            throw new \RuntimeException('Číselník kódů bank má podezřele málo řádků (' . count($codes) . ').');
        }
        ksort($codes, SORT_STRING);

        return $codes;
    }
}
