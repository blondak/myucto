<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;

/**
 * Podání nemocenských dávek (NEMPRI25, NEMPRI20, HZUPN20) odeslaná z PREMIER a přijatá ČSSZ
 * ({@see PremierPayrollSubmissions}), převzatá PRODUKTOVÝM importem (Mzdy → Importy, větev dávek).
 * Import podání znovu neodesílá: k převzatému vztahu zapíše případ dávky jako vyřízený předchozím
 * programem, aby hlídač lhůt NEMPRI ani HZUPN nepožadoval podruhé, a HZUPN k rozběhnuté
 * neschopnosti nechá otevřené. Osoby ani vztahy import dávek nezakládá, věta bez převzatého
 * vztahu se zablokuje a protokol ji vypíše.
 *
 * Věty jdou po jedné v pořadí odeslání (HZUPN tak najde případ, který založilo dřívější NEMPRI),
 * jen ty odeslané do konce převáděného období: nemocenské se páruje se schválenou nepřítomností,
 * kterou převod zakládá rok po roku. Zapsaná věta (i věta, ke které evidence už odpovídá) se zapíše
 * do mapy převodu, opakovaný převod ji přeskočí; nezapsaná se příště zkusí znovu.
 */
final class PremierPayrollSickness
{
    private const ENVIRONMENT = 'production';

    public function __construct(
        private readonly RegistrationImportService $imports,
        private readonly PremierImportRepository $map,
    ) {}

    /**
     * @return array{
     *   counts:array<string,int>,
     *   problems:list<array{code:string,text:string,context:array<string,mixed>}>
     * } `counts` jsou počty pro protokol, `problems` věty, které se nezapsaly a mají zůstat vidět
     */
    public function import(PremierContext $ctx): array
    {
        $submissions = PremierPayrollSubmissions::fromBackup($ctx->backup, PremierPayrollSubmissions::SICKNESS_TYPES);
        $counts = [
            'benefits_files' => $submissions->stats['files'],
            'benefits_files_rejected' => $submissions->stats['files_rejected'],
            'benefits_files_unreadable' => $submissions->stats['files_unreadable'],
            'benefits_sentences_rejected' => $submissions->stats['sentences_rejected'],
            'benefits_sentences' => count($submissions->sentences),
            'benefits_later' => 0,
            'benefits_done' => 0,
            'benefits_applied' => 0,
            'benefits_unchanged' => 0,
            'benefits_blocked' => 0,
            'benefits_failed' => 0,
        ];
        $problems = [];
        $until = $ctx->endsOn();
        foreach ($submissions->sentences as $sentence) {
            if ($sentence['date'] !== null && $sentence['date'] > $until) {
                $counts['benefits_later']++;
                continue;
            }
            if ($this->map->get($ctx->supplierId, PremierImportRepository::KIND_PAYROLL_SICKNESS, $sentence['key']) !== null) {
                $counts['benefits_done']++;
                continue;
            }
            $reference = "{$sentence['type']} {$sentence['vrep_id']} věta {$sentence['sqnr']}";
            $context = ['type' => $sentence['type'], 'submission' => $sentence['vrep_id'], 'sentence' => $sentence['sqnr']];
            $files = [['name' => $sentence['name'], 'content_base64' => base64_encode($sentence['content'])]];
            try {
                $preview = $this->imports->preview($ctx->supplierId, self::ENVIRONMENT, $files);
                $record = $preview['records'][0] ?? null;
                if ($record === null) {
                    $counts['benefits_failed']++;
                    $problems[] = ['code' => 'benefit_unreadable', 'context' => $context,
                        'text' => "Podání ČSSZ {$reference}: věta se nedala přečíst - " . (string) ($preview['files'][0]['error'] ?? 'bez popisu') . '.'];
                    continue;
                }
                if ($record['blocker'] !== null) {
                    $counts['benefits_blocked']++;
                    $problems[] = ['code' => 'benefit_blocked', 'context' => $context,
                        'text' => "Podání ČSSZ {$reference}: věta se nezapsala - " . (string) $record['blocker']];
                    continue;
                }
                if ($record['selectable'] !== true) {
                    $counts['benefits_unchanged']++;
                    $this->remember($ctx, $sentence['key'], $record['benefit']['case_id'] ?? null);
                    continue;
                }
                // Převod zapisuje celé mzdy pod právem k převodům; podání dávek předchozího programu jsou jejich součást.
                $applied = $this->imports->apply(
                    $ctx->supplierId,
                    self::ENVIRONMENT,
                    $files,
                    [(string) $record['key']],
                    true,
                    null,
                    $ctx->userOrNull(),
                    null,
                    null,
                    mayWriteSubmissions: true,
                );
            } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
                $counts['benefits_failed']++;
                $problems[] = ['code' => 'benefit_failed', 'context' => $context,
                    'text' => "Podání ČSSZ {$reference}: věta se nezapsala - {$e->getMessage()}"];
                continue;
            }
            $result = $applied['results'][0] ?? null;
            $status = is_array($result) ? (string) $result['status'] : 'failed';
            if ($status === 'applied') {
                $counts['benefits_applied']++;
                $this->remember($ctx, $sentence['key'], $result['case_id'] ?? null);
                continue;
            }
            $counts[$status === 'skipped' ? 'benefits_blocked' : 'benefits_failed']++;
            $problems[] = ['code' => $status === 'skipped' ? 'benefit_blocked' : 'benefit_failed', 'context' => $context,
                'text' => "Podání ČSSZ {$reference}: věta se nezapsala - " . (is_array($result) ? (string) ($result['message'] ?? '') : 'bez výsledku') . '.'];
        }
        return ['counts' => $counts, 'problems' => $problems];
    }

    private function remember(PremierContext $ctx, string $key, mixed $caseId): void
    {
        $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PAYROLL_SICKNESS, $key, is_numeric($caseId) ? (int) $caseId : 1, $ctx->runId);
    }
}
