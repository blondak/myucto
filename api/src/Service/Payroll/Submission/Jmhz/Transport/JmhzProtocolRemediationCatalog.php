<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlId;

/**
 * Co s chybou z protokolu ČSSZ udělat: náprava ke kontrolám, se kterými se
 * účetní potkávají nejčastěji.
 *
 * Katalog kontrol říká, CO pravidlo hlídá; jak z chyby ven, píše ČSSZ jinde.
 * Tady je to doložené ze dvou zdrojů:
 *
 *  - FAQ ČSSZ „Nejčastější dotazy při podávání JMHZ" z 9. 6. 2026
 *    (kontroly 219, 238, 242, 243, 244, 245, 325, 326),
 *  - Katalog kontrol MH 1.4.2.10, sloupec poznámek u kontrol 262 a 263
 *    (post DIS validace, kódy 103901608 a 103901609).
 *
 * Klíčem je ID KONTROLY, ne číslo z protokolu. FAQ píše „Chyba 238", ale
 * jde o ID kontroly z katalogu: kontroly 219 až 326 dělá cJMHZ, takže
 * v protokolu chodí jako 40000 + ID (skutečné protokoly vrátily 40243, 40244
 * a 40326). Kdyby je ČSSZ přesunula na DIS (20000 + ID), náprava platí dál,
 * protože ID kontroly se z obou rozsahů spočítá stejně
 * ({@see JmhzProtocolError::fromCode()}). Holé „238" protokol nenese — parser
 * by takový kód odmítl jako nedoložený, ne ho tiše vyložil jinak.
 *
 * Kontrola 219 už v katalogu 1.4.2.10 není (zrušena v 1.4.2.1), FAQ ji ale
 * dál uvádí a starší protokoly ji nesou. Náprava proto nesmí záviset na tom,
 * zda kontrolu zná připnutý katalog.
 *
 * Druh nápravy (místo v aplikaci) drží {@see JmhzBlockerCatalog} jako u všech
 * ostatních kódů, text vysvětlení a kroky jsou ve
 * `web/src/i18n/{cs,en}.json` pod `payroll.jmhz_protocol_help`.
 */
final class JmhzProtocolRemediationCatalog
{
    public const SOURCE_FAQ = 'cssz_faq_2026_06_09';
    public const SOURCE_CONTROL_CATALOG = 'cssz_control_catalog_1_4_2_10';

    /** @var list<string> */
    public const SOURCES = [self::SOURCE_FAQ, self::SOURCE_CONTROL_CATALOG];

    /**
     * Atributy, které u nerezidenta s prohlášením nesmí mít žádnou hodnotu,
     * ani nulu (FAQ ČSSZ 9. 6. 2026, chyba 243, bod D).
     *
     * @var list<string>
     */
    public const NONRESIDENT_EMPTY_ATTRIBUTE_IDS = [
        '10300', '10301', '10302', '10303', '10453', '10431', '10432', '10433',
        '10434', '10435', '10436', '10437', '10438', '10439', '10440', '10304',
        '10306', '10307', '10309', '10310',
    ];

    /**
     * ID kontroly => [kód nápravy, zdroj].
     *
     * @var array<int,array{0:string,1:string}>
     */
    private const BY_CONTROL = [
        219 => ['jmhz_protocol_correction_guid_invalid', self::SOURCE_FAQ],
        238 => ['jmhz_protocol_correction_form_unpaired', self::SOURCE_FAQ],
        242 => ['jmhz_protocol_tax_withholding_with_declaration', self::SOURCE_FAQ],
        243 => ['jmhz_protocol_tax_nonresident_with_declaration', self::SOURCE_FAQ],
        244 => ['jmhz_protocol_tax_reliefs_without_declaration', self::SOURCE_FAQ],
        245 => ['jmhz_protocol_tax_advance_with_withholding', self::SOURCE_FAQ],
        262 => ['jmhz_protocol_employment_not_found_at_cssz', self::SOURCE_CONTROL_CATALOG],
        263 => ['jmhz_protocol_person_not_found_at_cssz', self::SOURCE_CONTROL_CATALOG],
        325 => ['jmhz_protocol_tax_withholding_with_advance', self::SOURCE_FAQ],
        326 => ['jmhz_protocol_regular_submission_duplicate', self::SOURCE_FAQ],
    ];

    /** @var list<string> */
    public const CODES = [
        'jmhz_protocol_correction_guid_invalid',
        'jmhz_protocol_correction_form_unpaired',
        'jmhz_protocol_tax_withholding_with_declaration',
        'jmhz_protocol_tax_nonresident_with_declaration',
        'jmhz_protocol_tax_reliefs_without_declaration',
        'jmhz_protocol_tax_advance_with_withholding',
        'jmhz_protocol_employment_not_found_at_cssz',
        'jmhz_protocol_person_not_found_at_cssz',
        'jmhz_protocol_tax_withholding_with_advance',
        'jmhz_protocol_regular_submission_duplicate',
    ];

    /**
     * Náprava ke kontrole, nebo `null`, když ji neznáme. Neznámá kontrola
     * není chyba (viz {@see JmhzProtocolExplainer}): uživateli zůstane hláška
     * z protokolu a katalogový popis.
     *
     * @return array{code:string,kind:string,field:?string,source:string,empty_attribute_ids:list<string>}|null
     */
    public static function forControl(JmhzControlId $controlId): ?array
    {
        $entry = self::BY_CONTROL[$controlId->value] ?? null;
        if ($entry === null) {
            return null;
        }
        [$code, $source] = $entry;
        $remediation = JmhzBlockerCatalog::remediation($code);

        return [
            'code' => $code,
            'kind' => $remediation['kind'],
            'field' => $remediation['field'],
            'source' => $source,
            'empty_attribute_ids' => $controlId->value === 243
                ? self::NONRESIDENT_EMPTY_ATTRIBUTE_IDS
                : [],
        ];
    }

    /** @return list<int> */
    public static function controlIds(): array
    {
        return array_keys(self::BY_CONTROL);
    }
}
