<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Pracovní sestava přiznání k dani z příjmů (DPPO i DPFO) pro kontrolu s účetní a do
 * archivu. Není podáním a netváří se jako tiskopis Finanční správy.
 *
 * Každá částka přiznání se čte z XML, které vzniká stejnou cestou jako export pro EPO
 * ({@see TaxReturnService::reportSource()}); sestava tedy nemá vlastní výpočet a nemůže
 * se od XML rozejít. Z výpočtu (téhož, ze kterého XML vzniklo) se berou jen popisky
 * řádků a údaje, které XML nenese: předpis záloh podle § 38a a rozdíl u dodatečného DPFO.
 * Evidence daňových ztrát a ruční vstupy jsou doplněk, v XML nejsou vůbec.
 *
 * Čistá třída bez databáze; popisky řádků výkazů dodává volající.
 */
final class TaxReturnReportBuilder
{
    /** Klíčové řádky II. oddílu DPPO, zobrazují se i s nulou. */
    private const PO_KEY_LINES = [10, 200, 250, 270, 290, 310, 340, 360];

    /** Řádky II. oddílu DPPO, které XML nese mimo {@see DppoXmlBuilder::LINE_ATTR}: atribut a formát. */
    private const PO_EXTRA_LINES = [
        220 => ['kc_ii_220', 'money'],
        280 => ['kc_ii270_280', 'percent'],
        330 => ['kc_ii320_330', 'money'],
    ];

    /** Klíčové řádky DPFO (atributy), zobrazují se i s nulou. */
    private const FO_KEY_ATTRS = ['kc_zakldan23', 'kc_zakldan', 'kc_zdzaokr', 'da_dan16', 'da_slevy35c', 'kc_zbyvpred'];

    /**
     * Kód řádku výpočtu DPFO ({@see DpfoReturnCalculator}) → atribut XML DPFDP7. Slouží jen
     * k dohledání poznámky o zdroji; číslo řádku a popisek bere sestava z
     * {@see TaxReturnLineCatalog} (kódy výpočtu se s čísly tiskopisu místy nekryjí).
     */
    private const FO_LINE_ATTR = [
        '31' => 'kc_prij6', '34' => 'kc_zd6', '37' => 'kc_zd7', '38' => 'kc_zakldan8',
        '39' => 'kc_zd9', '40' => 'kc_zd10', '41' => 'kc_uhrn', '42' => 'kc_zakldan23',
        '44' => 'kc_ztrata2', '45' => 'kc_zakldan', '54' => 'kc_odcelk', '55' => 'kc_zdsniz',
        '56' => 'kc_zdzaokr', '57' => 'da_dan16', '60' => 'uhrn_slevy35ba', '64' => 'da_slevy35ba',
        '72' => 'kc_dazvyhod', '74' => 'da_slevy35c', '75' => 'kc_danbonus', '84' => 'kc_zalzavc',
        '85' => 'kc_zalpred', '91' => 'kc_zbyvpred',
    ];

    private const FORMA_LABELS = [
        'B' => 'řádné přiznání',
        'O' => 'opravné přiznání',
        'D' => 'dodatečné přiznání',
        'E' => 'opravné dodatečné přiznání',
    ];

    private const CATEGORY_LABELS = ['M' => 'mikro', 'L' => 'malá', 'S' => 'střední', 'V' => 'velká'];

    private const SCOPE_LABELS = ['P' => 'v plném rozsahu', 'Z' => 've zkráceném rozsahu', 'M' => 've zkráceném rozsahu pro mikro účetní jednotku'];

    /** Předepsané přílohy DPPO (`Prilohy/PredepsanaPriloha kod`). */
    private const PREDEPSANE_PRILOHY = [
        'PP_OPISPUV' => 'Příloha v účetní závěrce',
        'PP_PTOK' => 'Přehled o peněžních tocích',
        'PP_ZVKAP' => 'Přehled o změnách vlastního kapitálu',
    ];

    /** Tabulka majetku a dluhů DPFO (§ 7b), kódy atributů `kc_dpfmz*` věty U. */
    private const FO_ASSET_ROWS = [
        '02' => 'Hmotný majetek',
        '05a' => 'Peněžní prostředky v hotovosti',
        '06' => 'Peněžní prostředky na bankovních účtech',
        '03' => 'Zásoby',
        '04' => 'Pohledávky včetně poskytnutých úvěrů a zápůjček',
        '08' => 'Ostatní majetek',
        '10' => 'Dluhy včetně přijatých úvěrů a zápůjček',
        '11' => 'Rezervy',
    ];

    /** Řádky VZZ, které ve výkazu nemají písmenné označení (mezisoučty). */
    private const VZZ_CALC_ROWS = ['PVH', 'FVH', 'VHPZ', 'VHPO', 'VH', 'OBRAT'];

    /**
     * @param array<string,mixed> $source výstup {@see TaxReturnService::reportSource()}
     * @param array<string,array<string,array{label:string,level:int,row_type:string}>> $labels
     *   popisky řádků výkazů podle `row_code` (`balance_sheet`, `income_statement`), jen DPPO
     * @return array<string,mixed>
     */
    public function build(array $source, array $labels = [], ?\DateTimeImmutable $now = null): array
    {
        $type = (string) ($source['type'] ?? '');
        if (!in_array($type, ['po', 'fo'], true)) {
            throw new TaxReturnException('invalid_type', 'Neplatný typ přiznání (fo|po).', 400);
        }
        $xml = (string) ($source['xml'] ?? '');
        $dom = $this->load($xml);
        $now ??= new \DateTimeImmutable();

        $report = [
            'type' => $type,
            'title' => $type === 'po'
                ? 'Přiznání k dani z příjmů právnických osob'
                : 'Přiznání k dani z příjmů fyzických osob',
            'form_code' => strtoupper((string) ($source['form_code'] ?? ($type === 'po' ? 'dppdp9' : 'dpfdp7'))),
            'year' => (int) ($source['year'] ?? 0),
            'header' => $this->header($dom, $source, $now, $xml),
            'amendment' => null,
            'statements' => [],
            'tables' => [],
            'warnings' => array_values(array_filter(array_map('strval', (array) ($source['warnings'] ?? [])), static fn (string $w): bool => trim($w) !== '')),
            'notes' => [],
        ];

        return $type === 'po'
            ? $this->buildPo($report, $dom, $source, $labels)
            : $this->buildFo($report, $dom, $source);
    }

    // ── DPPO ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $source
     * @param array<string,array<string,array{label:string,level:int,row_type:string}>> $labels
     * @return array<string,mixed>
     */
    private function buildPo(array $report, \DOMDocument $dom, array $source, array $labels): array
    {
        $d = $this->attrs($dom, 'VetaD');
        $o = $this->attrs($dom, 'VetaO');
        $result = (array) ($source['computation']['result'] ?? []);
        $line = fn (int $n): float => $this->num($o, DppoXmlBuilder::LINE_ATTR[$n]) ?? 0.0;

        $report['lines'] = [
            'title' => 'II. oddíl: daň z příjmů právnických osob',
            'rows' => $this->poLines($o, (array) ($result['lines'] ?? [])),
        ];

        $po = fn (int $n, string $style = '') => $this->sum(TaxReturnLineCatalog::po($n), 'ř. ' . $n, $line($n), $style);
        $rows = [$po(10), $po(70), $po(170), $po(200, 'strong'), $po(230)];
        foreach ([242, 243, 260] as $n) {
            if ($line($n) !== 0.0) {
                $rows[] = $po($n);
            }
        }
        $rows[] = $po(270, 'strong');
        $rows[] = $this->sum(TaxReturnLineCatalog::po(280), 'ř. 280', $this->num($o, 'kc_ii270_280'), '', 'percent');
        $rows[] = $po(290);
        $rows[] = $po(300);
        $rows[] = $po(340, 'total');
        $rows[] = $this->sum(TaxReturnLineCatalog::PO_OTHER['kc_v_1'], 'V. oddíl', $this->num($d, 'kc_v_1') ?? 0.0);

        // kc_v_4 nese doplatek se znaménkem EPO: záporné = doplatek, kladné = přeplatek.
        $balance = -($this->num($d, 'kc_v_4') ?? 0.0);
        $report['summary'] = [
            'rows' => $rows,
            'result' => $this->balance($balance, 'Doplatek daně', 'Přeplatek daně'),
            'advances' => $this->advances($line(360), (array) ($result['next_advances'] ?? [])),
        ];

        if (($d['dapdpp_forma'] ?? 'B') === 'D' || ($d['dapdpp_forma'] ?? 'B') === 'E') {
            $report['amendment'] = [
                $this->sum('Nově zjištěná daň', 'V. oddíl', $this->num($d, 'kc_dppiv1')),
                $this->sum('Poslední známá daň', 'V. oddíl', $this->num($d, 'kc_dppiv2')),
                $this->sum('Rozdíl daně', 'V. oddíl', $this->num($d, 'kc_dppiv3'), 'total'),
                $this->sum('Datum zjištění důvodů', '', $this->isoDate($d['d_zjist'] ?? ''), '', 'date'),
            ];
        }

        $report['statements'] = $this->poStatements($dom, $labels);
        if ($report['statements'] === []) {
            $report['notes'][] = 'XML neobsahuje přílohu účetní závěrky (rozvahu a výkaz zisku a ztráty). '
                . 'Důvod uvádějí upozornění k podání.';
        }
        $contents = $this->poContents($dom);
        if ($contents !== []) {
            $report['notes'][] = 'Podání dále obsahuje: ' . implode(', ', $contents) . '.';
        }

        $lossTable = $this->lossTable((array) ($source['tax_losses'] ?? []), $line(230), 'ř. 230', $line(200) < 0 ? -$line(200) : 0.0);
        if ($lossTable !== null) {
            $report['tables'][] = $lossTable;
        }
        foreach ($this->inputTables('po', (array) ($source['inputs'] ?? [])) as $table) {
            $report['tables'][] = $table;
        }

        return $report;
    }

    /**
     * @param array<string,string> $o
     * @param list<array<string,mixed>> $calcLines
     * @return list<array<string,mixed>>
     */
    private function poLines(array $o, array $calcLines): array
    {
        $calc = [];
        foreach ($calcLines as $l) {
            $calc[(int) ($l['line'] ?? 0)] = $l;
        }
        $numbers = array_unique(array_merge(array_keys(DppoXmlBuilder::LINE_ATTR), array_keys(self::PO_EXTRA_LINES)));
        sort($numbers);

        $rows = [];
        foreach ($numbers as $n) {
            [$attr, $format] = isset(DppoXmlBuilder::LINE_ATTR[$n])
                ? [DppoXmlBuilder::LINE_ATTR[$n], 'money']
                : self::PO_EXTRA_LINES[$n];
            $value = $this->num($o, $attr);
            $key = in_array($n, self::PO_KEY_LINES, true);
            if ($value === null && !$key) {
                continue;
            }
            $rows[] = $this->lineRow(
                (string) $n,
                TaxReturnLineCatalog::po($n),
                (string) ($calc[$n]['source'] ?? ''),
                $value ?? 0.0,
                $format,
                $key,
            );
        }
        return $rows;
    }

    /**
     * Rozvaha a VZZ tak, jak jdou do přílohy přiznání (věty UA, UD, UB), v celých
     * tisících Kč. Číslo řádku tiskopisu se převádí na `row_code` přes číselník, podle
     * kterého builder XML píše ({@see DppoXmlBuilder::appendixRowNumbers()}).
     *
     * @param array<string,array<string,array{label:string,level:int,row_type:string}>> $labels
     * @return list<array<string,mixed>>
     */
    private function poStatements(\DOMDocument $dom, array $labels): array
    {
        $numbers = DppoXmlBuilder::appendixRowNumbers();
        $defs = [
            ['VetaUA', 'assets', 'balance_sheet', 'Rozvaha: aktiva', ['Brutto', 'Korekce', 'Netto', 'Netto min. období'], ['kc_brutto', 'kc_korekce', 'kc_netto', 'kc_netto_min']],
            ['VetaUD', 'liabilities', 'balance_sheet', 'Rozvaha: pasiva', ['Běžné období', 'Minulé období'], ['kc_sled', 'kc_min']],
            ['VetaUB', 'income_statement', 'income_statement', 'Výkaz zisku a ztráty (druhové členění)', ['Běžné období', 'Minulé období'], ['kc_sled', 'kc_min']],
        ];

        $out = [];
        foreach ($defs as [$tag, $section, $labelSet, $title, $columns, $attrs]) {
            $elements = $this->all($dom, $tag);
            if ($elements === []) {
                continue;
            }
            $byNumber = [];
            foreach ($numbers[$section] as $rowCode => $cRadkuList) {
                foreach ($cRadkuList as $cRadku) {
                    $byNumber[$cRadku] ??= (string) $rowCode;
                }
            }
            $rows = [];
            foreach ($elements as $el) {
                $cRadku = (int) ($el['c_radku'] ?? 0);
                if ($cRadku <= 0) {
                    continue;
                }
                $rowCode = $byNumber[$cRadku] ?? null;
                $def = $rowCode !== null ? ($labels[$labelSet][$rowCode] ?? null) : null;
                $values = [];
                foreach ($attrs as $attr) {
                    $values[] = (int) round($this->num($el, $attr) ?? 0.0);
                }
                $level = $def !== null ? (int) $def['level'] : $this->levelFromCode((string) $rowCode);
                $rows[$cRadku] = [
                    'number' => $cRadku,
                    'code' => $this->displayCode($rowCode, $cRadku),
                    'label' => $def !== null ? (string) $def['label'] : $this->fallbackLabel($rowCode, $cRadku),
                    'level' => max(0, $level),
                    'strong' => $def !== null
                        ? ((string) $def['row_type'] !== 'detail' || $level === 0)
                        : ($rowCode === null || in_array($rowCode, self::VZZ_CALC_ROWS, true) || $level <= 1),
                    'values' => $values,
                ];
            }
            ksort($rows);
            $out[] = [
                'key' => $section,
                'title' => $title,
                'unit' => 'v celých tisících Kč',
                'columns' => $columns,
                'rows' => array_values($rows),
            ];
        }
        return $out;
    }

    /** @return list<string> */
    private function poContents(\DOMDocument $dom): array
    {
        $parts = [];
        $named = [
            'VetaE' => 'tabulku a) přílohy č. 1 (rozpis ř. 40)',
            'VetaF' => 'tabulku B (odpisy)',
            'VetaG' => 'tabulku C (opravné položky a rezervy)',
            'VetaM' => 'tabulku H (slevy na dani)',
            'VetaR' => 'zvláštní přílohu k ř. 62',
            'VetaA' => 'přehled transakcí se spojenými osobami',
            'VetaNP' => 'žádost o vrácení přeplatku',
        ];
        foreach ($named as $tag => $label) {
            $count = count($this->all($dom, $tag));
            if ($count > 0) {
                $parts[] = $count > 1 ? $label . ' (' . $count . '×)' : $label;
            }
        }
        foreach ($this->all($dom, 'PredepsanaPriloha') as $attachment) {
            $kod = (string) ($attachment['kod'] ?? '');
            $parts[] = 'přiložený soubor: ' . (self::PREDEPSANE_PRILOHY[$kod] ?? $kod);
        }
        return $parts;
    }

    // ── DPFO ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function buildFo(array $report, \DOMDocument $dom, array $source): array
    {
        $d = $this->attrs($dom, 'VetaD');
        $f = $d + $this->attrs($dom, 'VetaO') + $this->attrs($dom, 'VetaS');
        $result = (array) ($source['computation']['result'] ?? []);
        $v = fn (string $attr): float => $this->num($f, $attr) ?? 0.0;

        $report['lines'] = [
            'title' => 'Výpočet daně (řádky přiznání)',
            'rows' => $this->foLines($f, (array) ($result['lines'] ?? [])),
        ];

        $fo = function (string $attr, string $style = '') use ($v): array {
            [$row, $label] = TaxReturnLineCatalog::fo($attr);
            return $this->sum($label, 'ř. ' . $row, $v($attr), $style);
        };
        $rows = [
            $fo('kc_zd6'), $fo('kc_zd7'), $fo('kc_zakldan8'), $fo('kc_zd9'), $fo('kc_zd10'), $fo('kc_uhrn'),
            $fo('kc_zakldan23', 'strong'), $fo('kc_ztrata2'), $fo('kc_odcelk'), $fo('kc_zdzaokr', 'strong'),
            $fo('da_dan16'), $fo('uhrn_slevy35ba'), $fo('da_slevy35ba'),
        ];
        if ($v('kc_dazvyhod') !== 0.0) {
            $rows[] = $fo('kc_dazvyhod');
            $rows[] = $fo('kc_slevy35c');
            $rows[] = $fo('kc_danbonus');
        }
        $rows[] = $fo('da_slevy35c', 'total');
        if ($v('kc_zalzavc') !== 0.0) {
            $rows[] = $fo('kc_zalzavc');
        }
        $rows[] = $fo('kc_zalpred');

        $report['summary'] = [
            'rows' => $rows,
            // kc_zbyvpred: kladné = zbývá doplatit, záporné = přeplatek.
            'result' => $this->balance($v('kc_zbyvpred'), 'Zbývá doplatit', 'Přeplatek'),
            'advances' => $this->advances((float) ($result['tax'] ?? 0), (array) ($result['next_advances'] ?? [])),
        ];

        if (in_array((string) ($d['dap_typ'] ?? 'B'), ['D', 'E'], true)) {
            $amend = (array) ($result['amendment'] ?? []);
            $report['amendment'] = [
                $this->sum('Nově zjištěná daň', '', isset($amend['new_tax']) ? (float) $amend['new_tax'] : null),
                $this->sum('Poslední známá daň', '', isset($amend['last_known_tax']) ? (float) $amend['last_known_tax'] : null),
                $this->sum('Rozdíl daně', '', isset($amend['tax_difference']) ? (float) $amend['tax_difference'] : null, 'total'),
                $this->sum('Datum zjištění důvodů', '', $this->isoDate($d['d_zjist'] ?? ''), '', 'date'),
            ];
        }

        foreach ($this->foAppendixTables($dom, $d) as $table) {
            $report['tables'][] = $table;
        }
        $lossTable = $this->lossTable((array) ($source['tax_losses'] ?? []), $v('kc_ztrata2'), 'ř. 44', $v('kc_dztrata'));
        if ($lossTable !== null) {
            $report['tables'][] = $lossTable;
        }
        foreach ($this->inputTables('fo', (array) ($source['inputs'] ?? [])) as $table) {
            $report['tables'][] = $table;
        }

        $report['notes'][] = 'Přehledy pojistného OSVČ (sociální pojištění pro ČSSZ a přehled pro zdravotní '
            . 'pojišťovnu) nejsou součástí této sestavy; tisknou se samostatně na kartě Export v části Pojistné.';
        if ((float) ($result['summary']['separate_base'] ?? 0) > 0) {
            $report['notes'][] = 'Samostatný základ daně podle § 16a se do XML nezapisuje a v portálu EPO se doplňuje ručně.';
        }

        return $report;
    }

    /**
     * @param array<string,string> $f
     * @param list<array<string,mixed>> $calcLines
     * @return list<array<string,mixed>>
     */
    private function foLines(array $f, array $calcLines): array
    {
        $notes = [];
        foreach ($calcLines as $l) {
            $attr = self::FO_LINE_ATTR[(string) ($l['code'] ?? $l['line'] ?? '')] ?? null;
            if ($attr !== null) {
                $notes[$attr] = (string) ($l['source'] ?? '');
            }
        }
        $rows = [];
        foreach (TaxReturnLineCatalog::FO as $attr => [$row, $label]) {
            $value = $this->num($f, $attr);
            $key = in_array($attr, self::FO_KEY_ATTRS, true);
            if (($value === null || $value === 0.0) && !$key) {
                continue;
            }
            $rows[] = $this->lineRow($row, $label, $notes[$attr] ?? '', $value ?? 0.0, 'money', $key);
        }
        return $rows;
    }

    /**
     * Přílohy DPFO přečtené z XML: Příloha č. 1 (věta T, činnosti, oddíl E, majetek a
     * dluhy), Příloha č. 2 (věty V a J), děti (věta A) a manžel/manželka (věta D).
     *
     * @param array<string,string> $d
     * @return list<array<string,mixed>>
     */
    private function foAppendixTables(\DOMDocument $dom, array $d): array
    {
        $tables = [];
        $t = $this->attrs($dom, 'VetaT');
        if ($t !== []) {
            $mode = ($t['vyd7proc'] ?? '') === 'A'
                ? 'výdaje procentem z příjmů' . (isset($t['pr_sazba']) ? ' (' . $t['pr_sazba'] . ' %)' : '')
                : (($t['uc_soust'] ?? '') === '2' ? 'skutečné výdaje, účetnictví' : 'skutečné výdaje, daňová evidence');
            $rows = [
                $this->row([$this->c('101'), $this->c('Příjmy (§ 7)'), $this->c($this->num($t, 'kc_prij7'), 'money')]),
                $this->row([$this->c('102'), $this->c('Výdaje (' . $mode . ')'), $this->c($this->num($t, 'kc_vyd7'), 'money')]),
                $this->row([$this->c('104'), $this->c('Rozdíl příjmů a výdajů'), $this->c($this->num($t, 'kc_hosp_rozd'), 'money')]),
                $this->row([$this->c('105'), $this->c('Úpravy zvyšující rozdíl'), $this->c($this->num($t, 'kc_uhzvys'), 'money')]),
                $this->row([$this->c('106'), $this->c('Úpravy snižující rozdíl'), $this->c($this->num($t, 'kc_uhsniz'), 'money')]),
                $this->row([$this->c('113'), $this->c('Dílčí základ daně ze samostatné činnosti'), $this->c($this->num($t, 'kc_zd7p'), 'money')], true),
            ];
            $tables[] = $this->table('Příloha č. 1: příjmy ze samostatné činnosti', null,
                [['ř.', 'left'], ['Položka', 'left'], ['Kč', 'right']], $rows);

            $activities = [];
            if (isset($t['pr_prij7']) || isset($t['c_nace'])) {
                $activities[] = $this->row([
                    $this->c('hlavní' . (isset($t['c_nace']) ? ', CZ-NACE ' . $t['c_nace'] : '')),
                    $this->c(isset($t['m_podnik']) ? (int) $t['m_podnik'] : null, 'int'),
                    $this->c($this->num($t, 'pr_prij7'), 'money'),
                    $this->c($this->num($t, 'pr_vyd7'), 'money'),
                ]);
            }
            foreach ($this->all($dom, 'Vetac') as $a) {
                $activities[] = $this->row([
                    $this->c('vedlejší' . (isset($a['c_nace_dal']) ? ', CZ-NACE ' . $a['c_nace_dal'] : '')
                        . (!empty($a['sazba_dal']) ? ', paušál ' . $a['sazba_dal'] . ' %' : '')),
                    $this->c(null, 'int'),
                    $this->c($this->num($a, 'prijmy7'), 'money'),
                    $this->c($this->num($a, 'vydaje7'), 'money'),
                ]);
            }
            if ($activities !== []) {
                $tables[] = $this->table('Příloha č. 1: činnosti', null,
                    [['Činnost', 'left'], ['Měsíce', 'right'], ['Příjmy', 'right'], ['Výdaje', 'right']], $activities);
            }
        }

        $adjustments = [];
        foreach ($this->all($dom, 'VetaC') as $a) {
            $adjustments[] = $this->row([$this->c('zvýšení (ř. 105)'), $this->c((string) ($a['uprzvys_235'] ?? '')), $this->c($this->num($a, 'kc_uprzvys_235'), 'money')]);
        }
        foreach ($this->all($dom, 'VetaE') as $a) {
            $adjustments[] = $this->row([$this->c('snížení (ř. 106)'), $this->c((string) ($a['uprsniz_235'] ?? '')), $this->c($this->num($a, 'kc_uprsniz_235'), 'money')]);
        }
        if ($adjustments !== []) {
            $tables[] = $this->table('Příloha č. 1, oddíl E: úpravy dílčího základu podle § 23', null,
                [['Směr', 'left'], ['Důvod', 'left'], ['Kč', 'right']], $adjustments);
        }

        $u = $this->attrs($dom, 'VetaU');
        if ($u !== []) {
            $rows = [];
            foreach (self::FO_ASSET_ROWS as $code => $label) {
                $open = $this->num($u, 'kc_dpfmz' . $code);
                $close = $this->num($u, 'kc_z_dpfmz' . $code);
                if ($open === null && $close === null) {
                    continue;
                }
                $rows[] = $this->row([$this->c($label), $this->c($open, 'money'), $this->c($close, 'money')]);
            }
            if (isset($u['kc_dpfmz18'])) {
                $rows[] = $this->row([$this->c('Mzdy (úhrn za období)'), $this->c(null, 'money'), $this->c($this->num($u, 'kc_dpfmz18'), 'money')]);
            }
            $tables[] = $this->table('Příloha č. 1: majetek a dluhy (§ 7b)', null,
                [['Položka', 'left'], ['Na začátku', 'right'], ['Na konci', 'right']], $rows);
        }

        $vv = $this->attrs($dom, 'VetaV');
        if ($vv !== []) {
            $rows = [];
            $pairs = [
                ['kc_prij9', 'Příjmy z nájmu (§ 9)'], ['kc_vyd9', 'Výdaje k příjmům z nájmu'], ['kc_zd9p', 'Dílčí základ daně § 9'],
                ['kc_prij10', 'Ostatní příjmy (§ 10)'], ['kc_vyd10', 'Výdaje k ostatním příjmům'], ['kc_zd10p', 'Dílčí základ daně § 10'],
            ];
            foreach ($pairs as [$attr, $label]) {
                if (isset($vv[$attr])) {
                    $rows[] = $this->row([$this->c($label), $this->c($this->num($vv, $attr), 'money')], str_starts_with($attr, 'kc_zd'));
                }
            }
            $tables[] = $this->table('Příloha č. 2: příjmy z nájmu a ostatní příjmy', null,
                [['Položka', 'left'], ['Kč', 'right']], $rows);

            $items = [];
            foreach ($this->all($dom, 'VetaJ') as $j) {
                $items[] = $this->row([
                    $this->c(trim(($j['kod_dr_prij10'] ?? '') . ' ' . ($j['druh_prij10'] ?? ''))),
                    $this->c((string) ($j['kod10'] ?? '')),
                    $this->c($this->num($j, 'prijmy10'), 'money'),
                    $this->c($this->num($j, 'vydaje10'), 'money'),
                    $this->c($this->num($j, 'rozdil10'), 'money'),
                ]);
            }
            if ($items !== []) {
                $tables[] = $this->table('Příloha č. 2: druhy ostatních příjmů (§ 10)', null,
                    [['Druh příjmu', 'left'], ['Kód', 'left'], ['Příjmy', 'right'], ['Výdaje', 'right'], ['Rozdíl', 'right']], $items);
            }
        }

        $children = [];
        foreach ($this->all($dom, 'VetaA') as $a) {
            $children[] = $this->row([
                $this->c(trim(($a['vyzdite_jmeno'] ?? '') . ' ' . ($a['vyzdite_prijmeni'] ?? ''))),
                $this->c($this->months($a, '')),
                $this->c($this->months($a, '2')),
                $this->c($this->months($a, '3')),
            ]);
        }
        if ($children !== []) {
            $tables[] = $this->table('Vyživované děti (§ 35c)', 'Počet měsíců nároku podle pořadí dítěte, v závorce měsíce se ZTP/P.',
                [['Dítě', 'left'], ['1. dítě', 'right'], ['2. dítě', 'right'], ['3. a další', 'right']], $children);
        }
        if (!empty($d['manz_prijmeni']) || !empty($d['manz_jmeno'])) {
            $tables[] = $this->table('Manžel / manželka (§ 35ba odst. 1 písm. b)', null,
                [['Jméno', 'left'], ['Měsíce', 'right'], ['Sleva', 'right']],
                [$this->row([
                    $this->c(trim(($d['manz_jmeno'] ?? '') . ' ' . ($d['manz_prijmeni'] ?? ''))),
                    $this->c(isset($d['m_manz']) ? (int) $d['m_manz'] : null, 'int'),
                    $this->c($this->num($d, 'kc_op15_1c'), 'money'),
                ])]);
        }

        return $tables;
    }

    /** @param array<string,string> $a */
    private function months(array $a, string $order): string
    {
        $plain = (int) ($a['vyzdite_pocmes' . $order] ?? 0);
        $ztpp = (int) ($a['vyzdite_ztpp' . $order] ?? 0);
        if ($plain === 0 && $ztpp === 0) {
            return '';
        }
        return $plain . ($ztpp > 0 ? ' (' . $ztpp . ')' : '');
    }

    // ── Společné bloky ────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function header(\DOMDocument $dom, array $source, \DateTimeImmutable $now, string $xml): array
    {
        $type = (string) $source['type'];
        $d = $this->attrs($dom, 'VetaD');
        $p = $this->attrs($dom, 'VetaP');
        $supplier = (array) ($source['supplier'] ?? []);

        $name = $type === 'po'
            ? (string) ($p['zkrobchjm'] ?? '')
            : trim(($p['jmeno'] ?? '') . ' ' . ($p['prijmeni'] ?? ''));
        if ($name === '') {
            $name = (string) ($supplier['name'] ?? '');
        }
        // U DPFO nese rod_c rodné číslo, do sestavy ho netiskneme; IČO je v evidenci firmy.
        $ic = $type === 'po' ? (string) ($p['rod_c'] ?? '') : '';
        if ($ic === '') {
            $ic = (string) ($supplier['ic'] ?? '');
        }
        $dic = (string) ($p['dic'] ?? '');
        if ($dic !== '' && ctype_digit($dic)) {
            $dic = 'CZ' . $dic;
        }
        if ($dic === '') {
            $dic = (string) ($supplier['dic'] ?? '');
        }

        $street = trim((string) ($p['ulice'] ?? ''));
        $number = trim((string) ($p['c_pop'] ?? '') . (isset($p['c_orient']) && $p['c_orient'] !== '' ? '/' . $p['c_orient'] : ''));
        $line1 = trim($street . ' ' . $number);
        $line2 = trim(trim((string) ($p['psc'] ?? '')) . ' ' . trim((string) ($p['naz_obce'] ?? '')));
        $address = implode(', ', array_filter([$line1, $line2], static fn (string $s): bool => $s !== ''));

        $forma = (string) ($type === 'po' ? ($d['dapdpp_forma'] ?? 'B') : ($d['dap_typ'] ?? 'B'));
        $variantLabel = self::FORMA_LABELS[$forma] ?? 'řádné přiznání';
        if ((string) ($source['variant'] ?? '') === 'dodatecne' && (int) ($source['variant_seq'] ?? 1) > 1) {
            $variantLabel .= ' č. ' . (int) $source['variant_seq'];
        }

        $scope = null;
        if ($type === 'po') {
            if (isset($d['uv_rozsah_rozv'])) {
                $scope = 'rozvaha ' . (self::SCOPE_LABELS[$d['uv_rozsah_rozv']] ?? $d['uv_rozsah_rozv'])
                    . ', výkaz zisku a ztráty ' . (self::SCOPE_LABELS[$d['uv_rozsah_vzz'] ?? 'P'] ?? 'v plném rozsahu');
                if (isset($d['kat_uj'])) {
                    $scope .= '; ' . (self::CATEGORY_LABELS[$d['kat_uj']] ?? $d['kat_uj']) . ' účetní jednotka';
                }
            } else {
                $scope = 'bez přílohy účetní závěrky';
            }
        }

        $snapshot = is_array($source['snapshot'] ?? null) ? $source['snapshot'] : null;
        $isFinal = ($source['status'] ?? 'draft') === 'final';

        return [
            'name' => $name,
            'ic' => $ic,
            'dic' => $dic,
            'address' => $address,
            'period_from' => $this->czDate($d['zdobd_od'] ?? ''),
            'period_to' => $this->czDate($d['zdobd_do'] ?? ''),
            'variant_label' => $variantLabel,
            'status_label' => $isFinal ? 'uzamčené (finální)' : 'rozpracované',
            'is_final' => $isFinal,
            'finalized_at' => $isFinal && !empty($source['finalized_at'])
                ? (new \DateTimeImmutable((string) $source['finalized_at']))->format('d.m.Y H:i')
                : null,
            'revision_no' => $snapshot['revision_no'] ?? null,
            'generated_at' => $now->format('d.m.Y H:i'),
            'scope' => $scope,
            'xml_sha256' => hash('sha256', $xml),
        ];
    }

    /**
     * @param array<string,mixed> $next výstup kalkulátoru `next_advances`
     * @return array<string,mixed>|null
     */
    private function advances(float $lastKnownTax, array $next): ?array
    {
        if ($next === []) {
            return null;
        }
        $regime = (string) ($next['regime'] ?? 'none');
        $label = match ($regime) {
            'quarterly' => 'čtvrtletní zálohy',
            'semiannual' => 'pololetní zálohy',
            default => 'zálohy se neplatí',
        };
        return [
            'last_known_tax' => round($lastKnownTax, 2),
            'regime' => $regime,
            'regime_label' => $label,
            'count' => (int) ($next['count'] ?? 0),
            'amount' => round((float) ($next['amount'] ?? 0), 2),
            'note' => (string) ($next['note'] ?? ''),
            'filing_deadline' => (string) ($next['filing_deadline'] ?? ''),
        ];
    }

    /** @return array{label:string,value:float,tone:string} */
    private function balance(float $amount, string $dueLabel, string $refundLabel): array
    {
        $amount = round($amount, 2);
        if ($amount > 0) {
            return ['label' => $dueLabel, 'value' => $amount, 'tone' => 'due'];
        }
        if ($amount < 0) {
            return ['label' => $refundLabel, 'value' => -$amount, 'tone' => 'refund'];
        }
        return ['label' => 'Bez doplatku a přeplatku', 'value' => 0.0, 'tone' => 'zero'];
    }

    /**
     * @param array<string,mixed> $card výstup {@see TaxLossService::card()}
     * @return array<string,mixed>|null
     */
    private function lossTable(array $card, float $appliedNow, string $appliedLine, float $yearLoss): ?array
    {
        $losses = (array) ($card['losses'] ?? []);
        if ($losses === [] && $appliedNow === 0.0 && $yearLoss === 0.0) {
            return null;
        }
        $rows = [];
        foreach ($losses as $l) {
            $rows[] = $this->row([
                $this->c((int) ($l['origin_year'] ?? 0), 'year'),
                $this->c((float) ($l['amount'] ?? 0), 'money'),
                $this->c((float) ($l['applied'] ?? 0), 'money'),
                $this->c((float) ($l['remaining'] ?? 0), 'money'),
                $this->c((int) ($l['expires_year'] ?? 0), 'year'),
            ]);
        }
        $note = 'V tomto přiznání uplatněno ' . $this->plainMoney($appliedNow) . ' Kč (' . $appliedLine . '). '
            . 'K uplatnění podle evidence zbývá ' . $this->plainMoney((float) ($card['available_total'] ?? 0)) . ' Kč.';
        if ($yearLoss > 0) {
            $note .= ' Za toto období vzniká daňová ztráta ' . $this->plainMoney($yearLoss) . ' Kč.';
        }
        return $this->table('Daňové ztráty (§ 34)', $note,
            [['Rok vzniku', 'left'], ['Ztráta', 'right'], ['Uplatněno', 'right'], ['Zbývá', 'right'], ['Lze uplatnit do', 'right']],
            $rows);
    }

    /**
     * Vstupy přiznání zadané uživatelem (v XML nejsou jako takové, jen jejich dopad).
     *
     * @param array<string,mixed> $inputs
     * @return list<array<string,mixed>>
     */
    private function inputTables(string $type, array $inputs): array
    {
        $scalar = [];
        $add = function (string $label, mixed $value, string $format) use (&$scalar): void {
            if ($value === null || $value === '' || $value === 0 || $value === 0.0) {
                return;
            }
            $scalar[] = $this->row([$this->c($label), $this->c($value, $format)]);
        };
        $money = static fn (mixed $v): ?float => is_numeric($v) ? round((float) $v, 2) : null;

        if ($type === 'po') {
            $add('Zaplacené zálohy na daň', $money($inputs['tax_paid_advances'] ?? null), 'money2');
            $add('Ztráta minulých let k uplatnění', $money($inputs['loss_carryforward'] ?? null), 'money2');
            $add('Dary (souhrnná částka)', $money($inputs['donations'] ?? null), 'money2');
            $add('Odečet na výzkum a vývoj', $money($inputs['rnd_deduction'] ?? null), 'money2');
            $add('Odečet na odborné vzdělávání', $money($inputs['education_deduction'] ?? null), 'money2');
            $add('Průměrný počet zaměstnanců se zdravotním postižením', $money($inputs['disabled_employees_avg'] ?? null), 'decimal');
            $add('z toho s těžším zdravotním postižením', $money($inputs['disabled_employees_severe_avg'] ?? null), 'decimal');
            $add('Sleva za zastavenou exekuci (§ 35 odst. 4)', $money($inputs['stopped_execution_credit'] ?? null), 'money2');
            $add('Lhůta pro podání', (string) ($inputs['filing_deadline'] ?? ''), 'date');
            if (array_key_exists('puz_to_registry', $inputs)) {
                $scalar[] = $this->row([$this->c('Předat přílohu účetní závěrky do sbírky listin'), $this->c(!empty($inputs['puz_to_registry']) ? 'ano' : 'ne')]);
            }
        } else {
            $s6 = (array) ($inputs['s6_employment'] ?? []);
            $add('Příjmy ze závislé činnosti (§ 6)', $money($s6['income'] ?? null), 'money2');
            $add('Sražené zálohy ze závislé činnosti', $money($s6['withholding'] ?? null), 'money2');
            $add('Dílčí základ z kapitálového majetku (§ 8)', $money(((array) ($inputs['s8_capital'] ?? []))['base'] ?? null), 'money2');
            $s9 = (array) ($inputs['s9_rental'] ?? []);
            $add('Příjmy z nájmu (§ 9)', $money($s9['income'] ?? null), 'money2');
            $add('Výdaje k nájmu (§ 9)', $money($s9['expenses'] ?? null), 'money2');
            if ($money($s9['income'] ?? null)) {
                $scalar[] = $this->row([$this->c('Výdaje k nájmu uplatněny'), $this->c(($s9['expense_mode'] ?? '') === 'pausal' ? 'procentem z příjmů' : 've skutečné výši')]);
            }
            $add('Samostatný základ daně (§ 16a)', $money($inputs['s16a_separate_base'] ?? null), 'money2');
            $add('Mzdy (ruční údaj pro Přílohu č. 1)', $money($inputs['s7_payroll_gross'] ?? null), 'money2');
            $add('Ztráta minulých let k uplatnění', $money($inputs['loss_carryforward'] ?? null), 'money2');
            $add('Zaplacené zálohy na daň', $money($inputs['tax_paid_advances'] ?? null), 'money2');
            $add('Zaplacené zálohy na sociální pojištění', $money($inputs['social_paid_advances'] ?? null), 'money2');
            $add('Zaplacené zálohy na zdravotní pojištění', $money($inputs['health_paid_advances'] ?? null), 'money2');
        }
        $add('Poslední známá daň (zadaná ručně)', $money($inputs['last_known_tax'] ?? null), 'money2');
        $add('Datum zjištění důvodů dodatečného přiznání', (string) ($inputs['d_zjist'] ?? ''), 'date');
        $add('Důvody opravného / dodatečného přiznání', trim((string) ($inputs['amend_reason'] ?? '')), 'text');
        $add('Poznámka', trim((string) ($inputs['notes'] ?? '')), 'text');

        $tables = [];
        if ($scalar !== []) {
            $tables[] = $this->table('Vstupy přiznání zadané uživatelem', null, [['Údaj', 'left'], ['Hodnota', 'right']], $scalar);
        }

        $itemLists = [
            'manual_increase_items' => 'Ruční položky zvyšující základ daně (§ 23, § 25)',
            'manual_decrease_items' => 'Ruční položky snižující základ daně (§ 23)',
            'donation_items' => 'Dary (§ 20 odst. 8, § 15)',
        ];
        foreach ($itemLists as $key => $title) {
            $rows = [];
            foreach ((array) ($inputs[$key] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $text = trim((string) ($item['text'] ?? ''));
                if (($item['kind'] ?? '') === DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL) {
                    $text .= ' (paušální výdaj na dopravu)';
                }
                $rows[] = $this->row([$this->c($text), $this->c($money($item['amount'] ?? 0), 'money2')]);
            }
            if ($rows !== []) {
                $tables[] = $this->table($title, null, [['Popis', 'left'], ['Kč', 'right']], $rows);
            }
        }

        if ($type === 'fo') {
            $rows = [];
            foreach ((array) ($inputs['s10_items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $rows[] = $this->row([
                    $this->c(trim(($item['kind_code'] ?? '') . ' ' . ($item['text'] ?? ''))),
                    $this->c($money($item['income'] ?? 0), 'money2'),
                    $this->c($money($item['expenses'] ?? 0), 'money2'),
                ]);
            }
            if ($rows !== []) {
                $tables[] = $this->table('Ostatní příjmy § 10 zadané uživatelem', null,
                    [['Druh příjmu', 'left'], ['Příjmy', 'right'], ['Výdaje', 'right']], $rows);
            }
        }

        return $tables;
    }

    // ── Pomocné ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function lineRow(string $code, string $label, string $note, float $value, string $format, bool $key): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'note' => $note,
            'value' => $value,
            'format' => $format,
            'key' => $key,
            'nonzero' => $value !== 0.0,
        ];
    }

    /** @return array{label:string,ref:string,value:mixed,style:string,format:string} */
    private function sum(string $label, string $ref, mixed $value, string $style = '', string $format = 'money'): array
    {
        return ['label' => $label, 'ref' => $ref, 'value' => $value, 'style' => $style, 'format' => $format];
    }

    /** @return array{value:mixed,format:string} */
    private function c(mixed $value, string $format = 'text'): array
    {
        return ['value' => $value, 'format' => $format];
    }

    /**
     * @param list<array{value:mixed,format:string}> $cells
     * @return array{cells:list<array{value:mixed,format:string}>,strong:bool}
     */
    private function row(array $cells, bool $strong = false): array
    {
        return ['cells' => $cells, 'strong' => $strong];
    }

    /**
     * @param list<array{0:string,1:string}> $columns popisek a zarovnání
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function table(string $title, ?string $note, array $columns, array $rows): array
    {
        return [
            'title' => $title,
            'note' => $note,
            'columns' => array_map(static fn (array $c): array => ['label' => $c[0], 'align' => $c[1]], $columns),
            'rows' => $rows,
        ];
    }

    private function load(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        $ok = trim($xml) !== '' && $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new TaxReturnException('invalid_xml', 'XML přiznání se nepodařilo přečíst, sestavu nelze vytvořit.', 500);
        }
        return $dom;
    }

    /** @return array<string,string> atributy prvního výskytu věty */
    private function attrs(\DOMDocument $dom, string $tag): array
    {
        $el = $dom->getElementsByTagName($tag)->item(0);
        return $el instanceof \DOMElement ? $this->elementAttrs($el) : [];
    }

    /** @return list<array<string,string>> */
    private function all(\DOMDocument $dom, string $tag): array
    {
        $out = [];
        foreach ($dom->getElementsByTagName($tag) as $el) {
            if ($el instanceof \DOMElement) {
                $out[] = $this->elementAttrs($el);
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private function elementAttrs(\DOMElement $el): array
    {
        $out = [];
        foreach ($el->attributes as $attr) {
            $out[$attr->nodeName] = (string) $attr->nodeValue;
        }
        return $out;
    }

    /** @param array<string,string> $attrs */
    private function num(array $attrs, string $name): ?float
    {
        $raw = trim((string) ($attrs[$name] ?? ''));
        return $raw !== '' && is_numeric($raw) ? (float) $raw : null;
    }

    private function czDate(string $v): string
    {
        $d = \DateTimeImmutable::createFromFormat('!j.n.Y', trim($v));
        return $d === false ? trim($v) : $d->format('d.m.Y');
    }

    private function isoDate(string $v): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!j.n.Y', trim($v));
        return $d === false ? null : $d->format('Y-m-d');
    }

    private function plainMoney(float $v): string
    {
        return number_format($v, 0, ',', ' ');
    }

    private function displayCode(?string $rowCode, int $cRadku): string
    {
        if ($rowCode === null) {
            return $cRadku === 24 ? 'B.+C.' : '';
        }
        if (in_array($rowCode, ['AKTIVA', 'PASIVA'], true) || in_array($rowCode, self::VZZ_CALC_ROWS, true)) {
            return '';
        }
        if (str_starts_with($rowCode, 'P.')) {
            return substr($rowCode, 2);
        }
        return $rowCode === 'I.n' ? 'I.' : $rowCode;
    }

    private function fallbackLabel(?string $rowCode, int $cRadku): string
    {
        return match (true) {
            $rowCode === null && $cRadku === 24 => 'Cizí zdroje',
            $rowCode === 'AKTIVA' => 'AKTIVA CELKEM',
            $rowCode === 'PASIVA' => 'PASIVA CELKEM',
            $rowCode === 'PVH' => 'Provozní výsledek hospodaření',
            $rowCode === 'FVH' => 'Finanční výsledek hospodaření',
            $rowCode === 'VHPZ' => 'Výsledek hospodaření před zdaněním',
            $rowCode === 'VHPO' => 'Výsledek hospodaření po zdanění',
            $rowCode === 'VH' => 'Výsledek hospodaření za účetní období',
            $rowCode === 'OBRAT' => 'Čistý obrat za účetní období',
            default => 'Řádek ' . $cRadku,
        };
    }

    private function levelFromCode(string $rowCode): int
    {
        if ($rowCode === '' || in_array($rowCode, ['AKTIVA', 'PASIVA'], true)) {
            return 0;
        }
        if (in_array($rowCode, self::VZZ_CALC_ROWS, true)) {
            return 0;
        }
        $code = str_starts_with($rowCode, 'P.') ? substr($rowCode, 2) : $rowCode;
        return max(1, substr_count(rtrim($code, '.'), '.') + 1);
    }
}
