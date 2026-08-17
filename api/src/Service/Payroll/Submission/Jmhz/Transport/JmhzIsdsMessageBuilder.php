<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Z čeho se skládá datová zpráva ISDS s podáním JMHZ.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co je obsahem zprávy — a co NENÍ
 * ═══════════════════════════════════════════════════════════════════════════
 * Podávací a dotazovací protokol ČSSZ v1.47, kapitola „ISDS → Komunikační vzor“
 * (strana 24 z 51):
 *
 * > „E-podání pro ČSSZ je zpráva ISDS, jejímž obsahem je jedna nebo více příloh
 * > ve formátu XML odpovídající schématu některého z typů podání […] (tzv.
 * > ‚holé XML podání‘). Pouze takové datové zprávy ISDS budou zpracovány
 * > automaticky systémem pro zpracování e-podání na ČSSZ.“
 *
 * Přílohou je tedy HOLÁ DATOVÁ VĚTA. Proti cestě VREP odpadá všechno ostatní:
 *
 * | vrstva                        | VREP | ISDS („holé XML“) |
 * |-------------------------------|------|-------------------|
 * | GovTalk obálka                | ano  | **ne**            |
 * | podpis kvalifikovaným certif. | ano  | **ne**            |
 * | šifrování na `DIS.CSSZ.2025`  | ano  | **ne**            |
 * | gzip                          | ano  | **ne**            |
 * | base64                        | ano  | **ne**            |
 *
 * Protokol (strana 47) k tomu výslovně varuje, že holé XML není komprimováno,
 * šifrováno ani podepsáno. Zastupuje je kanál sám: ISDS je šifrovaný přenos a
 * ČSSZ podle strany 23 provádí autentizaci a autorizaci „na základě identifikace
 * datové schránky odesílatele“. Přidat sem podpis nebo šifru by nebylo „bezpečněji“
 * — byla by to nedoložená odchylka, kterou by automatické zpracování odmítlo.
 *
 * Protokol na straně 47 připouští přes ISDS i formát GovTalk. Nepoužíváme ho:
 * GovTalk přes ISDS podle téže strany vyžaduje VS v `GovTalkDetails/Keys` navíc,
 * odpověď je v obou případech stejná, a druhá varianta obsahu by znamenala druhou
 * cestu k testování bez jediného užitku.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Přílohou je TENTÝŽ zmrazený artefakt jako u VREP
 * ═══════════════════════════════════════════════════════════════════════════
 * Builder žádné XML nestaví — dostane bajty zmrazeného artefaktu podání a jen je
 * zabalí. To je záměr, ne úspora: kdyby si ISDS cesta sestavovala vlastní
 * dokument, vznikly by pod jedním podáním dvě různé pravdy s různými GUIDy a
 * duplicitu přijatého podání nelze u ČSSZ vzít zpět. Volba kanálu tak nemůže
 * vyrobit druhé podání, druhou povinnost ani druhý termín.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Pole obálky
 * ═══════════════════════════════════════════════════════════════════════════
 * Protokol (strana 24): „Obsah polí v obálce datové zprávy […] není pro
 * zpracování relevantní (pole věc, zmocnění, k rukám apod.), mohou být nastavena
 * libovolně.“ ČSSZ tedy podle věci nic neřídí — vyplňuje se pro ČLOVĚKA, který
 * zprávu uvidí ve schránce, a proto nese agendu i období.
 *
 * Co relevantní JE, je `dmSenderIdent` (spisová značka odesílatele): podle strany
 * 24 ho ČSSZ v odpovědi vrátí v `RecipientIdent`. Je to jediný údaj, který si
 * volíme sami a dostaneme zpátky, takže na něm stojí párování odpovědi
 * i dohledání po přerušeném odeslání ({@see \MyInvoice\Service\Submission\Channel\Isds\IsdsChannel::probe()}).
 */
final readonly class JmhzIsdsMessageBuilder
{
    /**
     * ISDS omezuje `dmSenderIdent` na 50 znaků. Delší značka by se uřízla a
     * dohledání odeslané zprávy by přestalo fungovat právě po timeoutu, tedy
     * přesně tehdy, kdy je potřeba.
     */
    public const MAX_SENDER_IDENT = 50;

    /** Přílohou smí být podle protokolu jedině XML. */
    public const ATTACHMENT_MIME = 'application/xml';

    public function build(
        string $frozenArtifactBytes,
        string $agendaCode,
        string $variableSymbol,
        string $periodLabel,
        string $correlationReference,
        string $environment,
    ): JmhzIsdsMessage {
        if (trim($frozenArtifactBytes) === '') {
            throw new JmhzTransportException(
                'jmhz_isds_payload_empty',
                'Do datové schránky nelze odeslat prázdnou datovou větu.',
            );
        }
        // Příloha musí být XML, ne cokoliv, co se za ni vydává. Kdyby sem přišel
        // zip nebo podepsaná obálka, ČSSZ by zprávu nezpracovala automaticky a
        // podání by tiše uvázlo — proto raději hlasitě tady.
        if (!str_starts_with(ltrim($frozenArtifactBytes), '<')) {
            throw new JmhzTransportException(
                'jmhz_isds_payload_not_xml',
                'Příloha pro datovou schránku musí být holé XML datové věty.',
            );
        }
        $senderIdent = $this->senderIdent($correlationReference);
        $recipient = JmhzIsdsRecipientCatalog::forEnvironment($environment);

        return new JmhzIsdsMessage(
            recipient: $recipient,
            subject: $this->subject($agendaCode, $periodLabel, $variableSymbol),
            senderIdent: $senderIdent,
            attachmentFilename: $this->filename($agendaCode, $variableSymbol, $periodLabel),
            attachmentMimeType: self::ATTACHMENT_MIME,
            attachmentBytes: $frozenArtifactBytes,
        );
    }

    /**
     * Věc zprávy. ČSSZ ji ignoruje, člověk ne — ve schránce je to jediné, podle
     * čeho pozná, které období odešlo.
     */
    private function subject(string $agendaCode, string $periodLabel, string $variableSymbol): string
    {
        return sprintf(
            '%s - Jednotné měsíční hlášení zaměstnavatele za %s, VS %s',
            $agendaCode,
            $periodLabel,
            $variableSymbol,
        );
    }

    /**
     * Název přílohy je deterministický, protože artefakt je zmrazený: totéž
     * podání musí dát tentýž soubor, jinak by se dvě stažení nedala porovnat.
     */
    private function filename(string $agendaCode, string $variableSymbol, string $periodLabel): string
    {
        return sprintf(
            '%s_%s_%s.xml',
            $this->slug($agendaCode),
            $this->slug($variableSymbol),
            $this->slug($periodLabel),
        );
    }

    private function senderIdent(string $correlationReference): string
    {
        $value = trim($correlationReference);
        if ($value === '') {
            throw new JmhzTransportException(
                'jmhz_isds_sender_ident_missing',
                'Podání nemá spisovou značku, takže by odpověď z datové schránky'
                    . ' nešlo přiřadit zpět.',
            );
        }
        // Ořezat by znamenalo tiše rozbít párování odpovědi. Radši odmítnout.
        if (strlen($value) > self::MAX_SENDER_IDENT) {
            throw new JmhzTransportException(
                'jmhz_isds_sender_ident_too_long',
                'Spisová značka podání je delší než 50 znaků, které datová'
                    . ' schránka připouští.',
            );
        }

        return $value;
    }

    /** Název souboru musí projít ISDS i souborovým systémem uživatele. */
    private function slug(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', trim($value)) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'x' : $slug;
    }
}
