<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverInstitutionWriter;

/**
 * Příjemci odvodů ze zálohy PREMIER v podobě, kterou přijímá společný zápis účtů institucí
 * ({@see PayrollTakeoverInstitutionWriter::institutionAccounts()}). Nic nezapisuje.
 *
 *  - Zdravotní pojišťovny: číselník `POJIST` s `CO_POJIS` = 1 (kód `ZKRATKA_PO`, název,
 *    účet `UCET_PO` a banka `KOD_PO`, variabilní symbol plátce `VAR1`, datová schránka
 *    `ADR_DS`). Je to registr pojišťoven v programu, stejně jako registr PAMICA. Penzijní
 *    fondy (`CO_POJIS` = 2) příjemci odvodů nejsou.
 *  - ČSSZ a finanční úřad: nastavení mezd `SET_GLOB` (`amz_ucetN`, banka `amz_kodN`,
 *    variabilní symbol `amz_varN`). Příjemce se pozná podle předčíslí účtu u ČNB
 *    ({@see PayrollTakeoverInstitutionWriter::LEVY_ACCOUNTS}); účet ČSSZ s jiným
 *    předčíslím podle pozice 3.
 *
 * Význam pozic `amz_ucet1..3` je odvozený z dat, ne z dokumentace (agregovaně na reálné
 * záloze): 1 má předčíslí 713 (záloha na daň ze závislé činnosti) a platby 342 z deníku
 * jdou na něj, 2 má předčíslí 7720 (daň vybíraná srážkou) a platby srážkové daně jdou na
 * něj, 3 má předčíslí 21012 a platí se na něj pojistné 336 (ČSSZ); všechny s bankou 0710.
 * Variabilní symbol na pozici 3 má deset číslic (variabilní symbol zaměstnavatele u ČSSZ),
 * na ostatních pozicích je to IČO. Jistota vysoká u finančního úřadu (předčíslí i deník),
 * u ČSSZ vysoká pro tuto zálohu (deník), obecně střední.
 *
 * Účty z nastavení mezd nejsou sdělení úřadu: zápis je zakládá jako převzaté
 * (`imported`) a účetní je musí porovnat s rozhodnutím.
 */
final class PremierPayrollInstitutions
{
    /** Pozice účtu ČSSZ v nastavení mezd PREMIER (viz hlavička). */
    private const SOCIAL_SECURITY_SLOT = 3;

    /** @return list<array<string,mixed>> */
    public static function read(PremierBackup $backup): array
    {
        $out = [];
        foreach ($backup->rows('POJIST') as $row) {
            $code = self::text($row['ZKRATKA_PO'] ?? '');
            $account = (string) preg_replace('/\s+/', '', self::text($row['UCET_PO'] ?? ''));
            $bankCode = self::text($row['KOD_PO'] ?? '');
            if ((int) ($row['CO_POJIS'] ?? 0) !== 1 || preg_match('/^[0-9]{3}$/D', $code) !== 1 || $code === '999'
                || preg_match('/^(?:[0-9]{1,6}-)?[0-9]{2,10}$/D', $account) !== 1 || preg_match('/^[0-9]{4}$/D', $bankCode) !== 1) {
                continue;
            }
            $variable = (string) preg_replace('/\D/', '', self::text($row['VAR1'] ?? ''));
            $dataBox = self::text($row['ADR_DS'] ?? '');
            $out[] = [
                'type' => 'health_insurer',
                'code' => $code,
                'name' => mb_substr(self::text($row['NAZEV_PO'] ?? ''), 0, 190) ?: null,
                'account' => $account,
                'bank_code' => $bankCode,
                'variable_symbol' => $variable !== '' ? mb_substr($variable, 0, 10) : null,
                'data_box' => $dataBox !== '' ? mb_substr($dataBox, 0, 20) : null,
                'source' => 'POJIST',
                'issue' => null,
                'candidates' => 1,
            ];
        }
        return [...$out, ...self::levies($backup)];
    }

    /**
     * ČSSZ a finanční úřad z nastavení mezd. Vrací vždy všechny tři příjemce, i bez účtu,
     * aby protokol řekl, co účetní chybí.
     *
     * @return list<array<string,mixed>>
     */
    private static function levies(PremierBackup $backup): array
    {
        $settings = [];
        foreach ($backup->rows('SET_GLOB') as $row) {
            $key = strtolower(self::text($row['PROMEN'] ?? ''));
            if (preg_match('/^amz_(ucet|kod|var)([0-9])$/D', $key, $match) === 1) {
                $settings[(int) $match[2]][$match[1]] = self::text($row['C_SET'] ?? '');
            }
        }
        $found = [];
        foreach ($settings as $slot => $values) {
            $account = (string) preg_replace('/\s+/', '', $values['ucet'] ?? '');
            $bankCode = $values['kod'] ?? '';
            if ($bankCode !== PayrollTakeoverInstitutionWriter::CNB_BANK_CODE
                || preg_match('/^([0-9]{1,6})-[0-9]{2,10}$/D', $account, $match) !== 1) {
                continue;
            }
            $prefix = isset(PayrollTakeoverInstitutionWriter::LEVY_ACCOUNTS[$match[1]]) ? $match[1] : null;
            if ($prefix === null && $slot === self::SOCIAL_SECURITY_SLOT) {
                $prefix = (string) array_search('social_security', array_map(static fn (array $l): string => $l[0], PayrollTakeoverInstitutionWriter::LEVY_ACCOUNTS), true);
            }
            if ($prefix === null || $prefix === '') {
                continue;
            }
            $variable = (string) preg_replace('/\D/', '', $values['var'] ?? '');
            $found[$prefix][] = ['account' => $account, 'variable' => $variable !== '' && strlen($variable) <= 10 ? $variable : null, 'slot' => $slot];
        }
        $out = [];
        foreach (PayrollTakeoverInstitutionWriter::LEVY_ACCOUNTS as $prefix => [$type, $code, $name]) {
            $hits = $found[(string) $prefix] ?? [];
            $accounts = array_values(array_unique(array_column($hits, 'account')));
            $issue = match (true) {
                $accounts === [] => 'missing',
                count($accounts) > 1 => 'ambiguous',
                default => null,
            };
            $out[] = [
                'type' => $type,
                'code' => $code,
                'name' => $name,
                'account' => $issue === null ? $accounts[0] : null,
                'bank_code' => $issue === null ? PayrollTakeoverInstitutionWriter::CNB_BANK_CODE : null,
                'variable_symbol' => $issue === null ? ($hits[0]['variable'] ?? null) : null,
                'data_box' => null,
                'source' => $issue === null ? 'nastavení mezd PREMIER (SET_GLOB amz_ucet' . $hits[0]['slot'] . ')' : null,
                'issue' => $issue,
                'candidates' => count($accounts),
            ];
        }
        return $out;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
