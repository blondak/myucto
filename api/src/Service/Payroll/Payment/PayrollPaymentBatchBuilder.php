<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollPaymentBatchRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use Psr\Clock\ClockInterface;

final class PayrollPaymentBatchBuilder
{
    private const MAX_LIABILITIES = 500;

    public function __construct(
        private readonly PayrollPaymentBatchRepository $batches,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly SecretEncryption $encryption,
        private readonly IbanValidator $ibanValidator,
        private readonly CzechBankAccountValidator $czechBankAccountValidator,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param list<array<string,mixed>> $requests
     * @return array{
     *   batch_id:int,
     *   batch_reference:string,
     *   channel:string,
     *   export_format:string,
     *   planned_payment_date:string,
     *   currency_code:string,
     *   declared_total_minor:int,
     *   declared_item_count:int,
     *   snapshot_hash:string,
     *   created:bool,
     *   replayed:bool
     * }
     */
    public function build(
        int $supplierId,
        string $exportFormat,
        string $payerReference,
        array $requests,
        ?int $actorUserId = null,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma platební dávky musí být kladné číslo.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel platební dávky není platný.',
            );
        }
        if (!in_array($exportFormat, ['abo', 'sepa', 'manual'], true)) {
            throw new \InvalidArgumentException(
                'Formát mzdové platební dávky není podporovaný.',
            );
        }
        if ($payerReference === ''
            || mb_strlen($payerReference, 'UTF-8') > 190
        ) {
            throw new \InvalidArgumentException(
                'Reference účtu plátce není platná.',
            );
        }
        $normalizedRequests = $this->normalizeRequests($requests);
        $idempotencyMaterial = CanonicalJson::encode([
            'schema_reference' => 'payroll-payment-batch-idempotency.v1',
            'supplier_id' => $supplierId,
            'export_format' => $exportFormat,
            'payer_reference' => $payerReference,
            'requests' => $normalizedRequests,
        ]);
        $idempotencyHash = hash(
            'sha256',
            $idempotencyMaterial,
            true,
        );
        $idempotencyHex = bin2hex($idempotencyHash);

        return $this->batches->transaction(function () use (
            $supplierId,
            $exportFormat,
            $payerReference,
            $normalizedRequests,
            $idempotencyHash,
            $idempotencyHex,
            $actorUserId,
        ): array {
            if (!$this->batches->lockSupplier($supplierId)) {
                throw new \DomainException(
                    'Firma platební dávky nebyla nalezena.',
                );
            }
            $existing = $this->batches->findByIdempotencyForUpdate(
                $supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                return [
                    ...$existing,
                    'created' => false,
                    'replayed' => true,
                ];
            }

            $requestByLiability = [];
            foreach ($normalizedRequests as $request) {
                $requestByLiability[$request['liability_id']] =
                    $request['amount_minor'];
            }
            $liabilityIds = array_keys($requestByLiability);
            $liabilities = $this->batches->lockLiabilities(
                $supplierId,
                $liabilityIds,
            );
            if (count($liabilities) !== count($liabilityIds)) {
                throw new \DomainException(
                    'Některý vybraný platební závazek neexistuje.',
                );
            }

            $existing = $this->batches->findByIdempotencyForUpdate(
                $supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                return [
                    ...$existing,
                    'created' => false,
                    'replayed' => true,
                ];
            }

            $plannedDate = null;
            $currencyCode = null;
            $channel = null;
            $declaredTotal = 0;
            $groups = [];
            foreach ($liabilities as $liability) {
                $amount = $requestByLiability[$liability['id']] ?? null;
                if ($amount === null) {
                    throw new \LogicException(
                        'Uzamčený závazek nebyl požadován.',
                    );
                }
                $this->assertLiability($liability, $amount);
                $plannedDate ??= $liability['due_on'];
                $currencyCode ??= $liability['currency_code'];
                if ($plannedDate !== $liability['due_on']
                    || $currencyCode !== $liability['currency_code']
                ) {
                    throw new \DomainException(
                        'Jedna dávka musí mít shodné datum a měnu.',
                    );
                }
                $recipientChannel = str_starts_with(
                    $liability['recipient_reference'],
                    'employee-account:',
                ) ? 'bank' : 'cash';
                $channel ??= $recipientChannel;
                if ($channel !== $recipientChannel) {
                    throw new \DomainException(
                        'Bankovní a hotovostní výplaty nelze míchat.',
                    );
                }
                $declaredTotal = $this->addAmounts(
                    $declaredTotal,
                    $amount,
                );
                $recipient = $liability['recipient_reference'];
                $source = $this->sourceSnapshot($liability);
                if (!isset($groups[$recipient])) {
                    $groups[$recipient] = [
                        'recipient_reference' => $recipient,
                        'employee_id' => $liability['employee_id'],
                        'amount_minor' => 0,
                        'liabilities' => [],
                        'source' => $source,
                    ];
                } elseif ($groups[$recipient]['employee_id']
                    !== $liability['employee_id']
                    || !$this->sameFrozenTarget(
                        $groups[$recipient]['source'],
                        $source,
                    )
                ) {
                    throw new \DomainException(
                        'Reference příjemce nemá v dávce jednoznačný zmrazený cíl.',
                    );
                }
                $groups[$recipient]['amount_minor'] = $this->addAmounts(
                    $groups[$recipient]['amount_minor'],
                    $amount,
                );
                $groups[$recipient]['liabilities'][] = [
                    'id' => $liability['id'],
                    'reference' => $liability['liability_reference'],
                    'amount_minor' => $amount,
                    'source_snapshot_hash' =>
                        $liability['source_snapshot_hash'],
                ];
            }
            ksort($groups, SORT_STRING);

            $payerInstruction = $this->payerInstruction(
                $supplierId,
                $channel,
                $exportFormat,
                $payerReference,
                $currencyCode,
            );
            $batchReference = 'payroll-batch:'
                . substr($idempotencyHex, 0, 48);
            $preparedItems = [];
            foreach ($groups as $recipient => $group) {
                $itemReference = 'payroll-item:'
                    . substr(
                        hash(
                            'sha256',
                            $idempotencyHex . "\0" . $recipient,
                        ),
                        0,
                        48,
                    );
                $instruction = $this->recipientInstruction(
                    $supplierId,
                    $exportFormat,
                    $currencyCode,
                    $plannedDate,
                    $group,
                );
                $instructionJson = CanonicalJson::encode($instruction);
                $instructionHash = hash('sha256', $instructionJson);
                $preparedItems[] = [
                    'item_reference' => $itemReference,
                    'recipient_reference' => $recipient,
                    'amount_minor' => $group['amount_minor'],
                    'instruction_ciphertext' =>
                        $this->encryption->encryptFor(
                            $instructionJson,
                            "payroll-payment-item:{$supplierId}:"
                                . $itemReference,
                        ),
                    'instruction_hash' => $instructionHash,
                    'liabilities' => $group['liabilities'],
                ];
            }
            $declaredItemCount = count($preparedItems);
            $creationDateTime = \DateTimeImmutable::createFromInterface(
                $this->clock->now(),
            )->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:sP');
            $snapshot = [
                'schema_reference' =>
                    'payroll-payment-batch-snapshot.v1',
                'batch_reference' => $batchReference,
                'channel' => $channel,
                'export_format' => $exportFormat,
                'direction' => 'outgoing',
                'planned_payment_date' => $plannedDate,
                'creation_datetime' => $creationDateTime,
                'currency_code' => $currencyCode,
                'payer_reference' => $payerReference,
                'payer_instruction' => $payerInstruction,
                'declared_total_minor' => $declaredTotal,
                'declared_item_count' => $declaredItemCount,
                'items' => array_map(
                    static fn (array $item): array => [
                        'item_reference' => $item['item_reference'],
                        'recipient_reference' =>
                            $item['recipient_reference'],
                        'amount_minor' => $item['amount_minor'],
                        'instruction_hash' =>
                            $item['instruction_hash'],
                        'liabilities' => $item['liabilities'],
                    ],
                    $preparedItems,
                ),
            ];
            $snapshotJson = CanonicalJson::encode($snapshot);
            $snapshotHash = hash('sha256', $snapshotJson);
            $snapshotCiphertext = $this->encryption->encryptFor(
                $snapshotJson,
                "payroll-payment-batch:{$supplierId}:{$batchReference}",
            );
            $batchId = $this->batches->insertBatch(
                $supplierId,
                $batchReference,
                $channel,
                $exportFormat,
                $plannedDate,
                $currencyCode,
                $payerReference,
                $declaredTotal,
                $declaredItemCount,
                $snapshotCiphertext,
                $snapshotHash,
                $idempotencyHash,
                $actorUserId,
            );
            foreach ($preparedItems as $item) {
                $itemId = $this->batches->insertItem(
                    $supplierId,
                    $batchId,
                    $item['item_reference'],
                    $item['recipient_reference'],
                    $item['amount_minor'],
                    $item['instruction_ciphertext'],
                    $item['instruction_hash'],
                    hash(
                        'sha256',
                        $idempotencyHex . "\0"
                            . $item['item_reference'],
                        true,
                    ),
                );
                foreach ($item['liabilities'] as $allocation) {
                    $this->batches->insertAllocation(
                        $supplierId,
                        $itemId,
                        $allocation['id'],
                        $allocation['amount_minor'],
                        hash(
                            'sha256',
                            $idempotencyHex . "\0"
                                . $item['item_reference'] . "\0"
                                . $allocation['id'],
                            true,
                        ),
                    );
                }
            }

            return [
                'batch_id' => $batchId,
                'batch_reference' => $batchReference,
                'channel' => $channel,
                'export_format' => $exportFormat,
                'planned_payment_date' => $plannedDate,
                'currency_code' => $currencyCode,
                'declared_total_minor' => $declaredTotal,
                'declared_item_count' => $declaredItemCount,
                'snapshot_hash' => $snapshotHash,
                'created' => true,
                'replayed' => false,
            ];
        });
    }

    /**
     * @param list<array<string,mixed>> $requests
     * @return non-empty-list<array{liability_id:int,amount_minor:int}>
     */
    private function normalizeRequests(array $requests): array
    {
        if ($requests === [] || count($requests) > self::MAX_LIABILITIES) {
            throw new \InvalidArgumentException(
                'Platební dávka musí obsahovat 1 až 500 závazků.',
            );
        }
        $result = [];
        $seen = [];
        foreach ($requests as $request) {
            $liabilityId = $request['liability_id'] ?? null;
            $amountMinor = $request['amount_minor'] ?? null;
            if (!is_int($liabilityId)
                || !is_int($amountMinor)
                || $liabilityId <= 0
                || $amountMinor <= 0
            ) {
                throw new \InvalidArgumentException(
                    'Závazek i částka dávky musí být kladné.',
                );
            }
            if (isset($seen[$liabilityId])) {
                throw new \InvalidArgumentException(
                    'Jeden závazek lze v dávce uvést pouze jednou.',
                );
            }
            $seen[$liabilityId] = true;
            $result[] = [
                'liability_id' => $liabilityId,
                'amount_minor' => $amountMinor,
            ];
        }
        usort(
            $result,
            static fn (array $left, array $right): int =>
                $left['liability_id'] <=> $right['liability_id'],
        );

        return $result;
    }

    /**
     * @param array{
     *   id:int,
     *   employee_id:?int,
     *   liability_kind:string,
     *   direction:string,
     *   recipient_reference:string,
     *   due_on:string,
     *   currency_code:string,
     *   amount_minor:int,
     *   allocated_minor:int
     * } $liability
     */
    private function assertLiability(array $liability, int $amount): void
    {
        if ($liability['liability_kind'] !== 'net_wage'
            || $liability['direction'] !== 'outgoing'
            || $liability['employee_id'] === null
        ) {
            throw new \DomainException(
                'Dávka podporuje pouze odchozí závazky čistých mezd.',
            );
        }
        $this->date($liability['due_on'], 'datum splatnosti závazku');
        if (preg_match('/^[A-Z]{3}$/D', $liability['currency_code']) !== 1) {
            throw new \DomainException('Měna závazku není platná.');
        }
        if ($liability['amount_minor'] <= 0
            || $liability['allocated_minor'] < 0
            || $liability['allocated_minor'] > $liability['amount_minor']
        ) {
            throw new \UnexpectedValueException(
                'Uložená alokace platebního závazku není platná.',
            );
        }
        $open = $liability['amount_minor']
            - $liability['allocated_minor'];
        if ($amount > $open) {
            throw new \DomainException(
                'Požadovaná platba překračuje otevřenou částku závazku.',
            );
        }
        $employeeId = $liability['employee_id'];
        if ($liability['recipient_reference']
            === "employee-cash:{$employeeId}"
        ) {
            return;
        }
        if (preg_match(
            '/^employee-account:[1-9][0-9]*$/D',
            $liability['recipient_reference'],
        ) === 1) {
            return;
        }
        throw new \DomainException(
            'Platební závazek nemá bezpečnou referenci příjemce.',
        );
    }

    /**
     * @param array{
     *   employee_id:?int,
     *   recipient_reference:string,
     *   source_snapshot_json:string,
     *   source_snapshot_hash:string
     * } $liability
     * @return array<string,mixed>
     */
    private function sourceSnapshot(array $liability): array
    {
        try {
            $decoded = json_decode(
                $liability['source_snapshot_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \DomainException(
                'Zdrojový snapshot závazku není platný.',
                previous: $exception,
            );
        }
        $source = $this->object($decoded, 'zdrojový snapshot závazku');
        $canonical = CanonicalJson::encode($source);
        if ($canonical !== $liability['source_snapshot_json']
            || !hash_equals(
                $liability['source_snapshot_hash'],
                hash('sha256', $canonical),
            )
            || ($source['schema_reference'] ?? null)
                !== 'payroll-payment-net-wage-source.v1'
            || ($source['person_id'] ?? null)
                !== $liability['employee_id']
            || ($source['recipient_reference'] ?? null)
                !== $liability['recipient_reference']
        ) {
            throw new \DomainException(
                'Závazek neodpovídá svému zmrazenému zdroji.',
            );
        }

        return $source;
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function sameFrozenTarget(array $left, array $right): bool
    {
        foreach ([
            'payment_target_id',
            'payment_target_hash',
            'payment_target_row_version',
            'payment_target_verification_hash',
        ] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function payerInstruction(
        int $supplierId,
        string $channel,
        string $exportFormat,
        string $payerReference,
        string $currencyCode,
    ): ?array {
        if ($channel === 'cash') {
            if ($exportFormat !== 'manual' || $payerReference !== 'cash') {
                throw new \DomainException(
                    'Hotovostní dávka vyžaduje ruční formát a referenci cash.',
                );
            }

            return null;
        }
        $accountHolderName = $this->batches->lockSupplierName(
            $supplierId,
        );
        if ($accountHolderName === null) {
            throw new \DomainException(
                'Firma plátce nemá úplný název pro platební dávku.',
            );
        }
        if ($exportFormat !== 'abo' && $exportFormat !== 'sepa') {
            throw new \DomainException(
                'Bankovní dávka vyžaduje formát ABO nebo SEPA.',
            );
        }
        if (preg_match(
            '/^currency:([1-9][0-9]*)$/D',
            $payerReference,
            $match,
        ) !== 1) {
            throw new \DomainException(
                'Bankovní dávka nemá bezpečnou referenci účtu plátce.',
            );
        }
        $payer = $this->batches->lockPayerCurrency(
            $supplierId,
            (int) $match[1],
        );
        if ($payer === null
            || !$payer['is_active']
            || $payer['code'] !== $currencyCode
        ) {
            throw new \DomainException(
                'Účet plátce není aktivní v měně dávky.',
            );
        }
        if ($exportFormat === 'abo') {
            if ($currencyCode !== 'CZK'
                || $payer['account_number'] === null
                || $payer['bank_code'] === null
            ) {
                throw new \DomainException(
                    'ABO dávka vyžaduje úplný korunový účet plátce.',
                );
            }
            $account = $this->czechAccount(
                $payer['account_number'] . '/' . $payer['bank_code'],
                'účet plátce',
            );

            return [
                'account_holder_name' => $accountHolderName,
                'account_number' => $account['account_number'],
                'bank_code' => $account['bank_code'],
            ];
        }
        if ($currencyCode !== 'EUR' || $payer['iban'] === null) {
            throw new \DomainException(
                'SEPA dávka vyžaduje eurový IBAN plátce.',
            );
        }
        $iban = $this->ibanValidator->normalize($payer['iban']);
        if (!$this->ibanValidator->isValid($iban)
            || ($payer['bic'] !== null
                && !$this->ibanValidator->isValidBic($payer['bic']))
        ) {
            throw new \DomainException(
                'SEPA účet plátce není platný.',
            );
        }

        return [
            'account_holder_name' => $accountHolderName,
            'iban' => $iban,
            'bic' => $payer['bic'] === null
                ? null
                : strtoupper(trim($payer['bic'])),
        ];
    }

    /**
     * @param array{
     *   recipient_reference:string,
     *   employee_id:?int,
     *   amount_minor:int,
     *   liabilities:list<array{
     *     id:int,
     *     reference:string,
     *     amount_minor:int,
     *     source_snapshot_hash:string
     *   }>,
     *   source:array<string,mixed>
     * } $group
     * @return array<string,mixed>
     */
    private function recipientInstruction(
        int $supplierId,
        string $exportFormat,
        string $currencyCode,
        string $plannedDate,
        array $group,
    ): array {
        $employeeId = $group['employee_id'];
        if ($employeeId === null) {
            throw new \LogicException('Příjemce nemá zaměstnance.');
        }
        $recipientName = $this->batches->lockEmployeeName(
            $supplierId,
            $employeeId,
        );
        if ($recipientName === null) {
            throw new \DomainException(
                'Příjemce mzdy nemá úplné jméno.',
            );
        }
        $base = [
            'schema_reference' =>
                'payroll-payment-recipient-instruction.v1',
            'recipient_reference' => $group['recipient_reference'],
            'amount_minor' => $group['amount_minor'],
            'currency_code' => $currencyCode,
            'planned_payment_date' => $plannedDate,
            'recipient_name' => $recipientName,
            'liabilities' => $group['liabilities'],
        ];
        if (str_starts_with(
            $group['recipient_reference'],
            'employee-cash:',
        )) {
            return $base;
        }
        if (preg_match(
            '/^employee-account:([1-9][0-9]*)$/D',
            $group['recipient_reference'],
            $match,
        ) !== 1) {
            throw new \DomainException(
                'Bankovní reference příjemce není platná.',
            );
        }
        $accountId = (int) $match[1];
        $source = $group['source'];
        $frozenAccountId = $source['payment_target_id'] ?? null;
        $frozenHash = $source['payment_target_hash'] ?? null;
        $frozenVersion = $source['payment_target_row_version'] ?? null;
        $frozenVerification = $source[
            'payment_target_verification_hash'
        ] ?? null;
        if ($frozenAccountId !== $accountId
            || !is_string($frozenHash)
            || preg_match('/^[0-9a-f]{64}$/D', $frozenHash) !== 1
            || $frozenHash === str_repeat('0', 64)
            || !is_int($frozenVersion)
            || $frozenVersion <= 0
            || !is_string($frozenVerification)
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $frozenVerification,
            ) !== 1
        ) {
            throw new \DomainException(
                'Závazek nemá úplný zmrazený cíl příjemce.',
            );
        }
        $account = $this->batches->lockPersonAccount(
            $supplierId,
            $employeeId,
            $accountId,
        );
        if ($account === null
            || !$account['is_active']
            || $account['effective_from'] > $plannedDate
            || ($account['effective_to'] !== null
                && $account['effective_to'] < $plannedDate)
            || $account['row_version'] !== $frozenVersion
            || !hash_equals($account['bank_account_hash'], $frozenHash)
        ) {
            throw new \DomainException(
                'Aktuální účet neodpovídá zmrazenému cíli závazku.',
            );
        }
        $verificationSource = $account['verification_source'];
        $verifiedOn = $account['verified_on'];
        $verifiedBy = $account['verified_by'];
        if (!is_string($verificationSource)
            || !in_array($verificationSource, [
                'employee_confirmation',
                'bank_document',
                'user_verified',
            ], true)
            || $verifiedOn === null
            || $verifiedBy === null
            || $this->date(
                $verifiedOn,
                'datum ověření účtu příjemce',
            ) > $plannedDate
        ) {
            throw new \DomainException(
                'Zmrazený platební cíl nemá úplné ověření.',
            );
        }
        $verificationHash = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-payment-target-verification.v1',
                'person_id' => $employeeId,
                'payment_target_id' => $accountId,
                'payment_target_hash' => $account['bank_account_hash'],
                'row_version' => $account['row_version'],
                'verification_source' => $verificationSource,
                'verified_on' => $verifiedOn,
                'verified_by' => $verifiedBy,
            ]),
        );
        if (!hash_equals($frozenVerification, $verificationHash)) {
            throw new \DomainException(
                'Ověření účtu neodpovídá zmrazenému cíli závazku.',
            );
        }
        $plaintext = $this->sensitiveData->reveal(
            $account['bank_account_ciphertext'],
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
            $accountId,
        );
        $lookupHash = bin2hex($this->sensitiveData->lookupHash(
            $plaintext,
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
        ));
        if (!hash_equals($account['bank_account_hash'], $lookupHash)) {
            throw new \DomainException(
                'Obsah účtu neodpovídá zmrazenému cíli závazku.',
            );
        }
        if ($exportFormat === 'abo') {
            $parsed = $this->czechAccount($plaintext, 'účet příjemce');

            return [
                ...$base,
                'account_number' => $parsed['account_number'],
                'bank_code' => $parsed['bank_code'],
            ];
        }
        $iban = $this->ibanValidator->normalize($plaintext);
        if (!$this->ibanValidator->isValid($iban)) {
            throw new \DomainException(
                'Účet příjemce není platný IBAN pro SEPA.',
            );
        }

        return [...$base, 'iban' => $iban];
    }

    /**
     * @return array{account_number:string,bank_code:string}
     */
    private function czechAccount(string $value, string $context): array
    {
        try {
            $parsed = $this->czechBankAccountValidator->parse($value);
        } catch (\InvalidArgumentException $exception) {
            throw new \DomainException(
                "{$context} není platný český bankovní účet.",
                0,
                $exception,
            );
        }

        return [
            'account_number' => $parsed['account_number'],
            'bank_code' => $parsed['bank_code'],
        ];
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$context} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException(
                    "{$context} musí mít textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function date(mixed $value, string $context): string
    {
        if (!is_string($value)) {
            throw new \DomainException("{$context} není datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \DomainException("{$context} není platné datum.");
        }

        return $value;
    }

    private function addAmounts(int $left, int $right): int
    {
        if ($right <= 0 || $left > PHP_INT_MAX - $right) {
            throw new \OverflowException(
                'Součet částek platební dávky není platný.',
            );
        }

        return $left + $right;
    }
}
