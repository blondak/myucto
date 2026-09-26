<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

enum InsolvencyMode: string
{
    case None = 'none';
    case AlertOnly = 'alert_only';
    case ApprovedStandard = 'approved_standard';
    case CourtDeterminedAmount = 'court_determined_amount';

    /**
     * Režimy, ve kterých plátce mzdy sráží PRO INSOLVENČNÍHO SPRÁVCE, ne pro
     * exekutora — a tedy potřebuje neměnný platební pokyn.
     *
     * § 406 odst. 3 písm. d) zákona č. 182/2006 Sb. (insolvenční zákon):
     * v rozhodnutí o schválení oddlužení plněním splátkového kalendáře se
     * zpeněžením majetkové podstaty insolvenční soud „přikáže plátci mzdy
     * dlužníka, aby po doručení rozhodnutí o schválení oddlužení prováděl ze
     * mzdy dlužníka stanovené srážky a nevyplácel sražené částky dlužníku."
     * Podle § 406 odst. 5 IZ se to rozhodnutí doručuje plátci mzdy do vlastních
     * rukou a „částky sražené z dlužníkovy mzdy zasílá plátce mzdy dlužníka
     * insolvenčnímu správci, a to bez zřetele k tomu, že rozhodnutí o schválení
     * oddlužení dosud není v právní moci".
     *
     * Rozsah srážky je podle § 398 odst. 3 IZ týž jako při výkonu rozhodnutí
     * pro přednostní pohledávku, ledaže soud podle § 398 odst. 5 IZ na žádost
     * dlužníka (§ 391 odst. 2 IZ — „o stanovení nižších než zákonem určených
     * měsíčních splátek") určí jinou výši měsíčních splátek — to je právě
     * {@see self::CourtDeterminedAmount}.
     *
     * Zdroj: https://www.zakonyprolidi.cz/cs/2006-182#p406 (§ 406),
     * https://www.zakonyprolidi.cz/cs/2006-182#p398 (§ 398),
     * https://www.zakonyprolidi.cz/cs/2006-182#p391 (§ 391).
     *
     * Obě větve tedy poukazují na TÝŽ účet insolvenčního správce a liší se jen
     * VÝŠÍ srážky. Proto je to jedno volatelné pravidlo a ne dvě kopie
     * podmínky `mode === ApprovedStandard` rozeseté po výpočtu, repository
     * a materializaci platebních závazků.
     */
    public function redirectsPaymentToAdministrator(): bool
    {
        return $this === self::ApprovedStandard
            || $this === self::CourtDeterminedAmount;
    }

    /**
     * Zahájené insolvenční řízení, o oddlužení ještě nerozhodnuto: exekuční
     * srážky se SRÁŽEJÍ a DEPONUJÍ, nikomu se nevyplácejí.
     *
     * § 109 odst. 1 písm. c) IZ: exekuci, která by postihovala majetek
     * dlužníka, „lze nařídit nebo zahájit, nelze jej však provést". Provedením
     * je až výplata oprávněnému, ne srážka — rozsudek NS 29 Cdo 5295/2016
     * (R 4/2020): plátce mzdy „provádí srážky ze mzdy povinného dále, ale
     * nevyplácí je oprávněnému, dokud tyto účinky nepominou". Rozsah srážky se
     * proto řídí dosavadními exekucemi (přednostní a nepřednostní pohledávky
     * podle § 279 a 280 o. s. ř.), jen se celá částka deponuje.
     *
     * § 109 odst. 1 písm. d) IZ navíc zakazuje uplatnit dohodou založené právo
     * na výplatu srážek, takže dobrovolné dohody se v tomhle režimu nesrážejí.
     *
     * Co se s depozitem stane dál, rozhoduje konec účinků: schválí-li soud
     * oddlužení nebo prohlásí konkurs, patří do majetkové podstaty a plátce ho
     * VYDÁ insolvenčnímu správci; skončí-li řízení jinak, uvolní se oprávněnému
     * (existující uvolnění depozita rozhodnutím).
     *
     * Zdroj: https://www.zakonyprolidi.cz/cs/2006-182#p109,
     * https://sbirka.nsoud.cz/sbirka/5858/.
     */
    public function depositsEnforcementDeductions(): bool
    {
        return $this === self::AlertOnly;
    }
}
