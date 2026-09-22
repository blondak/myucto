<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Migration\MoneyS3\AccountCode;

/**
 * Účetní deník PREMIER (`PUB_UCTO`) přečtený do podoby, se kterou pracují kroky převodu.
 *
 * Řádek deníku je jedna částka s účtem MD a Dal (`MD`, `DAL` - šestimístně bez tečky,
 * `518100`), datem zápisu `DATUM` a dokladem `DOKLAD` (řada) + `CISLO`. Řádky faktur
 * nesou vazbu na hlavičku ve sborníku: `SB_KOD` (`VF`, `PF`…) a `SBORNIK` = `INTER`
 * faktury v `FA_OUT` / `FA_IN`. Typ řádku faktury `IKOD`: `P` základ, `D` daň, `O`
 * zaokrouhlení. Kód DPH (`KOD_DPH`) nese řádek základu i daně.
 *
 * Deník PREMIER **nevede počáteční ani uzávěrkové zápisy** - stavy mezi roky přenáší
 * program sám. Počáteční stavy roku proto převod dopočte ze zápisů všech předchozích
 * let v záloze ({@see openingBalances()}). Zápisy na 701 (firma převedená do PREMIER
 * uprostřed života) se berou jako počáteční stavy, zápisy na 702 a 710 se přeskakují -
 * rok uzavírá průvodce uzávěrkou MyÚčta.
 */
final class PremierJournal
{
    public const OPENING_ACCOUNT = '701';
    private const CLOSING_ACCOUNTS = ['702', '710'];

    /** @var list<array<string,mixed>> všechny řádky deníku s účinkem, podle data */
    private array $rows = [];

    /** @var array<int,array<string,mixed>> INTER → řádek */
    private array $byInter = [];

    /** @var array<string,list<array<string,mixed>>> „SB_KOD|SBORNIK" → řádky dokladu ve sborníku */
    private array $byDocument = [];

    /** @var array<string,array{account:string,number:string,bank:string,iban:string,currency:string,label:string}> bankovní řady deníku */
    private array $bankSeries = [];

    /** @var array<int,list<string>> INTER řádku deníku → faktury, které hradí („směr|INTER faktury") */
    private array $paymentTargets = [];

    public function __construct(PremierBackup $backup)
    {
        $this->load($backup->rows('PUB_UCTO'));
        $this->loadBankSeries($backup->rows('DOKL_PU'));
    }

    /**
     * @param iterable<array<string,mixed>> $rows řádky ve tvaru `PUB_UCTO` (testy)
     * @param iterable<array<string,mixed>> $seriesRows číselník řad `DOKL_PU`
     */
    public static function fromRows(iterable $rows, iterable $seriesRows = []): self
    {
        $self = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $self->load($rows);
        $self->loadBankSeries($seriesRows);
        return $self;
    }

    /**
     * Měna účtu podle deníku: cizí měna, ve které je vedená většina řádků na účtu (s částkou
     * v měně), jinak `CZK`. Pro peněžní účty, u kterých číselník řad měnu neuvádí.
     */
    public function accountCurrency(string $account): string
    {
        $votes = ['CZK' => 0];
        foreach ($this->rows as $r) {
            if ($r['md'] !== $account && $r['dal'] !== $account) {
                continue;
            }
            $foreign = $r['currency'] !== '' && $r['currency'] !== 'CZK' && abs($r['amount_foreign']) >= 0.005;
            $key = $foreign ? $r['currency'] : 'CZK';
            $votes[$key] = ($votes[$key] ?? 0) + 1;
        }
        arsort($votes);
        return (string) array_key_first($votes);
    }

    /**
     * Řádky deníku navázané na doklad sborníku (faktura `VF` / `PF` s `INTER`), bez
     * uzávěrkových.
     *
     * @return list<array<string,mixed>>
     */
    public function linkedRows(string $sbKod, int $sbornik): array
    {
        return $this->byDocument[strtoupper($sbKod) . '|' . $sbornik] ?? [];
    }

    /** @param iterable<array<string,mixed>> $rows */
    private function load(iterable $rows): void
    {
        foreach ($rows as $r) {
            $row = self::normalize($r);
            if ($row === null) {
                continue;
            }
            $this->rows[] = $row;
            $this->byInter[$row['inter']] = $row;
            if ($row['sb_kod'] !== '' && $row['sbornik'] > 0 && $row['kind'] !== 'closing') {
                $this->byDocument[$row['sb_kod'] . '|' . $row['sbornik']][] = $row;
            }
        }
        usort($this->rows, static fn (array $a, array $b): int => ($a['date'] <=> $b['date']) ?: ($a['inter'] <=> $b['inter']));
    }

    /** @return list<array<string,mixed>> řádky roku (bez počátečních a uzávěrkových) */
    public function year(int $year): array
    {
        return array_values(array_filter($this->rows, static fn (array $r): bool => $r['year'] === $year && $r['kind'] === 'regular'));
    }

    /** @return list<array<string,mixed>> zápisy počátečních stavů roku (701), jsou-li v deníku */
    public function openingRows(int $year): array
    {
        return array_values(array_filter($this->rows, static fn (array $r): bool => $r['year'] === $year && $r['kind'] === 'opening'));
    }

    public function closingRowCount(int $year): int
    {
        return count(array_filter($this->rows, static fn (array $r): bool => $r['year'] === $year && $r['kind'] === 'closing'));
    }

    public function row(int $inter): ?array
    {
        return $this->byInter[$inter] ?? null;
    }

    public function firstYear(): ?int
    {
        return $this->rows === [] ? null : $this->rows[0]['year'];
    }

    public function hasRowsBefore(int $year): bool
    {
        return $this->rows !== [] && $this->rows[0]['year'] < $year;
    }

    /** @return list<string> kódy účtů (šestimístné z PREMIER), na které účtuje rok včetně počátečních stavů */
    public function accountsUsed(int $year): array
    {
        $used = [];
        foreach ($this->rows as $r) {
            if ($r['year'] === $year && $r['kind'] !== 'closing') {
                $used[$r['md']] = true;
                $used[$r['dal']] = true;
            }
        }
        unset($used['']);
        $codes = array_map('strval', array_keys($used));
        sort($codes, SORT_STRING);
        return $codes;
    }

    /**
     * Zápisy deníku roku. Doklad ({@see groups()}) je jeden zápis, jen doklad bankovní řady
     * se dělí po pohybech: MyÚčto bere pohyb za zaúčtovaný, když má vlastní zápis se zdrojem
     * `bank`, a jeden zápis smí mít jen jeden zdroj. Každý pohyb ({@see bankAmount()}) je
     * zápis s klíčem `doklad|#INTER`; ostatní řádky výpisu (haléřové a kurzové rozdíly…)
     * jdou do zápisu pohybu, který hradí stejnou fakturu (vazby `VAZBY`), zbylé do zápisu
     * `doklad|#`. Každý řádek je sám vyrovnaný, dělení na obratech nic nemění.
     *
     * @return array<string,list<array<string,mixed>>> klíč zápisu → řádky
     */
    public function documents(int $year): array
    {
        $out = [];
        foreach ($this->groups($year) as $key => $rows) {
            foreach ($this->splitByMovement($key, $rows) as $part => $partRows) {
                $out[$part] = $partRows;
            }
        }
        return $out;
    }

    /**
     * Doklady roku: řádky se stejnou řadou, číslem, datem a vazbou do sborníku tvoří
     * jeden doklad (bankovní výpis s pohyby více dnů je tak doklad na každý den).
     *
     * @return array<string,list<array<string,mixed>>> klíč dokladu → řádky
     */
    public function groups(int $year): array
    {
        $out = [];
        foreach ($this->year($year) as $r) {
            $out[self::documentKey($r)][] = $r;
        }
        return $out;
    }

    /** @param array<string,mixed> $r */
    public static function documentKey(array $r): string
    {
        return $r['series'] . '|' . $r['number'] . '|' . $r['date'] . '|' . $r['sb_kod'] . '|' . $r['sbornik'];
    }

    /** Klíč dokladu ({@see groups()}), ze kterého zápis ({@see documents()}) vznikl. */
    public static function groupKey(string $entryKey): string
    {
        return (string) preg_replace('/\|#\d*$/', '', $entryKey);
    }

    /**
     * Úhrady faktur po řádcích deníku ({@see PremierDocuments::paymentLinks()}) - podle nich
     * se řádky bankovního dokladu přiřazují k pohybům.
     *
     * @param array<int,list<array{direction:string,inter:int}>> $links
     */
    public function usePaymentLinks(array $links): void
    {
        $this->paymentTargets = [];
        foreach ($links as $inter => $targets) {
            foreach ($targets as $t) {
                $this->paymentTargets[(int) $inter][] = $t['direction'] . '|' . $t['inter'];
            }
        }
    }

    /**
     * Bankovní řady deníku z číselníku řad PREMIER (`DOKL_PU.TOK` = 2).
     *
     * @return array<string,array{account:string,number:string,bank:string,iban:string,currency:string,label:string}>
     */
    public function bankSeries(): array
    {
        return $this->bankSeries;
    }

    /**
     * Pohyb na bankovním účtu: řádek bankovní řady na účtu řady s nenulovou částkou v měně
     * účtu (+ příjem, - výdej); jinak `null`. Kurzové přecenění účtu v cizí měně (částka
     * v měně 0) pohyb není - zůstává jen v deníku.
     *
     * @param array<string,mixed> $r
     */
    public function bankAmount(array $r): ?float
    {
        $s = $this->bankSeries[$r['series']] ?? null;
        if ($s === null || ($r['md'] !== $s['account'] && $r['dal'] !== $s['account'])) {
            return null;
        }
        $amount = self::accountAmount($r, $s['account'], $s['currency'] !== 'CZK');
        return abs($amount) < 0.005 ? null : $amount;
    }

    /**
     * Pohyb řádku na účtu v měně účtu (+ příjem, - výdej).
     *
     * @param array<string,mixed> $r
     */
    public static function accountAmount(array $r, string $account, bool $foreign): float
    {
        $movement = self::movement($r, $account);
        if (!$foreign || abs($movement) < 0.005) {
            return $movement;
        }
        $value = abs((float) $r['amount_foreign']);
        return round($movement < 0 ? -$value : $value, 2);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,list<array<string,mixed>>>
     */
    private function splitByMovement(string $key, array $rows): array
    {
        if (!isset($this->bankSeries[$rows[0]['series']])) {
            return [$key => $rows];
        }
        $movements = [];
        $owners = [];
        foreach ($rows as $r) {
            if ($this->bankAmount($r) !== null) {
                $movements[$r['inter']] = [$r];
                foreach ($this->paymentTargets[$r['inter']] ?? [] as $target) {
                    $owners[$target] ??= $r['inter'];
                }
            }
        }
        if ($movements === []) {
            return [$key => $rows];
        }
        $rest = [];
        foreach ($rows as $r) {
            if (isset($movements[$r['inter']])) {
                continue;
            }
            $owner = null;
            foreach ($this->paymentTargets[$r['inter']] ?? [] as $target) {
                if (isset($owners[$target])) {
                    $owner = $owners[$target];
                    break;
                }
            }
            if ($owner !== null) {
                $movements[$owner][] = $r;
            } else {
                $rest[] = $r;
            }
        }
        $out = [];
        foreach ($movements as $inter => $movementRows) {
            $out[$key . '|#' . $inter] = $movementRows;
        }
        if ($rest !== []) {
            $out[$key . '|#'] = $rest;
        }
        return $out;
    }

    /** @param iterable<array<string,mixed>> $rows */
    private function loadBankSeries(iterable $rows): void
    {
        foreach ($rows as $r) {
            if ((int) ($r['TOK'] ?? 0) !== 2) {
                continue;
            }
            $code = strtoupper(trim((string) ($r['DOKLAD'] ?? '')));
            $account = trim((string) ($r['MD'] ?? '')) . trim((string) ($r['MDA'] ?? ''));
            if ($account === '' || !ctype_digit($account)) {
                $account = trim((string) ($r['DAL'] ?? '')) . trim((string) ($r['DALA'] ?? ''));
            }
            if ($code === '' || !ctype_digit($account) || AccountCode::fromMoney($account) === null) {
                continue;
            }
            $currency = strtoupper(trim((string) ($r['MENA'] ?? '')));
            $this->bankSeries[$code] = [
                'account' => $account,
                'number' => str_replace(' ', '', trim((string) ($r['CISLO_U'] ?? ''))),
                'bank' => trim((string) ($r['KOD_U'] ?? '')),
                'iban' => str_replace(' ', '', trim((string) ($r['IBAN'] ?? ''))),
                // Řada bez měny v číselníku (termínovaný vklad v EUR…) - měna z deníku.
                'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : $this->accountCurrency($account),
                'label' => trim((string) ($r['NAZEV_B'] ?? '')) ?: (trim((string) ($r['TEXT'] ?? '')) ?: $code),
            ];
        }
    }

    /**
     * Počáteční stavy roku spočtené z deníku předchozích let: zůstatky rozvahových účtů
     * (třídy 0-4) a výsledek hospodaření minulých let (třídy 5 a 6) na účet
     * `$resultAccount`. Kladná hodnota = zůstatek MD.
     *
     * Výsledek se sčítá za VŠECHNY předchozí roky: rozdělení výsledku, které PREMIER
     * účtuje v dalším roce (431 → 428), je v rozvahových účtech, takže součet vychází
     * přesně jako převod stavů v PREMIER.
     *
     * @return array<string,float> kód účtu (PREMIER) → zůstatek
     */
    public function openingBalances(int $year, string $resultAccount): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if ($r['year'] >= $year || $r['kind'] === 'closing') {
                continue;
            }
            foreach ([[$r['md'], 1], [$r['dal'], -1]] as [$code, $sign]) {
                $class = (string) substr((string) $code, 0, 1);
                if ($class === '' || $class === '7' || $class > '7') {
                    continue;
                }
                $target = in_array($class, ['5', '6'], true) ? $resultAccount : (string) $code;
                $out[$target] = ($out[$target] ?? 0.0) + $sign * $r['amount'];
            }
        }
        foreach ($out as $code => $v) {
            $out[$code] = round($v, 2);
            if (abs($out[$code]) < 0.005) {
                unset($out[$code]);
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Předvaha přímo z deníku PREMIER po syntetických účtech: netto PS, obrat, KS.
     *
     * @param array<string,float> $opening počáteční stavy roku (kód PREMIER → zůstatek)
     * @return array<string,array{0:float,1:float,2:float}>
     */
    public function trialBalance(int $year, array $opening): array
    {
        $out = [];
        foreach ($opening as $code => $balance) {
            $syn = AccountCode::synthetic((string) $code);
            if ($syn !== null) {
                $out[$syn] ??= [0.0, 0.0, 0.0];
                $out[$syn][0] += $balance;
            }
        }
        foreach ($this->year($year) as $r) {
            foreach ([[$r['md'], 1], [$r['dal'], -1]] as [$code, $sign]) {
                $syn = AccountCode::synthetic((string) $code);
                if ($syn !== null) {
                    $out[$syn] ??= [0.0, 0.0, 0.0];
                    $out[$syn][1] += $sign * $r['amount'];
                }
            }
        }
        foreach ($out as $syn => $v) {
            $out[$syn] = [round($v[0], 2), round($v[1], 2), round($v[0] + $v[1], 2)];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Čistý účinek řádku: kladná částka jde MD / Dal, záporná obráceně (PREMIER opravy
     * a dobropisy účtuje zápornou částkou na stejné strany).
     *
     * @param array<string,mixed> $r
     * @return array{debit:string,credit:string,amount:float}|null null = bez účinku
     */
    public static function effect(array $r): ?array
    {
        $amount = (float) $r['amount'];
        if (abs($amount) < 0.005 || $r['md'] === '' || $r['dal'] === '' || $r['md'] === $r['dal']) {
            return null;
        }
        return $amount < 0
            ? ['debit' => $r['dal'], 'credit' => $r['md'], 'amount' => round(-$amount, 2)]
            : ['debit' => $r['md'], 'credit' => $r['dal'], 'amount' => round($amount, 2)];
    }

    /**
     * Pohyb řádku na účtech s předponou (`311`, `221400`): + MD, - Dal.
     *
     * @param array<string,mixed> $r
     */
    public static function movement(array $r, string $prefix): float
    {
        $v = 0.0;
        if (str_starts_with($r['md'], $prefix)) {
            $v += $r['amount'];
        }
        if (str_starts_with($r['dal'], $prefix)) {
            $v -= $r['amount'];
        }
        return round($v, 2);
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>|null
     */
    private static function normalize(array $r): ?array
    {
        $date = (string) ($r['DATUM'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        $md = self::account((string) ($r['MD'] ?? ''));
        $dal = self::account((string) ($r['DAL'] ?? ''));
        $amount = round((float) ($r['CASTKA'] ?? 0), 2);
        $kind = 'regular';
        if (in_array(substr($md, 0, 3), self::CLOSING_ACCOUNTS, true) || in_array(substr($dal, 0, 3), self::CLOSING_ACCOUNTS, true)) {
            $kind = 'closing';
        } elseif (str_starts_with($md, self::OPENING_ACCOUNT) || str_starts_with($dal, self::OPENING_ACCOUNT)) {
            $kind = 'opening';
        }
        $currency = strtoupper(trim((string) ($r['MENA'] ?? '')));
        return [
            'inter' => (int) ($r['INTER'] ?? 0),
            'date' => $date,
            'year' => (int) substr($date, 0, 4),
            'tax_date' => self::date($r['DATUM_DPH'] ?? null),
            'series' => strtoupper(trim((string) ($r['DOKLAD'] ?? ''))),
            'number' => (string) ($r['CISLO'] ?? ''),
            'text' => trim((string) ($r['POPIS'] ?? '')),
            'note' => trim((string) ($r['POZNAMKA'] ?? '')),
            'amount' => $amount,
            'md' => $md,
            'dal' => $dal,
            'vat_code' => trim((string) ($r['KOD_DPH'] ?? '')),
            'vat_rate' => (float) ($r['SAZBA_DPH'] ?? 0),
            'vat_amount' => round((float) ($r['CASTKA_DPH'] ?? 0), 2),
            'line_kind' => strtoupper(trim((string) ($r['IKOD'] ?? ''))),
            'sb_kod' => strtoupper(trim((string) ($r['SB_KOD'] ?? ''))),
            'sbornik' => (int) ($r['SBORNIK'] ?? 0),
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : '',
            'amount_foreign' => round((float) ($r['ZCASTKA'] ?? 0), 2),
            'rate' => (float) ($r['KURS'] ?? 0),
            'rate_units' => max(1, (int) ($r['M_KURS'] ?? 1)),
            'variable_symbol' => trim((string) ($r['VARIABL'] ?? '')),
            // Údaje bankovního pohybu: VS příjmu (`VAR_DAL`) a z homebankingu (`H*`, `PARTRAN`).
            'variable_symbol_credit' => trim((string) ($r['VAR_DAL'] ?? '')),
            'bank_variable_symbol' => trim((string) ($r['HVAR'] ?? '')),
            'bank_account' => trim((string) ($r['HUCET'] ?? '')),
            'bank_constant_symbol' => trim((string) ($r['HKS'] ?? '')),
            'bank_specific_symbol' => trim((string) ($r['HSPEC'] ?? '')),
            'bank_message' => trim((string) ($r['HZPR_PRIJ'] ?? '')),
            'bank_ref' => trim((string) ($r['PARTRAN'] ?? '')),
            'partner_no' => trim((string) ($r['CISLO_ODB'] ?? '')),
            'partner_name' => trim((string) ($r['NAZEV_ODB'] ?? '')),
            'partner_ico' => trim((string) ($r['ICO_ODB'] ?? '')),
            'partner_dic' => strtoupper(str_replace(' ', '', trim((string) ($r['DIC_ODB'] ?? '')))),
            'partner_street' => trim((string) ($r['ULICE_ODB'] ?? '')),
            'partner_city' => trim((string) ($r['MESTO_ODB'] ?? '')),
            'partner_zip' => trim((string) ($r['PSC_ODB'] ?? '')),
            'partner_country' => trim((string) ($r['STAT_ODB'] ?? '')),
            'partner_id' => trim((string) ($r['ID_PAR'] ?? '')),
            'cost_center' => (int) ($r['STKOD'] ?? 0),
            'kind' => $kind,
        ];
    }

    /** Účet z deníku PREMIER jen číslicemi (`518100`), jinak prázdný. */
    private static function account(string $code): string
    {
        $code = trim($code);
        return ctype_digit($code) && strlen($code) >= 3 ? $code : '';
    }

    private static function date(mixed $value): ?string
    {
        $v = (string) ($value ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}
