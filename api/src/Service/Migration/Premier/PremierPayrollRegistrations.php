<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;

/**
 * Registrace ČSSZ odeslané z PREMIER ({@see PremierPayrollSubmissions}), převzaté PRODUKTOVÝM
 * importem registrací (Mzdy → Importy): stejný náhled a zápis, stejná kontrola nad evidencí.
 * Logiku importu tu nic neopakuje, jen ho volá, takže z vět do profilu přihlášky A1, identifikátorů
 * (OIČ, ID PPV), rezidence, adres po složkách, důchodových údajů a dalších polí jde tentýž zápis jako
 * při ručním nahrání souborů.
 *
 * Věty se berou po jedné v pořadí odeslání, každá se plánuje nad stavem evidence po předchozí.
 * Zapsaná věta (i věta, ke které evidence už odpovídá) se zapíše do mapy převodu, takže opakovaný
 * převod nic nezdvojí a nepřepisuje: dvě věty téže osoby si mohou protiřečit (starší hlásí jiný
 * stát narození nebo pojišťovnu než pozdější) a bez mapy by je každý běh zapisoval znovu za sebou.
 * Věta, kterou se zapsat nepodařilo, se v mapě neeviduje a příští běh ji zkusí znovu.
 */
final class PremierPayrollRegistrations
{
    private const ENVIRONMENT = 'production';

    public function __construct(
        private readonly RegistrationImportService $imports,
        private readonly PremierImportRepository $map,
    ) {}

    /**
     * Zapíše přijaté registrace s datem do konce převáděného období.
     *
     * @return array{
     *   counts:array<string,int>,
     *   problems:list<array{code:string,text:string,context:array<string,mixed>}>
     * } `counts` jsou počty pro protokol, `problems` věty, které se nezapsaly a mají zůstat vidět
     */
    public function import(PremierContext $ctx): array
    {
        $submissions = PremierPayrollSubmissions::fromBackup($ctx->backup);
        $counts = [
            'registrations_files' => $submissions->stats['files'],
            'registrations_files_rejected' => $submissions->stats['files_rejected'],
            'registrations_files_unreadable' => $submissions->stats['files_unreadable'],
            'registrations_sentences_rejected' => $submissions->stats['sentences_rejected'],
            'registrations_sentences' => count($submissions->sentences),
            'registrations_later' => 0,
            'registrations_done' => 0,
            'registrations_applied' => 0,
            'registrations_unchanged' => 0,
            'registrations_unmatched' => 0,
            'registrations_blocked' => 0,
            'registrations_failed' => 0,
        ];
        $problems = [];
        $until = $ctx->endsOn();
        foreach ($submissions->sentences as $sentence) {
            if ($sentence['date'] !== null && $sentence['date'] > $until) {
                $counts['registrations_later']++;
                continue;
            }
            if ($this->map->get($ctx->supplierId, PremierImportRepository::KIND_PAYROLL_REGISTRATION, $sentence['key']) !== null) {
                $counts['registrations_done']++;
                continue;
            }
            $reference = "{$sentence['type']} {$sentence['vrep_id']} věta {$sentence['sqnr']}";
            $context = ['type' => $sentence['type'], 'submission' => $sentence['vrep_id'], 'sentence' => $sentence['sqnr']];
            $files = [['name' => $sentence['name'], 'content_base64' => base64_encode($sentence['content'])]];
            try {
                $preview = $this->imports->preview($ctx->supplierId, self::ENVIRONMENT, $files);
                $record = $preview['records'][0] ?? null;
                if ($record === null) {
                    $counts['registrations_failed']++;
                    $problems[] = ['code' => 'registration_unreadable', 'context' => $context,
                        'text' => "Podání ČSSZ {$reference}: věta se nedala přečíst - " . (string) ($preview['files'][0]['error'] ?? 'bez popisu') . '.'];
                    continue;
                }
                if ($record['blocker'] !== null) {
                    $counts['registrations_blocked']++;
                    $problems[] = ['code' => 'registration_blocked', 'context' => $context,
                        'text' => "Podání ČSSZ {$reference}: věta se nezapsala - " . (string) $record['blocker']];
                    continue;
                }
                // Osoby a vztahy zakládá převzetí z PREMIER, věty je jen doplňují. Věta, ke které převod vztah
                // nezná (PREMIER ho eviduje s jiným nástupem nebo druhem, nebo ho převod záměrně nezaložil),
                // by jinak založila druhý vztah téže osoby bez mezd a bez návaznosti na další věty.
                if (in_array($record['operation'], ['create_person', 'create_employment'], true)) {
                    $counts['registrations_unmatched']++;
                    $problems[] = ['code' => 'registration_unmatched', 'context' => $context,
                        'text' => "Podání ČSSZ {$reference}: věta neodpovídá žádnému převzatému vztahu (PREMIER ho eviduje s jiným nástupem nebo druhem "
                            . 'vztahu, nebo ho převod nezaložil), proto se nepřevzala a nevznikl druhý vztah téže osoby. K ověření: porovnejte nástup vztahu '
                            . 'na kartě zaměstnance s hlášením ČSSZ.'];
                    continue;
                }
                if ($record['selectable'] !== true) {
                    $counts['registrations_unchanged']++;
                    $this->remember($ctx, $sentence['key'], $record['match']['employment_id'] ?? $record['match']['employee_id'] ?? null);
                    continue;
                }
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
                    autoApproveChanges: true,
                );
            } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
                $counts['registrations_failed']++;
                $problems[] = ['code' => 'registration_failed', 'context' => $context,
                    'text' => "Podání ČSSZ {$reference}: věta se nezapsala - {$e->getMessage()}"];
                continue;
            }
            $result = $applied['results'][0] ?? null;
            $status = is_array($result) ? (string) $result['status'] : 'failed';
            if ($status === 'applied') {
                $counts['registrations_applied']++;
                $this->remember($ctx, $sentence['key'], $result['employment_id'] ?? $result['employee_id'] ?? null);
                continue;
            }
            $counts[$status === 'skipped' ? 'registrations_blocked' : 'registrations_failed']++;
            $problems[] = ['code' => $status === 'skipped' ? 'registration_blocked' : 'registration_failed', 'context' => $context,
                'text' => "Podání ČSSZ {$reference}: věta se nezapsala - " . (is_array($result) ? (string) ($result['message'] ?? '') : 'bez výsledku') . '.'];
        }
        return ['counts' => $counts, 'problems' => $problems];
    }

    private function remember(PremierContext $ctx, string $key, mixed $targetId): void
    {
        $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PAYROLL_REGISTRATION, $key, is_numeric($targetId) ? (int) $targetId : 1, $ctx->runId);
    }
}
