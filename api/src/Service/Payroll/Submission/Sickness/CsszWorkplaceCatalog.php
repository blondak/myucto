<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Název pracoviště ČSSZ podle kódu (`CIS_PRACOVIST`), pro HZUPN
 * `dokument/nazevOSSZ` (nejvýš 30 znaků).
 *
 * Název se nikdy nevymýšlí: kód, který v připnutém číselníku není, vrací
 * `null` a prvek se do věty nevypíše (v XSD je nepovinný).
 */
final class CsszWorkplaceCatalog
{
    public const MAX_NAME_LENGTH = 30;

    /** @var array<int,string>|null */
    private static ?array $names = null;

    /** @var array{districts:array<int,string>,not_for_epodani:list<int>}|null */
    private static ?array $epodaniDistricts = null;

    public static function nameFor(int $code): ?string
    {
        $name = self::names()[$code] ?? null;
        if ($name === null) {
            return null;
        }
        $name = trim($name);

        return $name === '' || mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH ? null : $name;
    }

    /**
     * Je kód pracoviště v číselníku okresů pro e-podání (C_COKR)? Kód 101
     * (ústředí) je v číselníku výslovně označený „Nepoužívat pro e-podání",
     * takže tu neprojde. Prázdný (nenačtený) číselník nic nezamítá, ať chybějící
     * soubor nezablokuje podání, která dosud procházela.
     */
    public static function isEpodaniDistrict(int $code): bool
    {
        $catalog = self::epodaniDistricts();
        if ($catalog['districts'] === []) {
            return true;
        }

        return isset($catalog['districts'][$code])
            && !in_array($code, $catalog['not_for_epodani'], true);
    }

    /** @return array{districts:array<int,string>,not_for_epodani:list<int>} */
    private static function epodaniDistricts(): array
    {
        if (self::$epodaniDistricts !== null) {
            return self::$epodaniDistricts;
        }
        $path = dirname(__DIR__, 5) . '/resources/payroll/cssz-workplaces/epodani-districts.json';
        $districts = [];
        $excluded = [];
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (is_array($decoded)) {
            foreach (is_array($decoded['districts'] ?? null) ? $decoded['districts'] : [] as $code => $name) {
                if (is_string($name) && ctype_digit((string) $code)) {
                    $districts[(int) $code] = $name;
                }
            }
            foreach (is_array($decoded['not_for_epodani'] ?? null) ? $decoded['not_for_epodani'] : [] as $code) {
                if (is_int($code)) {
                    $excluded[] = $code;
                }
            }
        }

        return self::$epodaniDistricts = ['districts' => $districts, 'not_for_epodani' => $excluded];
    }

    /** @return array<int,string> */
    private static function names(): array
    {
        if (self::$names !== null) {
            return self::$names;
        }
        $path = dirname(__DIR__, 5) . '/resources/payroll/cssz-workplaces/workplaces.json';
        $names = [];
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (is_array($decoded) && is_array($decoded['workplaces'] ?? null)) {
            foreach ($decoded['workplaces'] as $code => $name) {
                if (is_string($name) && ctype_digit((string) $code)) {
                    $names[(int) $code] = $name;
                }
            }
        }

        return self::$names = $names;
    }
}
