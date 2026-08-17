<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Která došlá zpráva ve schránce je odpovědí na naše podání.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se páruje podle věci
 * ═══════════════════════════════════════════════════════════════════════════
 * Podávací a dotazovací protokol ČSSZ v1.47, kapitola „ISDS → Komunikační vzor“
 * (strana 24 z 51), přiznává omezení rozhraní ISDS: „aktuální verze rozhraní
 * ISDS neumožňuje vyhledávat došlé zprávy podle pole věc či podle spisové
 * značky […], takže je nutné stažený seznam projít a vyhledat dle pole ‚věc‘
 * odpovědi na podání“.
 *
 * Párování podle věci tedy není improvizace — je to postup, který ČSSZ předepisuje
 * a k němuž se v témže odstavci ZAVAZUJE:
 *
 * > „Pro usnadnění párování zpráv obsahujících podání a odpověď garantuje systém
 * > pro zpracování e-podání ČSSZ uvedení ID datové zprávy ISDS s podáním v poli
 * > ‚věc‘ v datové zprávě s odpovědí, a to ve formátu
 * > "ČSSZ - Odpověď na e-Podání. [{0}-{1}-{2}]" (kde prvkem {0} je
 * > transakce/classname, prvkem {1} je unikátní identifikátor podání a prvkem
 * > {2} je identifikátor původní zprávy s podáním).“
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Rozhoduje `dmId`, ne classname a ne pořadí
 * ═══════════════════════════════════════════════════════════════════════════
 * Ze tří prvků je pro nás závazný jen třetí — `dmId` NAŠÍ odeslané zprávy. Ten
 * jsme dostali při odeslání (nebo dohledali přes `probe()`), je jedinečný v celém
 * ISDS a nedá se splést s cizím podáním. `classname` se kontroluje jen jako
 * pojistka proti odpovědi na jinou agendu; `correlationId` se čte a předá dál,
 * ale sám o sobě nic neprokazuje.
 *
 * Matcher schválně NEPOUŽÍVÁ „nejnovější zpráva od ČSSZ“ ani shodu podle období.
 * Zaměstnavatel odesílá každý měsíc a opravná podání se překrývají — vzít
 * odpověď podle času by k podání přiřadilo protokol jiného měsíce a tím uzavřelo
 * povinnost, která uzavřená není.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co matcher NEDĚLÁ
 * ═══════════════════════════════════════════════════════════════════════════
 * Neprohlašuje nic za ověřené. Vrátí jen „tahle zpráva se tváří jako odpověď na
 * tohle podání“; obsah přílohy pak musí projít
 * {@see JmhzProtocolSignatureVerifier} a {@see JmhzProtocolParser} přesně jako
 * u VREP. Věc datové zprávy je nepodepsaný text, který si může nastavit kdokoliv
 * — kdyby na něm stálo přijetí podání, stačilo by poslat zprávu se správně
 * složeným předmětem.
 */
final readonly class JmhzIsdsResponseMatcher
{
    /**
     * Předpona věci odpovědi podle protokolu. Porovnává se bez ohledu na
     * velikost písmen a s tolerancí k mezerám — je to text pro člověka a
     * protokol jeho přesný tvar nezaručuje nad rámec uvedeného vzoru.
     */
    public const SUBJECT_PREFIX = 'ČSSZ - Odpověď na e-Podání.';

    /**
     * Název přílohy s protokolem, tvar podle téže strany protokolu:
     * `ČSSZ_Protokol_o_zpracování_e-Podání_{0}-{1}-{2}.xml`.
     */
    public const ATTACHMENT_PREFIX = 'ČSSZ_Protokol_o_zpracování_e-Podání_';

    /**
     * Rozebere věc odpovědi na tři prvky, nebo vrátí `null`, když to odpověď
     * ČSSZ na e-Podání není.
     */
    public function parseSubject(?string $subject): ?JmhzIsdsResponseReference
    {
        if ($subject === null) {
            return null;
        }
        // Hranatá závorka je jediná strukturní kotva, kterou protokol slibuje;
        // text před ní se může lišit diakritikou i mezerami podle toho, čím
        // prošel. Vytahuje se proto obsah závorky, ne shoda celé věty.
        if (preg_match('/\[([^\[\]]{3,255})\]/u', $subject, $match) !== 1) {
            return null;
        }
        $parts = explode('-', $match[1]);
        // Formát je {classname}-{correlationId}-{dmId}. Míň než tři prvky není
        // ten formát; víc být může, protože correlationId samo pomlčku obsahovat
        // smí — proto se krajní prvky berou zvenčí a zbytek je correlationId.
        if (count($parts) < 3) {
            return null;
        }
        $className = trim(array_shift($parts));
        $dmId = trim(array_pop($parts));
        $correlationId = trim(implode('-', $parts));

        if ($className === '' || $dmId === '' || $correlationId === '') {
            return null;
        }

        return new JmhzIsdsResponseReference($className, $correlationId, $dmId);
    }

    /**
     * Je tahle došlá zpráva odpovědí na zprávu, kterou jsme odeslali?
     *
     * @param string $sentMessageId `dmId` naší odeslané zprávy
     */
    public function matches(
        ?string $subject,
        string $sentMessageId,
        ?string $expectedClassName = null,
    ): bool {
        $reference = $this->parseSubject($subject);
        if ($reference === null) {
            return false;
        }
        $sentMessageId = trim($sentMessageId);
        if ($sentMessageId === '') {
            // Bez ID odeslané zprávy nemáme s čím porovnávat. Vrátit „ano“ by
            // k podání přiřadilo první odpověď, která se namane.
            return false;
        }
        if (!hash_equals($sentMessageId, $reference->originalMessageId)) {
            return false;
        }

        return $expectedClassName === null
            || strcasecmp(trim($expectedClassName), $reference->className) === 0;
    }
}
