<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

/**
 * Na jaké podání se u které agendy smí navázat oprava nebo storno.
 *
 * **Výchozí sada je PŘÍSNÁ a platí pro každou agendu:** navázat se dá jen na
 * podání, o kterém úřad už rozhodl. Dokud se neví, jestli originál prošel,
 * je oprava cesta k duplicitnímu podání — a to se u agend s okamžitým
 * protokolem (EPO) pozná až u správce daně, kdy se s tím nedá nic dělat.
 *
 * **Rozšíření je vždy jmenovité a s důvodem.** Agenda, u které protokol chodí
 * asynchronně a lhůta na opravu je přitom pevná, by při přísné sadě neměla jak
 * podat opravu včas: čekala by na rozhodnutí, které přijde až po lhůtě. Taková
 * agenda se proto vypisuje do {@see PENDING_PREDECESSOR_AGENDAS} spolu s větou,
 * proč to u ní neplatí. Bez důvodu se sem nesmí přidat nic — {@see reason()}
 * ho vrací a test nad katalogem ho vyžaduje.
 *
 * PROČ KATALOG V KÓDU, A NE SLOUPEC V DATABÁZI: jestli protokol chodí hned nebo
 * za dva dny, je vlastnost agendy dané zákonem a provozem úřadu, ne nastavení
 * firmy. `payroll_agenda_matrix` je tenantová a proměnná v čase; pravidlo z ní
 * by šlo přenastavit u jednoho zaměstnavatele a duplicitní podání by vzniklo
 * jen jemu. Modul takhle pinuje i ostatní vnější fakta (matice příznaků JMHZ,
 * katalog kontrol, lhůty) — jedno místo, čitelný důvod, změna vidět v diffu.
 */
final class PayrollAgendaCorrectionPolicy
{
    /**
     * Rozhodnutá podání. Sem patří i `rejected` — zamítnuté hlášení je
     * rozhodnuté a další podání je na něj správná reakce.
     *
     * @var list<string>
     */
    private const DECIDED_STATUSES = [
        'accepted',
        'partially_accepted',
        'rejected',
        'correction_required',
    ];

    /**
     * Průběžné stavy, které dokládají, že podání DOPUTOVALO k úřadu
     * (`submitted_at` u obou vynucuje check platformy), ale rozhodnuté ještě
     * není.
     *
     * @var list<string>
     */
    private const PENDING_STATUSES = [
        'submitted',
        'processing',
    ];

    /**
     * Agendy, které smějí navázat opravu i na nerozhodnuté podání — a proč.
     *
     * Klíč je `payroll_obligations.agenda_code`. Kód JMHZ je tu jako řetězec
     * schválně: sdílená vrstva podání nemá znát třídy konkrétní agendy. Že
     * odpovídá {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService::AGENDA_CODE},
     * hlídá test katalogu.
     *
     * @var array<string,string>
     */
    private const PENDING_PREDECESSOR_AGENDAS = [
        'JMHZ25' => 'Protokol ČSSZ chodí asynchronně (i s odstupem dnů), kdežto'
            . ' lhůta pro storno měsíčního hlášení končí pevně 20. dne'
            . ' následujícího měsíce. Čekat na rozhodnutí by znamenalo, že chybu,'
            . ' o které zaměstnavatel ví hned, nelze opravit včas.',
    ];

    /**
     * Stavy původního podání, na které se u téhle agendy smí navázat oprava.
     *
     * @return list<string>
     */
    public static function correctableStatuses(string $agendaCode): array
    {
        if (!self::allowsPendingPredecessor($agendaCode)) {
            return self::DECIDED_STATUSES;
        }

        return [...self::DECIDED_STATUSES, ...self::PENDING_STATUSES];
    }

    public static function allowsPendingPredecessor(string $agendaCode): bool
    {
        return array_key_exists($agendaCode, self::PENDING_PREDECESSOR_AGENDAS);
    }

    /** Důvod rozšíření, nebo `null` u agendy s přísnou výchozí sadou. */
    public static function reason(string $agendaCode): ?string
    {
        return self::PENDING_PREDECESSOR_AGENDAS[$agendaCode] ?? null;
    }

    /**
     * Celý katalog výjimek. Čte ho test, který hlídá, že se rozšíření nedá
     * přidat bez odůvodnění.
     *
     * @return array<string,string>
     */
    public static function declarations(): array
    {
        return self::PENDING_PREDECESSOR_AGENDAS;
    }
}
