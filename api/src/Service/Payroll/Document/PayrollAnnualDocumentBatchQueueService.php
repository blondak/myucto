<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Repository\Payroll\PayrollAnnualDocumentBatchRepository;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementPerformer;

/**
 * Roční mzdové dokumenty přes serverovou frontu.
 *
 * Do téhle třídy se přesunulo to, co dřív dělal prohlížeč: smyčka
 * `for … await` nad seznamem osob, jeden HTTP požadavek na člověka. U firmy
 * s pěti sty zaměstnanci to byl půltisíc synchronních požadavků, které skončily
 * na timeoutu nebo zavřením záložky. Fronta běh přežije — prohlížeč jen sleduje.
 */
final class PayrollAnnualDocumentBatchQueueService
{
    /**
     * Začátek zprávy u zablokovaného ročního zúčtování. Za ním následují kódy
     * překážek ({@see AnnualSettlementBlocker}) oddělené čárkou; frontend je
     * přeloží stejnými texty jako v detailu osoby.
     */
    public const BLOCKED_MESSAGE_PREFIX = 'Roční zúčtování nelze provést, překážky: ';

    public function __construct(
        private readonly PayrollAnnualDocumentBatchRepository $batches,
        private readonly AnnualPayrollSheetService $payrollSheets,
        private readonly AnnualTaxCertificateGenerator $certificates,
        private readonly AnnualSettlementPerformer $settlements,
    ) {}

    /**
     * @param 'selected'|'all'|string $scope
     * @return array<string,mixed>
     */
    public function enqueue(
        int $supplierId,
        int $taxYear,
        PayrollDocumentKind $kind,
        string $scope,
        ?int $employeeId,
        ?int $actorUserId,
        ?string $idempotencyKey = null,
    ): array {
        if ($supplierId <= 0 || $taxYear < 2000 || $taxYear > 2199) {
            throw new \InvalidArgumentException('Identita roční dávky dokumentů není platná.');
        }
        if (!in_array($kind->value, PayrollAnnualDocumentBatchRepository::KINDS, true)) {
            throw new \InvalidArgumentException('Tento druh dokumentu se ročně nevystavuje.');
        }
        if (!in_array($scope, PayrollAnnualDocumentBatchRepository::SCOPES, true)) {
            throw new \InvalidArgumentException('Rozsah roční dávky není platný.');
        }
        if ($scope === 'selected' && ($employeeId === null || $employeeId <= 0)) {
            throw new \InvalidArgumentException('Rozsah „jedna osoba“ vyžaduje zaměstnance.');
        }
        $target = $scope === 'selected' ? $employeeId : null;

        $key = trim((string) $idempotencyKey);
        if ($key === '') {
            $key = sprintf(
                'annual-document-batch:%d:%d:%s:%s:%s',
                $supplierId,
                $taxYear,
                $kind->value,
                $scope,
                $target === null ? 'all' : (string) $target,
            );
        }
        if (mb_strlen($key) > 190) {
            throw new \InvalidArgumentException('Idempotency key dávky je příliš dlouhý.');
        }

        return $this->batches->enqueue(
            $supplierId,
            $taxYear,
            $kind->value,
            $scope,
            $target,
            $key,
            $actorUserId,
        );
    }

    /** @return array<string,mixed>|null */
    public function detail(int $supplierId, int $batchId): ?array
    {
        return $this->batches->detail($supplierId, $batchId);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function items(int $supplierId, int $batchId, int $limit, int $offset): array
    {
        return $this->batches->items($supplierId, $batchId, $limit, $offset);
    }

    /** @return array<string,mixed> */
    public function retry(int $supplierId, int $batchId, int $itemId): array
    {
        return $this->batches->retry($supplierId, $batchId, $itemId);
    }

    /**
     * @return array{processed:bool,outcome:string|null,batch_id:int|null,item_id:int|null}
     */
    public function processOne(): array
    {
        $claim = $this->batches->claimNext();
        if ($claim === null) {
            return [
                'processed' => false,
                'outcome' => null,
                'batch_id' => null,
                'item_id' => null,
            ];
        }
        $supplierId = (int) $claim['supplier_id'];
        $employeeId = (int) $claim['employee_id'];
        $taxYear = (int) $claim['tax_year'];
        $kindValue = (string) $claim['document_kind'];
        $actorUserId = $claim['requested_by'] === null
            ? null : (int) $claim['requested_by'];
        try {
            $kind = PayrollDocumentKind::from($kindValue);
            if ($kind === PayrollDocumentKind::AnnualSettlementResult) {
                $outcome = $this->settle($claim, $supplierId, $employeeId, $taxYear, $actorUserId);
                return [
                    'processed' => true,
                    'outcome' => $outcome,
                    'batch_id' => (int) $claim['batch_id'],
                    'item_id' => (int) $claim['id'],
                ];
            }
            // Potvrzení, které osoba za rok už má, se nepřegeneruje: jeho
            // nahrazení je OPRAVA s povinným důvodem (§ opravné potvrzení),
            // a ten za účetní vymyslet nelze. Přeskočení není selhání.
            if ($kind !== PayrollDocumentKind::PayrollSheet
                && $this->batches->hasAnnualDocument(
                    $supplierId,
                    $employeeId,
                    $taxYear,
                    $kindValue,
                )
            ) {
                $this->batches->skip(
                    $claim,
                    'annual_document_exists',
                    'Osoba už potvrzení za rok má; jeho nahrazení je oprava'
                        . ' s povinným důvodem.',
                );
                return [
                    'processed' => true,
                    'outcome' => 'skipped',
                    'batch_id' => (int) $claim['batch_id'],
                    'item_id' => (int) $claim['id'],
                ];
            }

            $document = $kind === PayrollDocumentKind::PayrollSheet
                ? $this->payrollSheets->generate(
                    $supplierId,
                    $employeeId,
                    $taxYear,
                    $actorUserId,
                )
                : $this->certificates->generate(
                    $supplierId,
                    $employeeId,
                    $taxYear,
                    $kind,
                    $actorUserId,
                );
            $this->batches->succeed($claim, (int) $document['id']);
            $outcome = 'succeeded';
        } catch (AnnualTaxCertificateNoIncomeException) {
            // Osoba příjem toho druhu v roce nemá (srážkové potvrzení u
            // zaměstnance jen se zálohami), takže se jí potvrzení nevystavuje.
            // Opakování nic nezmění a selháním to není.
            $this->batches->skip(
                $claim,
                'annual_certificate_no_income',
                'Osoba nemá za rok žádný příjem, na který se tento druh potvrzení'
                    . ' vystavuje.',
            );
            $outcome = 'skipped';
        } catch (\Throwable $exception) {
            // Selhání JEDNÉ osoby nesmí zhodit dávku: uzavře se jen její
            // položka, důvod se uloží a fronta jede dál.
            $this->batches->fail(
                $claim,
                self::errorCode($exception),
                $exception->getMessage(),
            );
            $outcome = 'failed';
        }

        return [
            'processed' => true,
            'outcome' => $outcome,
            'batch_id' => (int) $claim['batch_id'],
            'item_id' => (int) $claim['id'],
        ];
    }

    /** @return array{processed:int,succeeded:int,failed:int,skipped:int} */
    public function processAvailable(int $limit = 25): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0];
        for ($index = 0; $index < max(1, min(500, $limit)); $index++) {
            $item = $this->processOne();
            if (!$item['processed']) {
                break;
            }
            $result['processed']++;
            match ($item['outcome']) {
                'succeeded' => $result['succeeded']++,
                'skipped' => $result['skipped']++,
                default => $result['failed']++,
            };
        }
        return $result;
    }

    /**
     * Roční zúčtování jedné osoby z dávky.
     *
     * Volá se stejná `settle()` jako z detailu osoby: posouzení, výpočet
     * i doklad jsou tytéž, fronta jen rozhoduje o položce. `settle()` si
     * transakci otevírá sám; pronájem položky (`claimNext()`) je v té chvíli
     * už potvrzený, takže žádná transakce fronty neběží.
     *
     * Nesplněné podmínky nejsou selhání. Opakovat je nemá smysl (chybějící
     * prohlášení ani zmeškaná lhůta se dalším pokusem nespraví) a dávku nesmí
     * vykázat jako rozbitou, takže položka končí jako přeskočená s výčtem
     * překážek. Stejně tak osoba, která už zúčtovaná je.
     *
     * @param array<string,mixed> $claim
     * @return 'succeeded'|'skipped'
     */
    private function settle(
        array $claim,
        int $supplierId,
        int $employeeId,
        int $taxYear,
        ?int $actorUserId,
    ): string {
        $settled = $this->settlements->settle($supplierId, $employeeId, $taxYear, $actorUserId);
        $result = $settled['result'];
        $alreadySettled = in_array(
            AnnualSettlementBlocker::AlreadySettled,
            $result->blockers,
            true,
        );
        if ($alreadySettled || ($result->performed && !$settled['created'])) {
            $this->batches->skip(
                $claim,
                'annual_settlement_exists',
                'Osoba už je za rok zúčtovaná; roční zúčtování se provádí jen jednou.',
            );
            return 'skipped';
        }
        if (!$result->performed) {
            $this->batches->skip(
                $claim,
                'annual_settlement_blocked',
                self::BLOCKED_MESSAGE_PREFIX . implode(', ', $result->blockerCodes()),
            );
            return 'skipped';
        }
        $documentId = (int) ($settled['document']['id'] ?? 0);
        if ($documentId <= 0) {
            throw new \RuntimeException('Roční zúčtování nevrátilo archivovaný doklad.');
        }
        $this->batches->succeed($claim, $documentId);
        return 'succeeded';
    }

    private static function errorCode(\Throwable $exception): string
    {
        $short = (new \ReflectionClass($exception))->getShortName();
        $normalized = strtolower((string) preg_replace(
            '/(?<!^)[A-Z]/',
            '_$0',
            $short,
        ));
        return substr('render_' . $normalized, 0, 64);
    }
}
