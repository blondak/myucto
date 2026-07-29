<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Parser podaného EPO XML DPPDP9 (daň z příjmů PO) — Featura A („Rekonciliace proti
 * PODANÉMU přiznání", `private/REAL_data_followup_UX.md` §A). Čte VetaD/VetaP/VetaO
 * atributy z reálně podaného souboru (staženého z EPO/portálu Moje daně), NE náš export.
 *
 * **Robustnost napříč verzemi formuláře:** různé `verzePis` (04.01, 05.01, 09.01, …) mění
 * detailní strukturu formuláře (přibývají/mizí řádky), ale atributy `kc_ii{starý}_{aktuální}`
 * jsou stabilní napříč verzemi — druhé číslo (aktuální řádek) odpovídá stejnému číslu řádku
 * formuláře bez ohledu na verzi. Parser proto čte podle NÁZVU atributu (reverzní mapa
 * {@see DppoXmlBuilder::LINE_ATTR}), ne podle pozice/pořadí — neznámé atributy (řádky
 * podrobnějšího rozpisu, které náš zjednodušený kalkulátor nepočítá) se nezahodí, jen
 * skončí v `extra` (informativní, bez diffu).
 *
 * Bezpečnost uploadu: DOMDocument s `resolveExternals=false`, `substituteEntities=false`,
 * `LIBXML_NONET` — žádné externí entity/DTD ze souboru nahraného uživatelem (XXE).
 */
final class DppoEpoXmlParser
{
    /** Rozpoznané „vedlejší" atributy VetaO mimo naši sadu řádků — jen popisky pro drill-down. */
    private const EXTRA_LABELS = [
        'kc_ii_220' => 'Základ daně před odečtem ztráty a darů (ř. 220)',
        'kc_ii270_280' => 'Sazba daně v % (ř. 280)',
        'kc_ii320_330' => 'Daň dle sazby na ř. 220 (ř. 330, kontrolní vazba na ř. 290)',
        'kc_ii80_70' => 'Souhrn částek zvyšujících výsledek hospodaření (ř. 70)',
    ];

    /**
     * @return array{
     *   form_code:string, dokument:string, verze_pis:string, dapdpp_forma:string,
     *   typ_zo:string, zdobd_od:?string, zdobd_do:?string,
     *   supplier: array{ic:string,dic:string,name:string},
     *   lines: array<int,float>, extra: array<string,array{value:float,label:string}>,
     *   rate_pct: ?float, amendment: array{kc_dppiv1:?float,kc_dppiv2:?float,kc_dppiv3:?float,d_zjist:string},
     * }
     */
    public function parse(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            throw new TaxReturnException('invalid_xml', 'Nahraný soubor je prázdný.', 400);
        }

        $dom = new \DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        if (!$ok) {
            $err = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            $detail = $err !== [] ? trim($err[0]->message) : '';
            throw new TaxReturnException('invalid_xml', 'Soubor není platné XML' . ($detail !== '' ? " ({$detail})" : '') . '.', 400);
        }
        libxml_use_internal_errors(false);

        $root = $dom->getElementsByTagName('DPPDP9')->item(0);
        if ($root === null) {
            throw new TaxReturnException('wrong_form', 'Nahraný soubor není přiznání DPPDP9 (daň z příjmů právnických osob) — element <DPPDP9> nenalezen.', 400);
        }
        $verzaPis = trim($root->getAttribute('verzePis'));

        $vetaD = $dom->getElementsByTagName('VetaD')->item(0);
        if ($vetaD === null) {
            throw new TaxReturnException('wrong_form', 'V XML chybí věta VetaD (hlavička přiznání).', 400);
        }
        $dokument = trim($vetaD->getAttribute('dokument'));
        if ($dokument !== '' && $dokument !== 'DP9') {
            throw new TaxReturnException('wrong_form', "Nahraný soubor je jiný typ podání (dokument=\"{$dokument}\"), ne DPPDP9.", 400);
        }
        $forma = trim($vetaD->getAttribute('dapdpp_forma')) ?: 'B';
        $typZo = trim($vetaD->getAttribute('typ_zo')) ?: 'A';
        $zdobdOd = $this->isoDate($vetaD->getAttribute('zdobd_od'));
        $zdobdDo = $this->isoDate($vetaD->getAttribute('zdobd_do'));

        $vetaP = $dom->getElementsByTagName('VetaP')->item(0);
        $supplier = [
            'ic' => $vetaP !== null ? trim($vetaP->getAttribute('rod_c')) : '',
            'dic' => $vetaP !== null ? trim($vetaP->getAttribute('dic')) : '',
            'name' => $vetaP !== null ? trim($vetaP->getAttribute('zkrobchjm')) : '',
        ];

        $vetaO = $dom->getElementsByTagName('VetaO')->item(0);
        if ($vetaO === null) {
            throw new TaxReturnException('wrong_form', 'V XML chybí věta VetaO (řádky II. oddílu — vlastní výpočet daně).', 400);
        }

        $reverse = $this->reverseLineAttr();
        $lines = [];
        $extra = [];
        /** @var \DOMAttr $attr */
        foreach ($vetaO->attributes as $attr) {
            $name = $attr->nodeName;
            $raw = trim($attr->nodeValue ?? '');
            if ($raw === '' || !is_numeric($raw)) {
                continue; // datumy (d_hospvysl) a jiné netextové atributy nás nezajímají
            }
            $value = (float) $raw;
            if (isset($reverse[$name])) {
                $lines[$reverse[$name]] = $value;
                continue;
            }
            if ($name === 'kc_ii270_280') {
                // sazba v % — zvlášť, ne řádek Kč
                continue;
            }
            $extra[$name] = ['value' => $value, 'label' => self::EXTRA_LABELS[$name] ?? $name];
        }

        $ratePct = $vetaO->hasAttribute('kc_ii270_280') && is_numeric($vetaO->getAttribute('kc_ii270_280'))
            ? (float) $vetaO->getAttribute('kc_ii270_280')
            : null;

        $amendment = ['kc_dppiv1' => null, 'kc_dppiv2' => null, 'kc_dppiv3' => null, 'd_zjist' => ''];
        if (in_array($forma, ['D', 'E'], true)) {
            foreach (['kc_dppiv1', 'kc_dppiv2', 'kc_dppiv3'] as $k) {
                if ($vetaD->hasAttribute($k) && is_numeric($vetaD->getAttribute($k))) {
                    $amendment[$k] = (float) $vetaD->getAttribute($k);
                }
            }
            $amendment['d_zjist'] = $this->isoDate($vetaD->getAttribute('d_zjist')) ?? '';
        }

        return [
            'form_code' => 'dppdp9',
            'dokument' => $dokument,
            'verze_pis' => $verzaPis,
            'dapdpp_forma' => $forma,
            'typ_zo' => $typZo,
            'zdobd_od' => $zdobdOd,
            'zdobd_do' => $zdobdDo,
            'supplier' => $supplier,
            'lines' => $lines,
            'extra' => $extra,
            'rate_pct' => $ratePct,
            'amendment' => $amendment,
        ];
    }

    /** @return array<string,int> atribut VetaO → číslo řádku (reverzní {@see DppoXmlBuilder::LINE_ATTR}) */
    private function reverseLineAttr(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (DppoXmlBuilder::LINE_ATTR as $line => $attr) {
                $map[$attr] = $line;
            }
        }
        return $map;
    }

    /** dd.mm.rrrr (EPO dateInMultiFormat, i bez vedoucích nul) → ISO YYYY-MM-DD; neplatné/prázdné → null. */
    private function isoDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m) !== 1) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!j.n.Y', $v);
        return $d === false ? null : $d->format('Y-m-d');
    }
}
