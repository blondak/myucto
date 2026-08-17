<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Co musí být vyřízené U ČSSZ, než JMHZ vůbec může projít — oběma kanály.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to aplikace vůbec vyslovuje
 * ═══════════════════════════════════════════════════════════════════════════
 * Tyhle podmínky nesplní software; splní je člověk na správě sociálního
 * zabezpečení, a to typicky týdny předem. Když je aplikace nepojmenuje, uživatel
 * na ně narazí až ODMÍTNUTÍM OSTRÉHO PODÁNÍ — tedy ve chvíli, kdy už běží lhůta
 * a zbývá málo času. Mlčet o předpokladu, jehož nesplnění se projeví až takhle
 * pozdě, je horší než o něm říct předem.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co tahle třída NENÍ
 * ═══════════════════════════════════════════════════════════════════════════
 * Není to kontrola a netvrdí, jestli je splněno. Registraci ani plné moci si
 * aplikace ověřit nemá jak: ČSSZ pro to nemá rozhraní a odhadovat stav
 * z předchozích podání by u nového zaměstnavatele lhalo. Je to VÝČET, který se
 * zobrazí — rozhodnutí zůstává na člověku.
 *
 * Zdroje: Podávací a dotazovací protokol ČSSZ v1.47, kapitoly „VREP →
 * Prerekvizity" a „ISDS → Prerekvizity / Registrace na ČSSZ pro podání za
 * zaměstnavatele" (strany 23–24), a stránka „Komunikační kanály e-Podání".
 */
final readonly class JmhzSubmissionPrerequisites
{
    /**
     * Předpoklady pro daný kanál.
     *
     * Liší se v jediném, zato podstatném bodě: VREP stojí na kvalifikovaném
     * certifikátu zmocněné osoby, ISDS na identitě datové schránky odesílatele.
     * Zmocnění k podávání za zaměstnavatele je potřeba v obou případech —
     * kanálem se mění doprava, ne oprávnění jednat za firmu.
     *
     * @return list<array{code:string,title:string,detail:string}>
     */
    public static function forChannel(string $channel): array
    {
        $shared = [
            [
                'code' => 'cssz_power_of_attorney',
                'title' => 'Registrované zmocnění podávat za zaměstnavatele',
                'detail' => 'Na místně příslušné správě sociálního zabezpečení musí být'
                    . ' registrovaná plná moc s uvedeným rozsahem zmocnění. Nejrychleji'
                    . ' přes službu Správa plných mocí na ePortálu ČSSZ, kterou má'
                    . ' k dispozici oprávněná osoba zaměstnavatele (typicky statutár).'
                    . ' Alternativou je formulář Oznámení o zmocnění k úkonům a službám'
                    . ' ČSSZ a Úřadu práce ČR podaný na ÚSSZ.',
            ],
            [
                'code' => 'cssz_variable_symbol',
                'title' => 'Variabilní symbol vydaný ČSSZ',
                'detail' => 'Podání za zaměstnavatele musí nést variabilní symbol, který'
                    . ' zaměstnavateli přidělila ČSSZ.',
            ],
        ];

        $specific = match ($channel) {
            'vrep_apep' => [[
                'code' => 'cssz_qualified_certificate',
                'title' => 'Kvalifikovaný certifikát zmocněné osoby registrovaný u ČSSZ',
                'detail' => 'VREP ověřuje podávajícího podle certifikátu, kterým je podání'
                    . ' podepsané. Certifikát (nebo jeho sériové číslo a vydavatel) musí'
                    . ' být u ČSSZ registrovaný předem — u účetní nebo daňového poradce'
                    . ' se registrace zajišťuje spolu se zmocněním.',
            ]],
            'isds' => [[
                'code' => 'cssz_data_box_registration',
                'title' => 'Datová schránka podávajícího registrovaná u ČSSZ',
                'detail' => 'Přes datovou schránku se kvalifikovaný certifikát nevyžaduje —'
                    . ' ČSSZ ověřuje podání podle identity odesílající schránky. Právě proto'
                    . ' je ale vhodné schránku zmocněnce zaregistrovat dopředu: bez toho se'
                    . ' podání došetřuje ručně a odpověď přijde se zpožděním.',
            ]],
            default => [],
        };

        return [...$shared, ...$specific];
    }
}
