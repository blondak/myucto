<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/**
 * Čím je nezdanění mzdové složky podložené.
 *
 * `tax_treatment = 'exempt'` sám o sobě je jen tvrzení. Výpočet daně na něm
 * proto stojí (`income-component-exemption-evidence-unverified`) a stát má na to
 * právo: důkazní břemeno nese plátce daně podle § 92 daňového řádu.
 *
 * Doklad se ale nevymýšlí jako nová evidence. § 6 odst. 9 ZDP ve znění pro rok
 * 2026 žádnou obecnou dokladovou podmínku osvobození neukládá — slovo
 * „prokázaných" je v celém odstavci JEDINKRÁT, a to v písm. o) (náhrada
 * prokázaných výdajů představitelům státní moci); „doloží", „potvrzení" ani
 * „písemně" tam nejsou vůbec. Co chybělo, je záznam o tom, o KTEROU
 * konstrukci se nezdanění opírá — a ten aplikace z vlastních dat sestavit umí.
 *
 * Hodnoty jsou právně různé situace, které se dosud slily do jediného
 * `exempt`. Rozdíl není kosmetický: na mzdovém listu podle § 38j odst. 2 písm. f)
 * bodu 2 se vykazují „částky osvobozené od daně", a plnění, které předmětem daně
 * vůbec není, mezi ně nepatří.
 */
enum PayrollExemptionBasis: string
{
    /**
     * § 6 odst. 7 ZDP — plnění NENÍ PŘEDMĚTEM DANĚ.
     *
     * Písm. a): „náhrady cestovních výdajů … do výše stanovené nebo umožněné
     * zvláštním právním předpisem pro zaměstnance odměňovaného platem …; jiné
     * a vyšší náhrady … jsou zdanitelným příjmem podle odstavce 1." Limit tedy
     * neplyne z rulesetu, ale ze zákoníku práce, a rozhodnutí, kolik se do něj
     * vešlo, nese klasifikovaný rozpad vyúčtování pracovní cesty
     * (CESTOVNI_NAHRADA_LIMIT / CESTOVNI_NAHRADA_NADLIMIT). Aplikace tu nic
     * nedopočítává a ani nesmí.
     */
    case NotSubjectToTax = 'not_subject_to_tax';

    /**
     * § 6 odst. 9 ZDP osvobozuje BEZ limitu — písm. a) odborný rozvoj,
     * c) nealkoholické nápoje na pracovišti, e) jízdenky u dopravce,
     * j) náhrada ztráty na důchodu, k) příjmy z praktického vyučování,
     * q) úhrada výdajů spojených s výplatou mzdy, s) odměna člena okrskové
     * volební komise. Není co dopočítávat a zákon k tomu doklad nežádá.
     */
    case StatutoryExempt = 'statutory_exempt';

    /**
     * § 6 odst. 9 písm. d) a m) ZDP — osvobozeno „v úhrnu do výše" ročního
     * limitu. Dokladem je ZMRAZENÝ rozpad koše na mzdovém vstupu: bez něj není
     * známé, kolik z plnění se do koše ještě vešlo, a osvobodit tedy nelze nic.
     */
    case BenefitBasket = 'benefit_basket';

    /**
     * § 6 odst. 9 písm. b) a i) ZDP — osvobozeno do limitu za KRATŠÍ OBDOBÍ než
     * zdaňovací: za jednu směnu (příspěvek na stravování), resp. za kalendářní
     * měsíc (přechodné ubytování).
     *
     * Proč to není `benefit_basket`, ačkoli se rozpad zmrazuje týmž mechanismem:
     * u ročního koše plyne strop z ročního úhrnu a jediné, co je k němu potřeba,
     * je pořadí čerpání. Tady strop plyne z PARAMETRU RULESETU NÁSOBENÉHO POČTEM
     * SMĚN S NÁROKEM (písm. b)), takže dokladem je navíc evidence docházky — jiná
     * konstrukce, jiný podklad, a na mzdovém listu i v auditní stopě musí jít
     * poznat, o kterou z nich šlo. S ročním košem se to NESČÍTÁ: jsou to
     * samostatná ustanovení s vlastními limity.
     */
    case PeriodicBenefitLimit = 'periodic_benefit_limit';

    public function statute(): string
    {
        return match ($this) {
            self::NotSubjectToTax => '§ 6 odst. 7 ZDP',
            self::StatutoryExempt => '§ 6 odst. 9 ZDP',
            self::BenefitBasket => '§ 6 odst. 9 písm. d) a m) ZDP',
            self::PeriodicBenefitLimit => '§ 6 odst. 9 písm. b) a i) ZDP',
        };
    }

    /**
     * Stojí osvobození na ZMRAZENÉM ROZPADU koše na mzdovém vstupu?
     *
     * Bez rozpadu není u těchhle podkladů známé, kolik z plnění se do limitu
     * ještě vešlo — osvobodit tedy nelze nic.
     */
    public function requiresFrozenSplit(): bool
    {
        return $this === self::BenefitBasket || $this === self::PeriodicBenefitLimit;
    }

    /**
     * Vykazuje se částka na mzdovém listu jako osvobozený příjem
     * (§ 38j odst. 2 písm. f) bod 2 ZDP)?
     *
     * Plnění mimo předmět daně osvobozeným příjmem NENÍ — mzdový list by o něm
     * jinak tvrdil něco, co zákon neříká.
     */
    public function isReportedAsExemptIncome(): bool
    {
        return $this !== self::NotSubjectToTax;
    }
}
