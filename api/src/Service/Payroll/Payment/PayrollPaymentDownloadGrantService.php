<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollPaymentDownloadGrantRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentExportRepository;

final class PayrollPaymentDownloadGrantService
{
    public function __construct(
        private readonly PayrollPaymentDownloadGrantRepository $grants,
        private readonly PayrollPaymentExportRepository $exports,
        private readonly PayrollPaymentExportStorage $storage,
    ) {}

    /**
     * @param null|callable(array{
     *   grant_id:int,
     *   export_id:int,
     *   ttl_seconds:int
     * }):void $beforeCommit
     * @return array{
     *   grant_id:int,
     *   export_id:int,
     *   token:string,
     *   expires_at:string
     * }
     */
    public function issue(
        int $supplierId,
        int $exportId,
        int $userId,
        int $ttlSeconds = 300,
        ?callable $beforeCommit = null,
    ): array {
        if ($this->grants->hasActiveTransaction()) {
            throw new \LogicException(
                'Download grant nelze vytvořit uvnitř cizí transakce.',
            );
        }
        if ($supplierId <= 0 || $exportId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, export a uživatel grantu musí být kladná čísla.',
            );
        }
        if ($ttlSeconds < 30 || $ttlSeconds > 900) {
            throw new \InvalidArgumentException(
                'Platnost download grantu musí být 30 až 900 sekund.',
            );
        }
        $token = rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '=',
        );
        $tokenHash = hash('sha256', $token, true);

        $issued = $this->grants->transaction(function () use (
            $supplierId,
            $exportId,
            $userId,
            $tokenHash,
            $ttlSeconds,
            $beforeCommit,
        ): array {
            if ($this->exports->lockById($supplierId, $exportId) === null) {
                throw new \DomainException(
                    'Platební export pro download grant nebyl nalezen.',
                );
            }
            $now = $this->grants->currentUtcDateTime();
            $expiresAt = $now->modify("+{$ttlSeconds} seconds");

            $grantId = $this->grants->insert(
                $supplierId,
                $exportId,
                $userId,
                $tokenHash,
                $now->format('Y-m-d H:i:s'),
                $expiresAt->format('Y-m-d H:i:s'),
            );
            if ($beforeCommit !== null) {
                $beforeCommit([
                    'grant_id' => $grantId,
                    'export_id' => $exportId,
                    'ttl_seconds' => $ttlSeconds,
                ]);
            }

            return [
                'grant_id' => $grantId,
                'expires_at' => $expiresAt,
            ];
        });

        return [
            'grant_id' => $issued['grant_id'],
            'export_id' => $exportId,
            'token' => $token,
            'expires_at' => $issued['expires_at']->format(DATE_ATOM),
        ];
    }

    /**
     * @param null|callable(array{
     *   export_id:int,
     *   bytes:string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   mime_type:string,
     *   suggested_filename:string,
     *   cache_control:string
     * }):void $beforeCommit
     * @return array{
     *   export_id:int,
     *   bytes:string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   mime_type:string,
     *   suggested_filename:string,
     *   cache_control:string
     * }
     */
    public function consume(
        int $supplierId,
        int $userId,
        string $token,
        ?callable $beforeCommit = null,
    ): array {
        if ($this->grants->hasActiveTransaction()) {
            throw new \LogicException(
                'Stažení platebního exportu nelze spustit uvnitř cizí transakce.',
            );
        }
        if ($supplierId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a uživatel download grantu musí být kladná čísla.',
            );
        }
        $token = trim($token);
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new \DomainException(
                'Download grant není platný nebo již vypršel.',
            );
        }
        $tokenHash = hash('sha256', $token, true);

        return $this->grants->transaction(function () use (
            $supplierId,
            $userId,
            $tokenHash,
            $beforeCommit,
        ): array {
            $grant = $this->grants->lockForConsume(
                $supplierId,
                $userId,
                $tokenHash,
            );
            $now = $this->grants->currentUtcDateTime();
            if ($grant === null
                || $grant['used_at'] !== null
                || $now > new \DateTimeImmutable(
                    $grant['expires_at'],
                    new \DateTimeZone('UTC'),
                )
            ) {
                throw new \DomainException(
                    'Download grant není platný nebo již vypršel.',
                );
            }
            $bytes = $this->storage->readVerified(
                $supplierId,
                $grant['storage_key'],
            );
            if (strlen($bytes) !== $grant['size_bytes']
                || !hash_equals(
                    $grant['file_sha256'],
                    hash('sha256', $bytes),
                )
            ) {
                throw new \DomainException(
                    'Archivovaný platební export nemá platnou integritu.',
                );
            }
            if (!$this->grants->consume(
                $grant['grant_id'],
                $now->format('Y-m-d H:i:s'),
            )) {
                throw new \DomainException(
                    'Download grant není platný nebo již vypršel.',
                );
            }

            $result = [
                'export_id' => $grant['export_id'],
                'bytes' => $bytes,
                'file_sha256' => $grant['file_sha256'],
                'size_bytes' => $grant['size_bytes'],
                'mime_type' => $grant['mime_type'],
                'suggested_filename' =>
                    $grant['suggested_filename'],
                'cache_control' => 'private, no-store',
            ];
            if ($beforeCommit !== null) {
                $beforeCommit($result);
            }

            return $result;
        });
    }

}
