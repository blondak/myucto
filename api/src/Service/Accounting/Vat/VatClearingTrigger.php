<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Vat;

use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;

/**
 * Napojení zúčtovacího dokladu DPH na PŘIZNÁNÍ — autoritativní okamžik, kdy je daň
 * za období známá (migrace 1332).
 *
 * PROČ TO NEJDE PODLE KALENDÁŘE. Cron 1. dne v měsíci zaúčtuje daň ze stavu, jaký
 * knihy mají v ten den. Doklad, který do období přibude POZDĚJI — opožděná přijatá
 * faktura, oprava, doklad vytěžený AI o pár dní později — obrat 343.100/343.200 změní
 * a zúčtovací zápis přestane odpovídat. V hlavní knize pak leží jiný závazek vůči
 * správci daně než ten, který se doopravdy podal, a nikoho to neupozorní.
 * Přiznání je naproti tomu okamžik, kdy je částka definitivní: je to přesně to číslo,
 * které odchází na finanční úřad.
 *
 * ── DVA VSTUPNÍ BODY, ZÁMĚRNĚ NESYMETRICKÉ ────────────────────────────────────
 *
 * {@see onSubmissionFiled()} — přiznání označeno jako PODANÉ (`tax_submissions.status`
 * = `submitted`). Zúčtování se ZALOŽÍ nebo PŘEPOČÍTÁ. Tohle je primární spouštěč.
 *
 * {@see onSubmissionDrafted()} — vygenerování/stažení konceptu přiznání. Zúčtování se
 * jen OBNOVÍ, pokud už existuje; nikdy se nezaloží nové.
 *
 * Nesymetrie je záměr, ne opomenutí. Archivace snímku výslovně NENÍ podání (audit §2.4,
 * viz {@see \MyInvoice\Service\Report\TaxSubmissionArchiver}) — přesně proto tam nedávno
 * přestal jezdit i posun daňového zámku. Kdyby stažení náhledu zakládalo účetní doklad,
 * vznikla by v knihách položka z pouhého prohlížení, a to i za období, které účetní
 * teprve rozpracovává. Nechat naopak UŽ EXISTUJÍCÍ doklad zastarat je právě ta vada,
 * kterou tahle změna odstraňuje — obnova existujícího dokladu tedy zůstává, protože
 * nemění POČET dokladů v deníku, jen sladí částku s tím, co účetní právě vidí.
 *
 * ── OPRAVNÉ A DODATEČNÉ PŘIZNÁNÍ ──────────────────────────────────────────────
 * Žádná zvláštní větev: opravné (O), dodatečné (D) i dodatečné opravné (E) přiznání
 * projdou týmž `onSubmissionFiled()` a doklad přepočítají znovu, protože se ptá na
 * AKTUÁLNÍ obrat období, ne na rozdíl vykázaný v přiznání. Poslední podání vyhrává
 * a jeho `submission_id` se zapíše do `vat_clearing_runs`.
 *
 * ── NIKDY NESHODÍ PODÁNÍ ──────────────────────────────────────────────────────
 * Zúčtování je NÁSLEDEK podání, ne jeho podmínka. Selhání (zavřené období, zámek,
 * chybějící analytika) proto nesmí shodit označení „podáno" — vrací se jako výsledek
 * se `skipped`/`error` a zapíše se do `activity_log`. Že něco nesedí, uživatel uvidí
 * v kontrole uzávěrky (`vat_clearing_stale`) a v agendě DPH.
 *
 * ── POŘADÍ VŮČI DAŇOVÉMU ZÁMKU JE KRITICKÉ ────────────────────────────────────
 * `TaxSubmissionArchiver::markSubmitted()` po označení podání posune
 * `accounting_supplier_settings.locked_until` na konec vykázaného období. Datum
 * zúčtovacího dokladu je POSLEDNÍ DEN téhož období, takže po posunu zámku by ho
 * {@see \MyInvoice\Service\Accounting\PostingService} odmítl s `date_locked`.
 * Přepočet proto MUSÍ proběhnout PŘED posunem zámku — viz volání v archiveru.
 */
final class VatClearingTrigger
{
    /** Přiznání k DPH. Kontrolní hlášení (dphkh1) daň nestanovuje, zúčtování nespouští. */
    public const RETURN_FORM = 'dphdp3';

    public function __construct(
        private readonly VatClearingService $clearing,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Přiznání k DPH bylo označeno jako PODANÉ → zaúčtovat/přepočítat zúčtování období,
     * ať hlavní kniha ukazuje přesně tu daň, která se podala.
     *
     * @param array<string,mixed> $submission řádek `tax_submissions` po přechodu na `submitted`
     * @param array<string,mixed> $meta auditní meta (user_id, ip, user_agent)
     * @return array<string,mixed>|null null = výkaz/firma, kterých se zúčtování netýká
     */
    public function onSubmissionFiled(array $submission, array $meta = []): ?array
    {
        return $this->run($submission, $meta, VatClearingService::TRIGGER_RETURN_FILED);
    }

    /**
     * Byl vygenerován koncept přiznání → OBNOVIT zúčtování, pokud už existuje.
     * Nezakládá nové (viz třídní docblock).
     *
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $meta
     * @return array<string,mixed>|null
     */
    public function onSubmissionDrafted(array $submission, array $meta = []): ?array
    {
        return $this->run($submission, $meta, VatClearingService::TRIGGER_RETURN_DRAFT);
    }

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $meta
     * @return array<string,mixed>|null
     */
    private function run(array $submission, array $meta, string $trigger): ?array
    {
        if ((string) ($submission['form_code'] ?? '') !== self::RETURN_FORM) {
            return null;
        }
        $supplierId = (int) ($submission['supplier_id'] ?? 0);
        $period = VatClearingService::periodFromSubmission($submission);
        if ($supplierId <= 0 || $period === null) {
            return null;
        }
        if (!$this->clearing->isCandidate($supplierId)) {
            return null;
        }
        [$year, $month] = $period;
        $userId = isset($meta['user_id']) ? (int) $meta['user_id'] : null;

        try {
            // Koncept jen OBNOVUJE: bez existujícího dokladu se nic nezakládá.
            if ($trigger === VatClearingService::TRIGGER_RETURN_DRAFT) {
                $status = $this->clearing->status($supplierId, $year, $month);
                if ($status['entry_id'] === null || $status['freshness'] === VatClearingService::FRESHNESS_OK) {
                    return null;
                }
                if (!$status['writable']) {
                    return $this->report($supplierId, $submission, $trigger, [
                        'status' => 'skipped',
                        'reason' => $status['writable_reason'],
                        'period' => $status['period_label'],
                    ], $userId);
                }
            }

            $result = $this->clearing->postForPeriod($supplierId, $year, $month, $meta + [
                'trigger'    => $trigger,
                'submission' => $submission,
            ]);
        } catch (PostingException $e) {
            // Zavřené období / zámek / chybějící období — zúčtování se nedoúčtuje a
            // NESMÍ shodit podání. Nález si vyzvedne kontrola uzávěrky a agenda DPH.
            return $this->report($supplierId, $submission, $trigger, [
                'status' => 'skipped',
                'reason' => $e->errorCode,
                'error'  => $e->getMessage(),
            ], $userId);
        } catch (\Throwable $e) {
            return $this->report($supplierId, $submission, $trigger, [
                'status' => 'error',
                'error'  => $e->getMessage(),
            ], $userId);
        }

        return $this->report($supplierId, $submission, $trigger, [
            'status'     => (string) $result['status'],
            'period'     => (string) $result['period_label'],
            'entry_id'   => $result['entry_id'],
            'input_vat'  => $result['input_vat'],
            'output_vat' => $result['output_vat'],
            'settlement' => $result['settlement'],
        ], $userId);
    }

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function report(int $supplierId, array $submission, string $trigger, array $payload, ?int $userId = null): array
    {
        $payload = ['trigger' => $trigger, 'submission_id' => (int) $submission['id']] + $payload;
        try {
            $this->activity->log(
                'accounting.vat_clearing_triggered',
                $userId,
                'tax_submission',
                (int) $submission['id'],
                $payload,
                null,
                null,
                $supplierId,
            );
        } catch (\Throwable) {
            // Auditní stopa je doplňková — její selhání nesmí shodit ani podání, ani přepočet.
        }

        return $payload;
    }
}
