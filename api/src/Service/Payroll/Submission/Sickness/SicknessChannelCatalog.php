<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Kterým kanálem se NEMPRI a HZUPN smí odeslat — a čím je to doložené.
 *
 * Laťka je stejná jako u {@see \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerChannelCatalog}:
 * kanál se otevře jen tehdy, když je doložený z primárního zdroje, a odmítnutí
 * vždy pojmenuje, KTERÁ specifikace chybí — ne obecné „nepodporováno".
 *
 * ## Datová schránka (ISDS)
 *
 * ČSSZ, „Komunikační kanály e-Podání" (podklad uložený 17. 8. 2026,
 * `private/Mzdy/podklady/cssz-komunikacni-kanaly-2026-08-17/`) uvádí v tabulce
 * podporovaných e-Podání u obou agend ANO ve všech třech sloupcích
 * (VREP/APEP, ISDS, PIKR):
 *
 *   NEMPRI25 — „Oznámení zaměstnavatele o žádosti zaměstnance o dávku
 *              (NEMPRI_2025)" — ano / ano / ano
 *   HZUPN    — „Hlášení zaměstnavatele /osoby dobrovolně nemocensky pojištěné/
 *              při ukončení pracovní neschopnosti" — ano / ano / ano
 *
 * a k ISDS dodává: „do specializované datové schránky e-Podání ČSSZ
 * (preferováno): ID schránky: 5ffu6xk, Název schránky: e-podani ČSSZ
 * a/nebo do datových schránek místně příslušné OSSZ/PSSZ/MSSZ."
 * Podávací a dotazovací protokol v1.47 (11. 2. 2025) k tomu u obou agend
 * uvádí sloupec ISDS jako „holé XML", tedy bez GovTalk obálky. Datová schránka
 * zůstává výchozím kanálem připraveného podání.
 *
 * ## VREP/APEP
 *
 * GovTalk `Class` a `eType` obou agend jsou doložené v dokumentu ČSSZ „Přehled
 * obálek" (`CSSZSubmClasses.pdf`, stažen 8. 10. 2026 a bajtově shodný s kopií
 * z 15. 8. 2026; `private/normy/zdroje/vrep/`, textová vrstva
 * `private/normy/zdroje/nempri25/CSSZSubmClasses.txt`, řádky 53 a 60):
 *
 *   NEMPRI25 — Class `CSSZ_NEM_PRI`, eType `NEMPRI25`
 *   HZUPN20  — Class `CSSZ_NEM_PRI`, eType `HZUPN20`
 *
 * Dřívější tvrzení, že třída „v žádném z připnutých podkladů není", vzniklo
 * hledáním jen v Podávacím protokolu v1.47, který tabulku tříd nemá. Obě
 * agendy sdílejí jednu třídu, takže obálka se staví podle formuláře
 * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkRequestShape::forForm()}).
 * Tvar protokolu, kterým VREP odpoví, zatím doložený není; odpověď se uloží
 * k podání a výsledek zapíše účetní (viz `JmhzProtocolParser`).
 *
 * ePortál (PIKR) je ruční kanál s ověřenou identitou podatele; strojové
 * rozhraní pro něj neexistuje, takže se nenabízí ani jako „skoro".
 */
final class SicknessChannelCatalog
{
    /** Výchozí kanál připraveného podání. */
    public const CHANNEL_ISDS = 'isds';

    /** Druhý doložený kanál: certifikátem podepsané podání na VREP/APEP. */
    public const CHANNEL_VREP = 'vrep_apep';

    /** GovTalk `Class` obou agend (`CSSZSubmClasses.pdf`). */
    public const VREP_CLASS = 'CSSZ_NEM_PRI';

    /** Agenda → formulář (`Message/@eType`) téhož dokumentu. */
    private const VREP_FORMS = [
        'NEMPRI' => 'NEMPRI25',
        'HZUPN' => 'HZUPN20',
    ];

    public const REASON_PORTAL_MANUAL_ONLY =
        'cssz_eportal_manual_channel_only';
    public const REASON_CHANNEL_UNKNOWN =
        'cssz_channel_unknown';

    /**
     * Specializovaná datová schránka e-Podání ČSSZ. Zdroj je citovaný
     * v docblocku třídy; je to preferovaný cíl, ne jediný přípustný — místně
     * příslušná OSSZ má vlastní schránku a tu volí uživatel v adresáři.
     */
    public const CSSZ_EPODANI_DATA_BOX = '5ffu6xk';

    /** @var list<string> */
    private const DOCUMENTED_CHANNELS = [self::CHANNEL_ISDS, self::CHANNEL_VREP];

    /** @return list<string> */
    public function documentedChannels(): array
    {
        return self::DOCUMENTED_CHANNELS;
    }

    public function isDocumented(string $channel): bool
    {
        return in_array($channel, self::DOCUMENTED_CHANNELS, true);
    }

    /**
     * Kanál, pod kterým se podání připraví. Je to datová schránka; odeslání
     * přes VREP si kanál podání přepíše teprve ve chvíli, kdy opravdu odchází
     * ({@see \MyInvoice\Service\Payroll\Submission\PayrollSubmissionService::adoptDispatchChannel()}).
     */
    public function dispatchChannel(): string
    {
        return self::CHANNEL_ISDS;
    }

    /** Formulář (`eType`) agendy pro obálku VREP, nebo výjimka. */
    public function vrepForm(string $agendaCode): string
    {
        $form = self::VREP_FORMS[strtoupper(trim($agendaCode))] ?? null;
        if ($form === null) {
            throw new SicknessException(
                self::REASON_CHANNEL_UNKNOWN,
                'Agenda ' . $agendaCode . ' nepatří k nemocenským podáním NEMPRI'
                . ' ani HZUPN, takže pro ni nemáme doložený formulář VREP.',
            );
        }

        return $form;
    }

    /**
     * Fail-closed brána. Nedoložený kanál nikdy nekončí obecnou hláškou —
     * vždy řekne, která specifikace chybí a co s tím může obsluha udělat.
     */
    public function assertDispatchable(string $channel): void
    {
        if ($this->isDocumented($channel)) {
            return;
        }
        [$code, $message] = match ($channel) {
            'eportal', 'pikr' => [
                self::REASON_PORTAL_MANUAL_ONLY,
                'ePortál ČSSZ je ruční kanál s ověřenou identitou podatele; strojové rozhraní pro něj '
                . 'neexistuje. Připravené XML stáhněte a nahrajte na ePortálu, nebo ho odešlete datovou '
                . 'schránkou.',
            ],
            default => [
                self::REASON_CHANNEL_UNKNOWN,
                'Pro tenhle kanál nemáme u NEMPRI ani HZUPN doloženou specifikaci podání.',
            ],
        };

        throw new SicknessException($code, $message);
    }
}
