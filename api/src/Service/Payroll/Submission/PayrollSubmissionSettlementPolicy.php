<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

/**
 * Smí účetní prohlásit povinnost za vyřízenou, když úřad neodpoví?
 *
 * Pravidlo je ZÁMĚRNĚ oddělené od {@see PayrollSubmissionSettlementService}:
 * ptá se na ně jak samotné uzavření, tak přehled podání, který podle něj
 * rozhoduje, jestli u řádku svítí tlačítko. Kdyby si každý postavil vlastní
 * podmínku, nabízela by obrazovka akci, kterou server odmítne — nebo ji
 * naopak zatajila.
 *
 * Brána je úzká a fail-closed. Projde jen agenda, u které je DOLOŽENÉ, že
 * úřad výsledek zpracování neposílá; jinde by se tudy odklikl měsíc, o kterém
 * úřad teprve rozhoduje.
 */
final readonly class PayrollSubmissionSettlementPolicy
{
    /** Stavy podání, které smí uzavřít ruční potvrzení. */
    public const SETTLEABLE_SUBMISSION_STATUSES = ['submitted', 'processing'];

    /**
     * Stavy podání, u kterých má smysl prohlásit „podal jsem to mimo aplikaci".
     *
     * Širší než u běžného uzavření schválně: účetní může hlášení vyplnit
     * a odeslat rovnou na portálu úřadu, aniž by ho aplikace kdy poslala —
     * podání pak zůstane `ready` nebo `prepared`. Chybí tu naopak všechny stavy,
     * o kterých už úřad rozhodl; tam není co potvrzovat.
     */
    public const EXTERNALLY_FILEABLE_SUBMISSION_STATUSES = [
        'prepared',
        'ready',
        'submitted',
        'processing',
    ];

    public function __construct(
        private PayrollDispatchCapabilityCatalog $capabilities,
    ) {}

    /**
     * `null` znamená „jde to"; jinak věta pro účetní.
     *
     * @param array<string,mixed>|null $outbox
     *        řádek z {@see \MyInvoice\Repository\Payroll\PayrollSubmissionRepository::findDispatchOutboxForSubmission()}
     */
    public function blockedReason(
        string $agendaCode,
        string $obligationStatus,
        string $submissionStatus,
        ?array $outbox,
    ): ?string {
        // Uzavřená povinnost se znovu neuzavírá. Bez tohohle kroku by tlačítko
        // svítilo dál i po uzavření: stav PODÁNÍ zůstává „odesláno" schválně,
        // takže sám o sobě o hotovosti měsíce nic neříká.
        if (in_array($obligationStatus, ['fulfilled', 'cancelled'], true)) {
            return 'Povinnost už je uzavřená.';
        }
        $capability = $this->capabilities->forAgenda($agendaCode);
        if (!$capability->isDispatchable()) {
            // ELDP a spol. mají vlastní, přísnější evidenci výsledku
            // (dokládá se dokumentem, ne kliknutím). Kdyby prošly i sem,
            // stály by vedle sebe dvě brány s různou laťkou a nikdo by
            // nepoužíval tu vyšší.
            return 'Tuhle agendu aplikace neodesílá, takže tady není co'
                . ' uzavírat. Výsledek se dokládá na záložce příslušné agendy.';
        }
        if ($capability->authorityReportsResult) {
            return 'U téhle agendy dorazí výsledek od úřadu sám a aplikace'
                . ' podle něj podání uzavře. Ručně ho uzavírat nelze — jinak by'
                . ' se za hotové prohlásilo něco, o čem úřad teprve rozhoduje.';
        }
        if (!in_array(
            $submissionStatus,
            self::SETTLEABLE_SUBMISSION_STATUSES,
            true,
        )) {
            return sprintf(
                'Uzavřít jde jen odeslané podání; tohle je ve stavu „%s".',
                $submissionStatus,
            );
        }
        // Řádek odchozí fronty existuje jen u datovky. Když existuje, musí
        // zpráva aplikaci prokazatelně opustit — uzavřít podání, které leží
        // ve frontě jako nepotvrzený koncept, by znamenalo prohlásit za
        // odevzdané něco, co nikdo neodeslal.
        if ($outbox !== null
            && !PayrollSubmissionDeliveryProof::hasLeftApplication($outbox)
        ) {
            return 'Zpráva zatím leží v odchozí frontě datové schránky'
                . ' neodeslaná. Nejdřív ji odešlete, teprve pak jde podání'
                . ' uzavřít.';
        }

        return null;
    }

    /**
     * Je u agendy povinnost splněná už DODÁNÍM zprávy do schránky úřadu?
     *
     * Platí pro agendy, které aplikace odesílá a na které úřad výsledek
     * zpracování neposílá — přehled o platbě pojistného a hromadné oznámení
     * zaměstnavatele zdravotní pojišťovně. Podání je u nich učiněné dodáním
     * do datové schránky pojišťovny; vadu pojišťovna oznamuje samostatnou
     * výzvou ({@see \MyInvoice\Service\Submission\DefectNoticeService}).
     * Čekat na odpověď, která nepřijde, znamenalo nutit účetní každý měsíc
     * ručně „označovat za vyřízené" něco, co už podala.
     *
     * Odvozeno z {@see PayrollDispatchCapabilityCatalog}, ne vyjmenováno:
     * stejná dvojice podmínek rozhoduje i o ručním uzavření, takže se obě
     * cesty nemohou rozejít v tom, které agendy pokrývají.
     */
    public function settlesOnDelivery(string $agendaCode): bool
    {
        $capability = $this->capabilities->forAgenda($agendaCode);

        return $capability->isDispatchable() && !$capability->authorityReportsResult;
    }

    /**
     * Smí aplikace sama uzavřít povinnost, protože zpráva prokazatelně
     * dorazila do schránky úřadu? `null` = ano.
     *
     * Na rozdíl od {@see self::blockedReason()} nestačí, že zpráva aplikaci
     * opustila (`sent`) — rozhoduje DOLOŽENÉ dodání (stav `delivered` nebo
     * připojená doručenka), protože tady nerozhoduje člověk.
     *
     * @param array<string,mixed>|null $outbox
     */
    public function deliverySettlementBlockedReason(
        string $agendaCode,
        string $obligationStatus,
        string $submissionStatus,
        ?array $outbox,
    ): ?string {
        if (in_array($obligationStatus, ['fulfilled', 'cancelled'], true)) {
            return 'Povinnost už je uzavřená.';
        }
        if (!$this->settlesOnDelivery($agendaCode)) {
            return 'U téhle agendy povinnost uzavírá výsledek od úřadu, ne doručení.';
        }
        if (!in_array(
            $submissionStatus,
            self::SETTLEABLE_SUBMISSION_STATUSES,
            true,
        )) {
            return sprintf(
                'Doručením se uzavírá jen odeslané podání; tohle je ve stavu „%s".',
                $submissionStatus,
            );
        }
        if (!in_array(
            PayrollSubmissionDeliveryProof::reason($outbox),
            ['delivered', 'receipt'],
            true,
        )) {
            return 'Dodání zprávy do schránky úřadu zatím není doložené.';
        }

        return null;
    }

    /**
     * Smí účetní prohlásit, že podání odeslala MIMO aplikaci (na portálu úřadu)?
     *
     * Proč je to druhá brána, a ne uvolnění té první: běžné ruční uzavření se
     * vědomě zavírá tam, kde úřad výsledek posílá sám, protože jinak by se za
     * hotové odklikl měsíc, o kterém úřad teprve rozhoduje. Tenhle předpoklad
     * ale u podání odeslaného jinudy NEPLATÍ — na hlášení, které aplikace nikdy
     * neodeslala, žádný protokol nedorazí a povinnost by visela navždy. Proto
     * `authorityReportsResult` tady záměrně nic neblokuje; laťkou je místo toho
     * výslovné prohlášení účetní, kdy a kde podala (vyžaduje ho služba).
     *
     * @param array<string,mixed>|null $outbox
     */
    public function externalFilingBlockedReason(
        string $agendaCode,
        string $obligationStatus,
        string $submissionStatus,
        ?array $outbox,
    ): ?string {
        if (in_array($obligationStatus, ['fulfilled', 'cancelled'], true)) {
            return 'Povinnost už je uzavřená.';
        }
        if (!$this->capabilities->forAgenda($agendaCode)->isDispatchable()) {
            return 'Tuhle agendu aplikace neodesílá, takže tady není co'
                . ' uzavírat. Výsledek se dokládá na záložce příslušné agendy.';
        }
        if (!in_array(
            $submissionStatus,
            self::EXTERNALLY_FILEABLE_SUBMISSION_STATUSES,
            true,
        )) {
            return sprintf(
                'O tomhle podání už úřad rozhodl (stav „%s"), takže není co'
                    . ' potvrzovat.',
                $submissionStatus,
            );
        }
        // Zpráva čekající ve frontě je tu STOPKA s opačnou radou než u běžného
        // uzavření: když účetní podala na portálu, nesmí ta samá zpráva odejít
        // ještě jednou datovkou — u úřadu by vznikla duplicita.
        if ($outbox !== null
            && !PayrollSubmissionDeliveryProof::hasLeftApplication($outbox)
        ) {
            return 'Zpráva ještě leží v odchozí frontě datové schránky. Nejdřív'
                . ' ji ve frontě zrušte, jinak by totéž hlášení odešlo podruhé.';
        }

        return null;
    }
}
