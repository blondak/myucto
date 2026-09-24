<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

/**
 * Přiznání k DPH (DPHDP3) a kontrolní hlášení (DPHKH1) ve formátu EPO → částky
 * po řádcích, porovnatelné mezi programy.
 *
 * Formát EPO je jediný výstup, který mají všechny účetní programy stejný, proto se
 * přiznání MyÚčta i starého programu čtou touž třídou: MyÚčto své XML sestaví
 * ({@see \MyInvoice\Service\Report\DphPriznaniBuilder}), starý program ho vyexportuje.
 *
 *   - DPHDP3: číselné atributy vět Veta1–Veta6 (`Veta1.obrat23` => 125000).
 *   - DPHKH1: souhrn VetaC po atributech a řádky oddílů A.1–A.5 a B.1–B.3; řádek s dokladem
 *     má klíč oddíl + DIČ partnera + evidenční číslo dokladu a částku = součet základů
 *     a daní (zakl_dane1..3, dan1..3), agregované A.5 a B.3 mají klíč jen podle oddílu.
 *     Pořadí řádků v souboru nehraje roli — programy je řadí různě.
 */
final class EpoVatFilingReader
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    /** Atributy, které nejsou částky (koeficient v %, kódy). */
    private const NON_AMOUNT = ['koef_p20_nov', 'koef_p20_vypor', 'kod_rezim_pl', 'zdph_44', 'pomer', 'kod_pred_pl', 'c_radku', 'c_evid_dd', 'k_stat'];

    /** Atribut přiznání k DPH => číslo řádku formuláře (jen pro popis rozdílu). */
    private const DPH_LINES = [
        'obrat23' => '1', 'dan23' => '1', 'obrat5' => '2', 'dan5' => '2',
        'p_zb23' => '3', 'dan_pzb23' => '3', 'p_zb5' => '4', 'dan_pzb5' => '4',
        'p_sl23_e' => '5', 'dan_psl23_e' => '5', 'p_sl5_e' => '6', 'dan_psl5_e' => '6',
        'dov_zb23' => '7', 'dan_dzb23' => '7', 'dov_zb5' => '8', 'dan_dzb5' => '8',
        'p_dop_nrg' => '9', 'dan_pdop_nrg' => '9',
        'rez_pren23' => '10', 'dan_rpren23' => '10', 'rez_pren5' => '11', 'dan_rpren5' => '11',
        'p_sl23_z' => '12', 'dan_psl23_z' => '12', 'p_sl5_z' => '13', 'dan_psl5_z' => '13',
        'dod_zb' => '20', 'pln_sluzby' => '21', 'pln_vyvoz' => '22', 'dod_dop_nrg' => '23',
        'pln_zaslani' => '24', 'pln_rez_pren' => '25', 'pln_ost' => '26',
        'tri_pozb' => '30', 'tri_dozb' => '31', 'dov_osv' => '32', 'opr_verit' => '33', 'opr_dluz' => '34',
        'pln23' => '40', 'odp_tuz23_nar' => '40', 'odp_tuz23' => '40',
        'pln5' => '41', 'odp_tuz5_nar' => '41', 'odp_tuz5' => '41',
        'dov_cu' => '42', 'odp_cu_nar' => '42', 'odp_cu' => '42',
        'nar_zdp23' => '43', 'od_zdp23' => '43', 'odkr_zdp23' => '43',
        'nar_zdp5' => '44', 'od_zdp5' => '44', 'odkr_zdp5' => '44',
        'odp_rezim' => '45', 'odp_sum_nar' => '46', 'odp_sum_kr' => '46', 'nar_maj' => '47', 'od_maj' => '47',
        'plnosv_kf' => '50', 'pln_nkf' => '51', 'plnosv_nkf' => '51',
        'odp_uprav_kf' => '52', 'vypor_odp' => '53', 'kf_zboz' => '54',
        'dan_zocelk' => '62', 'odp_zocelk' => '63', 'dano_da' => '64', 'dano_no' => '65', 'dano' => '66',
    ];

    /**
     * @return array{form:string,period:array{year:?int,month:?int,quarter:?int},values:array<string,float>,rows:array<string,array{section:string,partner:string,document:string,amount:float}>}
     * @throws ParallelRunException soubor není přiznání k DPH ani kontrolní hlášení
     */
    public function read(string $xml, string $expectedForm): array
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES) {
            throw new ParallelRunException('input_invalid_xml', 'Soubor není čitelné XML podání.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (!$ok) {
            throw new ParallelRunException('input_invalid_xml', 'Soubor není čitelné XML podání.');
        }
        $form = null;
        foreach ($dom->getElementsByTagName('*') as $node) {
            $name = strtolower((string) $node->localName);
            if ($name === 'dphdp3' || $name === 'dphkh1') {
                $form = $node;
                break;
            }
        }
        $formName = $form === null ? null : strtolower((string) $form->localName);
        if ($form === null || $formName !== $expectedForm) {
            throw new ParallelRunException('input_wrong_form', $expectedForm === 'dphdp3'
                ? 'Soubor není přiznání k DPH (DPHDP3).'
                : 'Soubor není kontrolní hlášení (DPHKH1).', ['expected' => $expectedForm, 'found' => $formName]);
        }

        $period = ['year' => null, 'month' => null, 'quarter' => null];
        $values = [];
        $rows = [];
        foreach ($form->childNodes as $veta) {
            if (!$veta instanceof \DOMElement) {
                continue;
            }
            $name = (string) $veta->localName;
            if ($name === 'VetaD') {
                $period = [
                    'year' => self::intAttr($veta, 'rok'),
                    'month' => self::intAttr($veta, 'mesic'),
                    'quarter' => self::intAttr($veta, 'ctvrt'),
                ];
                continue;
            }
            if ($formName === 'dphdp3') {
                if (preg_match('/^Veta[1-6]$/', $name) === 1) {
                    foreach (self::amounts($veta) as $attr => $amount) {
                        $values[$name . '.' . $attr] = round(($values[$name . '.' . $attr] ?? 0.0) + $amount, 2);
                    }
                }
                continue;
            }
            if ($name === 'VetaC') {
                foreach (self::amounts($veta) as $attr => $amount) {
                    $values['VetaC.' . $attr] = round(($values['VetaC.' . $attr] ?? 0.0) + $amount, 2);
                }
                continue;
            }
            if (preg_match('/^Veta([AB][1-5])$/', $name, $m) !== 1) {
                continue;
            }
            $section = $m[1];
            $amount = 0.0;
            foreach (self::amounts($veta) as $attr => $v) {
                if (preg_match('/^(zakl_dane|dan)[123]$/', $attr) === 1) {
                    $amount += $v;
                }
            }
            $partner = self::partner($veta);
            $document = trim($veta->getAttribute('c_evid_dd'));
            $key = in_array($section, ['A5', 'B3'], true) ? $section : $section . '|' . $partner . '|' . self::docKey($document);
            if (!isset($rows[$key])) {
                $rows[$key] = ['section' => $section, 'partner' => $partner, 'document' => $document, 'amount' => 0.0];
            }
            $rows[$key]['amount'] = round($rows[$key]['amount'] + $amount, 2);
        }
        ksort($values, SORT_STRING);
        ksort($rows, SORT_STRING);

        return ['form' => $formName, 'period' => $period, 'values' => $values, 'rows' => $rows];
    }

    /** Číslo řádku formuláře DPHDP3 k atributu (`Veta4.pln23` → „40"), null = neznámý. */
    public static function dphLine(string $key): ?string
    {
        $attr = substr($key, (int) strpos($key, '.') + 1);
        return self::DPH_LINES[$attr] ?? null;
    }

    /** Evidenční číslo dokladu pro párování: bez mezer a velikosti písmen. */
    public static function docKey(string $document): string
    {
        return strtoupper(preg_replace('/\s+/', '', $document) ?? $document);
    }

    /** @return array<string,float> */
    private static function amounts(\DOMElement $veta): array
    {
        $out = [];
        foreach ($veta->attributes ?? [] as $attr) {
            if (!$attr instanceof \DOMAttr) {
                continue;
            }
            $name = (string) $attr->localName;
            if (in_array($name, self::NON_AMOUNT, true) || str_starts_with($name, 'dic') || str_starts_with($name, 'vatid') || str_starts_with($name, 'd') && preg_match('/^(duzp|dppd|dat)/', $name) === 1) {
                continue;
            }
            $value = trim($attr->value);
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
                continue;
            }
            $out[$name] = (float) $value;
        }
        return $out;
    }

    private static function partner(\DOMElement $veta): string
    {
        foreach (['dic_odb', 'dic_dod', 'vatid_dod', 'vatid_odb'] as $attr) {
            $v = trim($veta->getAttribute($attr));
            if ($v !== '') {
                return strtoupper(preg_replace('/\s+/', '', $v) ?? $v);
            }
        }
        return '';
    }

    private static function intAttr(\DOMElement $el, string $name): ?int
    {
        $v = trim($el->getAttribute($name));
        return $v === '' || !ctype_digit($v) ? null : (int) $v;
    }
}
