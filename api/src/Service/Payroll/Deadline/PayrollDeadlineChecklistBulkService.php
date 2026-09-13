<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;

/**
 * Hromadné odškrtnutí položek checklistu z přehledu termínů.
 *
 * Import docházky založí stovkám lidí nástupní checklist s termíny v minulosti,
 * přestože smlouvy, přihlášky i prohlášení vyřídil předchozí mzdový systém.
 * Odklikat 225 položek po jedné na 225 kartách není obsluha, to je trest.
 *
 * Každá položka jde TOUŽ cestou jako jednotlivá změna
 * ({@see PayrollEmploymentRepository::updateChecklist()}): zámek vztahu,
 * kontrola verze, předpoklad položky, událost na časové ose a záznam do
 * auditu. Co neprojde (předpoklad, souběžná změna), skončí ve `failed`
 * s důvodem a zbytek dávky to nezastaví.
 *
 * Požadavek má strop položek i časový rozpočet; když nestačí, vrátí
 * `complete = false` a kurzor `next_after_id`, od kterého prohlížeč pokračuje
 * — vzor {@see \MyInvoice\Repository\Payroll\PayrollInputRepository::approveByFilter()}.
 */
final readonly class PayrollDeadlineChecklistBulkService
{
    /** Nejvíc položek na jeden požadavek. */
    public const BATCH_MAX = 100;

    /** Nejvíc id ve výčtu — víc jich prohlížeč na stránce stejně nevybere. */
    public const IDS_MAX = 500;

    private const TIME_BUDGET_SECONDS = 10.0;

    public function __construct(
        private PayrollDeadlineOverviewService $overview,
        private PayrollDeadlineOverviewRepository $deadlines,
        private PayrollEmploymentRepository $employments,
    ) {}

    /**
     * @param list<int>|null $itemIds výčet položek; null = celá skupina podle filtru
     * @param array{phase:string,item_key:string,horizon_days:int,q:string}|null $filter
     * @return array{
     *   completed:list<int>,
     *   skipped:list<array{item_id:int,code:string,message:string}>,
     *   failed:list<array{item_id:int,employment_id:?int,subject:string,code:string,message:string}>,
     *   remaining:int,
     *   complete:bool,
     *   next_after_id:int
     * }
     */
    public function complete(
        int $supplierId,
        ?array $itemIds,
        ?array $filter,
        string $note,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        int $afterId = 0,
        float $timeBudgetSeconds = self::TIME_BUDGET_SECONDS,
    ): array {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 500) {
            throw new \InvalidArgumentException(
                'Poznámka je povinná (1 až 500 znaků) — je to jediná stopa, proč je položka splněná.',
            );
        }

        $skipped = [];
        $failed = [];
        if ($itemIds !== null) {
            $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
            if ($itemIds === [] || count($itemIds) > self::IDS_MAX) {
                throw new \InvalidArgumentException(
                    'Vyberte 1 až ' . self::IDS_MAX . ' položek checklistu.',
                );
            }
            sort($itemIds);
            $candidateIds = array_values(array_filter(
                $itemIds,
                static fn (int $id): bool => $id > $afterId,
            ));
        } elseif ($filter !== null) {
            $candidateIds = array_values(array_filter(
                array_map(
                    static fn (array $c): int => $c['item_id'],
                    $this->overview->checklistGroupCandidates(
                        $supplierId,
                        $filter['horizon_days'],
                        $filter['phase'],
                        $filter['item_key'],
                        $filter['q'],
                    ),
                ),
                static fn (int $id): bool => $id > $afterId,
            ));
        } else {
            throw new \InvalidArgumentException('Chybí výčet položek nebo skupina přehledu.');
        }

        $batch = array_slice($candidateIds, 0, self::BATCH_MAX);
        $rows = $this->deadlines->checklistItemsByIds($supplierId, $batch);
        $completed = [];
        $started = microtime(true);
        $lastId = $afterId;
        $processed = 0;
        foreach ($batch as $itemId) {
            if ($processed > 0 && microtime(true) - $started > $timeBudgetSeconds) {
                break;
            }
            ++$processed;
            $lastId = $itemId;
            $row = $rows[$itemId] ?? null;
            if ($row === null) {
                $failed[] = [
                    'item_id' => $itemId,
                    'employment_id' => null,
                    'subject' => '',
                    'code' => 'not_found',
                    'message' => 'Položka checklistu nebyla nalezena.',
                ];
                continue;
            }
            if ($row['status'] !== 'pending') {
                $skipped[] = [
                    'item_id' => $itemId,
                    'code' => 'already_resolved',
                    'message' => 'Položka už je vyřízená.',
                ];
                continue;
            }
            try {
                $this->employments->updateChecklist(
                    $supplierId,
                    $row['employment_id'],
                    $row['item_key'],
                    $row['row_version'],
                    'completed',
                    $note,
                    $userId,
                    $ip,
                    $userAgent,
                );
                $completed[] = $itemId;
            } catch (PayrollEmploymentConflictException) {
                $failed[] = $this->failure($row, 'row_version_conflict', 'Položku mezitím změnil někdo jiný. Načtěte přehled znovu.');
            } catch (PayrollEmploymentNotFoundException $e) {
                $failed[] = $this->failure($row, 'not_found', $e->getMessage());
            } catch (\DomainException | \InvalidArgumentException $e) {
                $failed[] = $this->failure($row, 'prerequisite_failed', $e->getMessage());
            }
        }

        $remaining = count(array_filter(
            $candidateIds,
            static fn (int $id): bool => $id > $lastId,
        ));

        return [
            'completed' => $completed,
            'skipped' => $skipped,
            'failed' => $failed,
            'remaining' => $remaining,
            'complete' => $remaining === 0,
            'next_after_id' => $remaining === 0 ? 0 : $lastId,
        ];
    }

    /**
     * @param array{item_id:int,employment_id:int,full_name:string} $row
     * @return array{item_id:int,employment_id:?int,subject:string,code:string,message:string}
     */
    private function failure(array $row, string $code, string $message): array
    {
        return [
            'item_id' => $row['item_id'],
            'employment_id' => $row['employment_id'],
            'subject' => $row['full_name'],
            'code' => $code,
            'message' => $message,
        ];
    }
}
