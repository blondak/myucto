<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Vědomé, explicitní prohlášení tvaru ODESÍLANÉ GovTalk obálky.
 *
 * V podkladech ČSSZ je doložená jen obálka PŘÍCHOZÍ (protokol): jmenný prostor,
 * `EnvelopeVersion` 2.0, `Header/MessageDetails/{Class,Qualifier,Function}`,
 * prázdný `GovTalkDetails` a `Body` s ČSSZ obálkou `Message/@version` +
 * `@eType="response"`. O odesílané obálce podklady říkají jen dvě věci
 * nepřímo: nese variabilní symbol (chyba 63 „Variabilní symbol z GovTalk
 * obálky {0} není shodný s VS ve formuláři") a smí nést e-mail pro notifikaci.
 * Kam přesně, jak se jmenuje `Qualifier` požadavku a jaký je `eType` requestu,
 * doložené NENÍ.
 *
 * Proto se tvar nehádá: `JmhzGovTalkEnvelope` bez tohoto objektu obálku vůbec
 * nepostaví. Kdo ho vytvoří, prohlašuje, že tvar ověřil proti dokumentaci
 * APEP/VREP nebo experimentem na testovacím prostředí.
 */
final readonly class JmhzGovTalkRequestShape
{
    private const TOKEN = '/^[A-Za-z][A-Za-z0-9._-]{0,63}$/D';

    public function __construct(
        public string $submitQualifier,
        public string $pollQualifier,
        public string $function,
        public string $variableSymbolKeyType,
        public string $bodyEnvelopeVersion,
        public string $bodyEnvelopeType,
        public string $sourceReference,
    ) {
        foreach ([
            'submitQualifier' => $submitQualifier,
            'pollQualifier' => $pollQualifier,
            'function' => $function,
            'variableSymbolKeyType' => $variableSymbolKeyType,
            'bodyEnvelopeType' => $bodyEnvelopeType,
        ] as $field => $value) {
            if (preg_match(self::TOKEN, $value) !== 1) {
                throw new JmhzTransportException(
                    'jmhz_govtalk_shape_invalid',
                    "Hodnota `{$field}` v prohlášeném tvaru GovTalk obálky není platný token.",
                );
            }
        }
        if (preg_match('/^[0-9]+(\.[0-9]+)*$/D', $bodyEnvelopeVersion) !== 1) {
            throw new JmhzTransportException(
                'jmhz_govtalk_shape_invalid',
                'Verze ČSSZ obálky v prohlášeném tvaru není číselná.',
            );
        }
        // Bez odkazu na zdroj by prohlášení bylo jen dalším odhadem s hezčím
        // jménem — v ledgeru pokusů má být dohledatelné, odkud tvar pochází.
        if (trim($sourceReference) === '') {
            throw new JmhzTransportException(
                'jmhz_govtalk_shape_invalid',
                'Prohlášený tvar GovTalk obálky musí uvádět zdroj, ze kterého byl ověřen.',
            );
        }
    }

    /**
     * Tvar doložený podacím protokolem ČSSZ. Zdroj: „CSSZ Podávací a dotazovací
     * protokol", v1.7 z 11. 2. 2025, kap. „Struktura zprávy" (str. 28–29 a 37),
     * plus zveřejněný vzorek `GovTalkEnvelopeCSSZEnvelope12.xml`. Obojí je
     * v `private/Mzdy/podklady/`.
     *
     * Doslovné hodnoty z protokolu:
     * - `Qualifier` = „Rozlišení funkce (request, poll, acknowledgement,
     *   response, error)" → odeslání `request`, dotaz na stav `poll`,
     * - `Function` = „(submit, delete)" → odeslání `submit`,
     * - variabilní symbol patří do `GovTalkDetails/Keys/Key[@Type="vars"]`;
     *   VREP nevyžaduje `Header/SenderDetails`, ale u podání vázaných na
     *   organizaci vyžaduje právě tenhle klíč,
     * - vnořená ČSSZ obálka má `version="1.2"`; `eType` pro JMHZ je `JMHZ25`
     *   (`CSSZSubmClasses.pdf` a dokumentace MPSV k JMHZ).
     *
     * **Pozor na záměnu:** MPSV provozuje ještě B2B bránu s vlastní GovTalk
     * obálkou (`Class=MPSV`, klíč `Type="ico"`, bez PKCS#7, `encrypted="no"`).
     * To je jiné API a pro JMHZ se použít nesmí.
     */
    public static function documented(): self
    {
        return new self(
            submitQualifier: 'request',
            pollQualifier: 'poll',
            function: 'submit',
            variableSymbolKeyType: 'vars',
            bodyEnvelopeVersion: '1.2',
            bodyEnvelopeType: 'JMHZ25',
            sourceReference: 'CSSZ Podavaci a dotazovaci protokol v1.7 (11. 2. 2025),'
                . ' kap. Struktura zpravy; CSSZSubmClasses.pdf',
        );
    }
}
