<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollPaymentBatchDiscardRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentBatchRepository;
use MyInvoice\Service\Payroll\PayrollProductionGate;

/**
 * Zahození mzdové platební dávky a její náhrada dávkou s jiným datem.
 *
 * Vzor je stejný jako u příkazů přijatých faktur: dokud příkaz aplikaci
 * neopustil, zahazuje se bez ptaní; stažený nebo bance předaný příkaz jde
 * zahodit jen s výslovným potvrzením, že ho uživatel v bankovnictví zrušil
 * (jinak hrozí dvojí platba). Mzdová dávka se přitom fyzicky nemaže - je
 * neměnná - takže se zahození jen zaeviduje a dávka přestane držet závazky.
 *
 * Dávku s doloženou úhradou zahodit nejde: peníze odešly a úhrada je spárovaná
 * s jejími alokacemi.
 */
final class PayrollPaymentBatchDiscardService
{
    public function __construct(
        private readonly PayrollPaymentBatchRepository $batches,
        private readonly PayrollPaymentBatchDiscardRepository $discards,
        private readonly PayrollPaymentBatchBuilder $builder,
        private readonly PayrollProductionGate $productionGate,
    ) {}

    /**
     * @return array{
     *   batch_id:int,
     *   handover_state:string,
     *   discarded:bool,
     *   replayed:bool
     * }
     */
    public function discard(
        int $supplierId,
        int $batchId,
        bool $bankCancellationConfirmed,
        ?int $actorUserId = null,
    ): array {
        $this->assertIds($supplierId, $batchId, $actorUserId);
        $this->productionGate->assertActive($supplierId);

        return $this->batches->transaction(fn (): array => $this->discardLocked(
            $supplierId,
            $batchId,
            $bankCancellationConfirmed,
            $actorUserId,
        ));
    }

    /**
     * Nové datum úhrady = zahodit dávku a sestavit ze stejných závazků novou.
     *
     * Obojí běží v jedné transakci: když nová dávka neprojde (minulé datum,
     * nepotvrzené pozdní datum, změněný účet příjemce), stará zůstane platná.
     *
     * @return array{
     *   discarded_batch_id:int,
     *   handover_state:string,
     *   batch:array<string,mixed>
     * }
     */
    public function reschedule(
        int $supplierId,
        int $batchId,
        string $paymentDate,
        bool $acceptLatePayment,
        bool $bankCancellationConfirmed,
        ?int $actorUserId = null,
    ): array {
        $this->assertIds($supplierId, $batchId, $actorUserId);
        PayrollPaymentDatePolicy::assertDate($paymentDate);
        $this->productionGate->assertActive($supplierId);

        return $this->batches->transaction(function () use (
            $supplierId,
            $batchId,
            $paymentDate,
            $acceptLatePayment,
            $bankCancellationConfirmed,
            $actorUserId,
        ): array {
            if (!$this->batches->lockSupplier($supplierId)) {
                throw new \DomainException('Firma platební dávky nebyla nalezena.');
            }
            $batch = $this->discards->lockBatch($supplierId, $batchId);
            if ($batch === null) {
                throw new \DomainException('Platební dávka nebyla nalezena.');
            }
            if ($this->discards->findDiscard($supplierId, $batchId) !== null) {
                throw new \DomainException(
                    'Dávka už je zahozená. Novou dávku vytvořte ze závazků v záložce Co zaplatit.',
                );
            }
            if ($paymentDate === $batch['planned_payment_date']) {
                throw new \DomainException(
                    'Dávka už má toto datum úhrady.',
                );
            }
            $requests = $this->discards->batchRequests($supplierId, $batchId);
            $discard = $this->discardLocked(
                $supplierId,
                $batchId,
                $bankCancellationConfirmed,
                $actorUserId,
            );
            $rebuilt = $this->builder->build(
                $supplierId,
                $batch['export_format'],
                $batch['payer_reference'],
                $requests,
                $actorUserId,
                $paymentDate,
                $acceptLatePayment,
            );

            return [
                'discarded_batch_id' => $batchId,
                'handover_state' => $discard['handover_state'],
                'batch' => $rebuilt,
            ];
        });
    }

    /**
     * @return array{
     *   batch_id:int,
     *   handover_state:string,
     *   discarded:bool,
     *   replayed:bool
     * }
     */
    private function discardLocked(
        int $supplierId,
        int $batchId,
        bool $bankCancellationConfirmed,
        ?int $actorUserId,
    ): array {
        if (!$this->batches->lockSupplier($supplierId)) {
            throw new \DomainException('Firma platební dávky nebyla nalezena.');
        }
        if ($this->discards->lockBatch($supplierId, $batchId) === null) {
            throw new \DomainException('Platební dávka nebyla nalezena.');
        }
        $existing = $this->discards->findDiscard($supplierId, $batchId);
        if ($existing !== null) {
            return [
                'batch_id' => $batchId,
                'handover_state' => $existing['handover_state'],
                'discarded' => false,
                'replayed' => true,
            ];
        }
        if ($this->discards->hasSettlement($supplierId, $batchId)) {
            throw new PayrollPaymentBatchSettledException();
        }
        $handover = $this->discards->handoverState($supplierId, $batchId);
        if ($handover !== 'none' && !$bankCancellationConfirmed) {
            throw new PayrollPaymentBatchHandoverException($handover);
        }
        $this->discards->insert(
            $supplierId,
            $batchId,
            $handover,
            $handover !== 'none',
            $actorUserId,
        );

        return [
            'batch_id' => $batchId,
            'handover_state' => $handover,
            'discarded' => true,
            'replayed' => false,
        ];
    }

    private function assertIds(
        int $supplierId,
        int $batchId,
        ?int $actorUserId,
    ): void {
        if ($supplierId <= 0 || $batchId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a platební dávka musí být kladná čísla.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel platební dávky není platný.',
            );
        }
    }
}
