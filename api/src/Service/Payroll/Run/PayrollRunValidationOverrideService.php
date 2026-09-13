<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

/**
 * Schválení a odvolání výjimky u mzdové validace — druhá půlka override.
 *
 * ─── CO TU CHYBĚLO ──────────────────────────────────────────────────────────
 *
 * Migrace 1210 založila u `payroll_run_validations` sloupce `override_reason`,
 * `overridden_by` a `overridden_at` a {@see PayrollRunWorkflow} na nich staví
 * podmínku schválení běhu: `unresolvedOverrideCount > 0` zastaví příkaz
 * `approve`. Jenže k těm sloupcům nevedla ŽÁDNÁ routa ani obrazovka — nikdo je
 * nikdy nenastavil. Každé varování s `requires_override = 1` proto natrvalo
 * zablokovalo mzdový běh a nešlo ho odklidit; jediná cesta ven byla zásah
 * přímo v databázi. Tahle služba tu půlku doplňuje.
 *
 * ─── KDO SMÍ SCHVALOVAT ─────────────────────────────────────────────────────
 *
 * Právo `payroll.approve` („Schválit mzdový běh"). Schválení výjimky je
 * rozhodnutí „vím o vadě a přesto se vyplácí" — tedy věcně část schválení
 * běhu, ne jeho příprava. Slabší právo (`payroll.review`) by znamenalo, že
 * překážku ke schválení odklízí někdo, kdo sám schválit nesmí, a schvalovatel
 * by pak podepisoval cizí rozhodnutí, aniž by ho mohl odmítnout jinak než
 * vrácením celého běhu.
 *
 * ─── ČTYŘI OČI: POLITIKA, NE BLOKACE ────────────────────────────────────────
 *
 * Výjimku smí schválit i ten, kdo revizi počítal, kontroloval a následně
 * schválí. {@see PayrollRunWorkflow} vyžaduje jednotlivé odborné kroky a
 * neměnnou auditní stopu, nikoli druhého uživatele. Historické pole
 * `four_eyes_met` zůstává jen kompatibilní auditní metadatou; nikdy neblokuje
 * výjimku ani mzdový běh.
 *
 * ─── HROMADNĚ ───────────────────────────────────────────────────────────────
 *
 * Firma s 225 zaměstnanci dostane 225× „chybí přihláška u ČSSZ" a 225× „chybí
 * oznámení zdravotní pojišťovně" — po jednom by to bylo 450 dialogů se stejným
 * odůvodněním. {@see self::grantMany()} schválí celou skupinu kontrol jednoho
 * kódu jedním příkazem a jedním odůvodněním, ale každou validaci provede
 * TÝMIŽ kroky jako jednotlivé schválení (stejné kontroly, stejný zápis, stejná
 * auditní událost na každou validaci). Rozdíl je jen v tom, že běh se zamkne,
 * `row_version` posune a potvrzení příkazu zapíše jednou za celou dávku.
 *
 * ─── ODVOLATELNOST ──────────────────────────────────────────────────────────
 *
 * Výjimku lze vzít zpět, dokud běh není schválený ({@see self::MUTABLE_STATUSES}).
 * Po schválení už ne: schválená revize je neměnný doklad a odebrání výjimky by
 * zpětně měnilo podklad, na jehož základě se vyplatilo. Že výjimka existovala,
 * zůstane v `payroll_run_events` navždy — události jsou append-only na úrovni
 * databázového triggeru, takže ani odvolání minulost nepřepíše.
 */
final class PayrollRunValidationOverrideService
{
    private const SAVEPOINT = 'payroll_run_validation_override';

    public const COMMAND_GRANT = 'validation_override';

    public const COMMAND_REVOKE = 'validation_override_revoke';

    public const COMMAND_GRANT_BULK = 'validation_override_bulk';

    public const EVENT_GRANTED = 'validation_override';

    public const EVENT_REVOKED = 'validation_override_revoked';

    /** Strop jedné dávky — víc validací jednoho kódu běh nevyrobí ani u velké firmy. */
    public const BULK_LIMIT = 5000;

    /**
     * Stavy běhu, ve kterých se s výjimkou ještě smí hýbat.
     *
     * Končí před `approved` — od schválení dál je revize doklad, ne rozpracovaný
     * podklad. `correction_pending` tu chybí záměrně: oprava vytvoří NOVOU revizi
     * s vlastní sadou validací, takže rozhodnutí se dělá znovu, ne se opravuje
     * to staré.
     *
     * @var list<string>
     */
    private const MUTABLE_STATUSES = [
        'inputs_locked',
        'calculated',
        'reviewed',
        'reopened',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRunRepository $runs,
    ) {}

    public function grant(
        int $supplierId,
        int $runId,
        int $validationId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        mixed $reason,
    ): PayrollRunValidationOverrideResult {
        return $this->execute(
            $supplierId,
            $runId,
            $validationId,
            $expectedVersion,
            $idempotencyKey,
            $actorUserId,
            PayrollRunOverrideReason::normalize($reason),
            true,
        );
    }

    public function revoke(
        int $supplierId,
        int $runId,
        int $validationId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        mixed $reason = null,
    ): PayrollRunValidationOverrideResult {
        return $this->execute(
            $supplierId,
            $runId,
            $validationId,
            $expectedVersion,
            $idempotencyKey,
            $actorUserId,
            PayrollRunOverrideReason::normalizeOptional($reason),
            false,
        );
    }

    /**
     * Hromadné schválení výjimky u všech dosud neschválených kontrol jednoho
     * kódu v aktuální revizi běhu.
     *
     * `$validationIds` zúží dávku na konkrétní validace (to, co měl uživatel
     * na obrazovce). Každá z nich musí patřit k běhu, k jeho aktuální revizi,
     * nést daný kód a vyžadovat schválení — jinak se neschválí nic. Už
     * schválené se přeskočí, takže opakované volání nic nezdvojí.
     *
     * @param list<int>|null $validationIds
     */
    public function grantMany(
        int $supplierId,
        int $runId,
        string $code,
        ?array $validationIds,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        mixed $reason,
    ): PayrollRunValidationBulkOverrideResult {
        $reason = PayrollRunOverrideReason::normalize($reason);
        $this->assertCommand($supplierId, $runId, 1, $expectedVersion, $actorUserId);
        $code = trim($code);
        if (preg_match('/^[a-z0-9_]{1,64}$/', $code) !== 1) {
            throw new \InvalidArgumentException('Kód kontroly není platný.');
        }
        if ($validationIds !== null) {
            $validationIds = array_values(array_unique(array_map('intval', $validationIds)));
            sort($validationIds);
            if ($validationIds === []) {
                throw new \InvalidArgumentException(
                    'Seznam kontrol k hromadnému schválení je prázdný.',
                );
            }
            if (count($validationIds) > self::BULK_LIMIT || min($validationIds) <= 0) {
                throw new \InvalidArgumentException(
                    'Seznam kontrol k hromadnému schválení není platný.',
                );
            }
        }
        [$keyHashBinary, $keyHashHex] = $this->keyHashes($idempotencyKey);
        $requestHash = hash('sha256', CanonicalJson::encode([
            'actor_user_id' => $actorUserId,
            'command' => self::COMMAND_GRANT_BULK,
            'expected_row_version' => $expectedVersion,
            'reason' => $reason,
            'run_id' => $runId,
            'supplier_id' => $supplierId,
            'validation_code' => $code,
            'validation_ids' => $validationIds,
        ]));

        $pdo = $this->db->pdo();
        $nested = $this->begin($pdo);
        try {
            $run = $this->lockRun($supplierId, $runId);

            $receipt = $this->runs->commandReceipt($supplierId, $keyHashBinary);
            if ($receipt !== null) {
                $this->assertReceiptMatches($receipt, $runId, self::COMMAND_GRANT_BULK, $requestHash);
                $result = $this->replayBulk($supplierId, $runId, $receipt);
                $this->finish($pdo, $nested);
                return $result;
            }

            $status = $this->assertMutable($run, $expectedVersion, true);
            $currentRevision = $this->runs->currentRevision($supplierId, $runId)
                ?? throw new \DomainException(
                    'Výjimku lze řešit jen u validací aktuální revize běhu.',
                );
            $revisionId = (int) $currentRevision['id'];

            $candidateIds = $validationIds ?? array_map(
                static fn (array $row): int => (int) $row['id'],
                array_values(array_filter(
                    $this->runs->validations($supplierId, $revisionId),
                    static fn (array $row): bool => $row['code'] === $code
                        && $row['requires_override'] === true,
                )),
            );

            $pending = [];
            $skipped = 0;
            foreach ($candidateIds as $validationId) {
                $validation = $this->lockOverridable(
                    $supplierId,
                    $runId,
                    $validationId,
                    $currentRevision,
                );
                if ((string) $validation['code'] !== $code) {
                    throw new \DomainException(
                        'Kontrola č. ' . $validationId . ' nepatří do skupiny ' . $code . '.',
                    );
                }
                if ($validation['overridden_at'] !== null) {
                    $skipped++;
                    continue;
                }
                $pending[] = $validation;
            }

            [$calculatedBy, $fourEyesMet] = $this->fourEyes($currentRevision, $actorUserId);
            if ($pending === []) {
                // Není co schválit — nic se nezapíše a `row_version` zůstane.
                $this->finish($pdo, $nested);
                return new PayrollRunValidationBulkOverrideResult(
                    $run,
                    [],
                    0,
                    $skipped,
                    $fourEyesMet,
                    false,
                );
            }

            foreach ($pending as $validation) {
                $this->applyGrant($supplierId, (int) $validation['id'], $reason, $actorUserId, $expectedVersion);
            }
            $run = $this->runs->updateRun(
                $supplierId,
                $runId,
                $expectedVersion,
                $status,
                null,
                $actorUserId,
            );
            $grantedIds = [];
            foreach ($pending as $validation) {
                $grantedIds[] = (int) $validation['id'];
                $this->recordEvent(
                    $supplierId,
                    $runId,
                    $revisionId,
                    $validation,
                    $run,
                    $status,
                    $calculatedBy,
                    $fourEyesMet,
                    $keyHashHex,
                    $requestHash,
                    $actorUserId,
                    $reason,
                    true,
                    ['bulk_count' => count($pending)],
                );
            }
            $this->runs->insertCommandReceipt(
                $supplierId,
                $runId,
                $revisionId,
                self::COMMAND_GRANT_BULK,
                $keyHashBinary,
                $requestHash,
                $expectedVersion,
                $status,
                $status,
                [
                    'four_eyes_met' => $fourEyesMet,
                    'granted' => true,
                    'granted_count' => count($grantedIds),
                    'row_version' => (int) $run['row_version'],
                    'run_id' => $runId,
                    'skipped_count' => $skipped,
                    'validation_code' => $code,
                    'validation_ids' => $grantedIds,
                ],
                $actorUserId,
            );

            $stored = $this->storedValidations($supplierId, $revisionId, $grantedIds);
            $this->finish($pdo, $nested);

            return new PayrollRunValidationBulkOverrideResult(
                $run,
                $stored,
                count($grantedIds),
                $skipped,
                $fourEyesMet,
                false,
            );
        } catch (\Throwable $e) {
            $this->rollback($pdo, $nested);
            throw $e;
        }
    }

    private function execute(
        int $supplierId,
        int $runId,
        int $validationId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        ?string $reason,
        bool $granting,
    ): PayrollRunValidationOverrideResult {
        $this->assertCommand($supplierId, $runId, $validationId, $expectedVersion, $actorUserId);
        $command = $granting ? self::COMMAND_GRANT : self::COMMAND_REVOKE;
        [$keyHashBinary, $keyHashHex] = $this->keyHashes($idempotencyKey);
        $requestHash = hash('sha256', CanonicalJson::encode([
            'actor_user_id' => $actorUserId,
            'command' => $command,
            'expected_row_version' => $expectedVersion,
            'reason' => $reason,
            'run_id' => $runId,
            'supplier_id' => $supplierId,
            'validation_id' => $validationId,
        ]));

        $pdo = $this->db->pdo();
        $nested = $this->begin($pdo);
        try {
            // Zámek na běhu je jediné pořadové místo celé operace: dvě souběžná
            // schválení téže validace se tady seřadí za sebou, takže druhé v pořadí
            // uvidí už zapsaný `overridden_at` (a neplatnou `row_version`) místo
            // aby ten první přepsalo.
            $run = $this->lockRun($supplierId, $runId);

            $receipt = $this->runs->commandReceipt($supplierId, $keyHashBinary);
            if ($receipt !== null) {
                $this->assertReceiptMatches($receipt, $runId, $command, $requestHash);
                $result = $this->replay($supplierId, $runId, $validationId, $receipt);
                $this->finish($pdo, $nested);
                return $result;
            }

            $status = $this->assertMutable($run, $expectedVersion, $granting);
            $currentRevision = $this->runs->currentRevision($supplierId, $runId);
            $validation = $this->lockOverridable(
                $supplierId,
                $runId,
                $validationId,
                $currentRevision,
            );
            $revisionId = (int) $validation['revision_id'];

            if ($granting) {
                if ($validation['overridden_at'] !== null) {
                    throw new \DomainException(
                        'Výjimka u této kontroly je už schválená.',
                    );
                }
                $this->applyGrant($supplierId, $validationId, (string) $reason, $actorUserId, $expectedVersion);
            } else {
                if ($validation['overridden_at'] === null) {
                    throw new \DomainException(
                        'U této kontroly není co odvolávat — výjimka schválená není.',
                    );
                }
                if (!$this->runs->clearValidationOverride($supplierId, $validationId)) {
                    // Nemělo by nastat — běh držíme zamčený. Když ano, je to souběh,
                    // který se nesmí utopit v tichu.
                    throw new PayrollRunConflictException($expectedVersion);
                }
            }

            /** @var array<string,mixed> $currentRevision lockOverridable ověřil, že existuje */
            [$calculatedBy, $fourEyesMet] = $this->fourEyes($currentRevision, $actorUserId);

            $run = $this->runs->updateRun(
                $supplierId,
                $runId,
                $expectedVersion,
                $status,
                null,
                $actorUserId,
            );

            $this->recordEvent(
                $supplierId,
                $runId,
                $revisionId,
                $validation,
                $run,
                $status,
                $calculatedBy,
                $fourEyesMet,
                $keyHashHex,
                $requestHash,
                $actorUserId,
                $reason,
                $granting,
            );
            $this->runs->insertCommandReceipt(
                $supplierId,
                $runId,
                $revisionId,
                $command,
                $keyHashBinary,
                $requestHash,
                $expectedVersion,
                $status,
                $status,
                [
                    'four_eyes_met' => $fourEyesMet,
                    'granted' => $granting,
                    'row_version' => (int) $run['row_version'],
                    'run_id' => $runId,
                    'validation_id' => $validationId,
                ],
                $actorUserId,
            );

            $stored = $this->runs->validation($supplierId, $validationId)
                ?? throw new \RuntimeException(
                    'Validace mzdového běhu po zápisu výjimky zmizela.',
                );
            $this->finish($pdo, $nested);

            return new PayrollRunValidationOverrideResult(
                $run,
                $stored,
                $granting,
                $fourEyesMet,
                false,
            );
        } catch (\Throwable $e) {
            $this->rollback($pdo, $nested);
            throw $e;
        }
    }

    private function assertCommand(
        int $supplierId,
        int $runId,
        int $validationId,
        int $expectedVersion,
        int $actorUserId,
    ): void {
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Uživatel příkazu není platný.');
        }
        if ($supplierId <= 0
            || $runId <= 0
            || $validationId <= 0
            || $expectedVersion <= 0
        ) {
            throw new \InvalidArgumentException(
                'Identifikace mzdové výjimky není platná.',
            );
        }
    }

    /** @return array{string,string} binární a hex otisk idempotency klíče */
    private function keyHashes(string $idempotencyKey): array
    {
        $normalizedKey = trim($idempotencyKey);
        if (mb_strlen($normalizedKey) < 8 || mb_strlen($normalizedKey) > 190) {
            throw new \InvalidArgumentException(
                'Idempotency key musí mít 8 až 190 znaků.',
            );
        }
        return [hash('sha256', $normalizedKey, true), hash('sha256', $normalizedKey)];
    }

    /** @return array<string,mixed> */
    private function lockRun(int $supplierId, int $runId): array
    {
        return $this->runs->lock($supplierId, $runId)
            ?? throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
    }

    /** @param array<string,mixed> $run */
    private function assertMutable(array $run, int $expectedVersion, bool $granting): string
    {
        $currentVersion = (int) $run['row_version'];
        if ($currentVersion !== $expectedVersion) {
            throw new PayrollRunConflictException($currentVersion);
        }
        $status = (string) $run['status'];
        if (!in_array($status, self::MUTABLE_STATUSES, true)) {
            throw new \DomainException($granting
                ? 'Ve stavu ' . $status . ' už výjimku schválit nelze — '
                    . 'rozhodnutí patří k běhu před jeho schválením.'
                : 'Schválenou výjimku lze vzít zpět jen dokud běh není '
                    . 'schválený; potom by to přepisovalo historii.');
        }
        return $status;
    }

    /**
     * Validace pod zámkem, ověřená proti běhu z URL a jeho aktuální revizi.
     *
     * `payroll_run_validations` nese jen `revision_id`, takže příslušnost
     * k běhu (a tím i k firmě) se ověřuje přes revizi — jinak by šlo cizí
     * validací hýbat přes vlastní běh.
     *
     * @param array<string,mixed>|null $currentRevision
     * @return array<string,mixed>
     */
    private function lockOverridable(
        int $supplierId,
        int $runId,
        int $validationId,
        ?array $currentRevision,
    ): array {
        $validation = $this->runs->lockValidation($supplierId, $validationId);
        if ($validation === null
            || (int) $validation['run_id'] !== $runId
        ) {
            throw new \OutOfBoundsException(
                'Validace mzdového běhu nebyla nalezena.',
            );
        }
        if ($currentRevision === null
            || (int) $currentRevision['id'] !== (int) $validation['revision_id']
        ) {
            throw new \DomainException(
                'Výjimku lze řešit jen u validací aktuální revize běhu.',
            );
        }
        if (!(bool) $validation['requires_override']) {
            throw new \DomainException(
                'Tato kontrola schválení výjimky nevyžaduje.',
            );
        }
        return $validation;
    }

    private function applyGrant(
        int $supplierId,
        int $validationId,
        string $reason,
        int $actorUserId,
        int $expectedVersion,
    ): void {
        if (!$this->runs->applyValidationOverride(
            $supplierId,
            $validationId,
            $reason,
            $actorUserId,
        )) {
            // Nemělo by nastat — běh držíme zamčený. Když ano, je to souběh,
            // který se nesmí utopit v tichu.
            throw new PayrollRunConflictException($expectedVersion);
        }
    }

    /**
     * @param array<string,mixed> $currentRevision
     * @return array{?int,bool}
     */
    private function fourEyes(array $currentRevision, int $actorUserId): array
    {
        $calculatedBy = $currentRevision['calculated_by'] === null
            ? null
            : (int) $currentRevision['calculated_by'];
        return [$calculatedBy, $calculatedBy === null || $calculatedBy !== $actorUserId];
    }

    /**
     * Auditní událost jedné validace — jednotlivé i hromadné schválení zapíše
     * na každou validaci tutéž událost se stejnými metadaty.
     *
     * @param array<string,mixed> $validation
     * @param array<string,mixed> $run
     * @param array<string,mixed> $extra
     */
    private function recordEvent(
        int $supplierId,
        int $runId,
        int $revisionId,
        array $validation,
        array $run,
        string $status,
        ?int $calculatedBy,
        bool $fourEyesMet,
        string $keyHashHex,
        string $requestHash,
        int $actorUserId,
        ?string $reason,
        bool $granting,
        array $extra = [],
    ): void {
        $metadata = [
            ...$extra,
            'calculated_by' => $calculatedBy,
            'four_eyes_met' => $fourEyesMet,
            'idempotency_key_hash' => $keyHashHex,
            'request_hash' => $requestHash,
            'row_version' => (int) $run['row_version'],
            'run_status' => $status,
            'validation_code' => (string) $validation['code'],
            'validation_entity_id' => $validation['entity_id'] === null
                ? null
                : (int) $validation['entity_id'],
            'validation_entity_type' => (string) $validation['entity_type'],
            'validation_id' => (int) $validation['id'],
            'validation_severity' => (string) $validation['severity'],
        ];
        if (!$granting) {
            // Odvolání smaže `override_reason` z validace; kdyby ho neneslo
            // sem, původní odůvodnění by z historie zmizelo úplně.
            $metadata['revoked_reason'] = $validation['override_reason'] === null
                ? null
                : (string) $validation['override_reason'];
        }
        $this->runs->insertEvent(
            $supplierId,
            $runId,
            $revisionId,
            $granting ? self::EVENT_GRANTED : self::EVENT_REVOKED,
            null,
            null,
            $actorUserId,
            $reason,
            $metadata,
        );
    }

    /** @param array<string,mixed> $receipt */
    private function assertReceiptMatches(
        array $receipt,
        int $runId,
        string $command,
        string $requestHash,
    ): void {
        if ((int) $receipt['run_id'] !== $runId
            || (string) $receipt['command_name'] !== $command
            || !hash_equals((string) $receipt['request_hash'], $requestHash)
        ) {
            throw new PayrollRunIdempotencyException();
        }
    }

    /**
     * @param array<string,mixed> $receipt
     */
    private function replay(
        int $supplierId,
        int $runId,
        int $validationId,
        array $receipt,
    ): PayrollRunValidationOverrideResult {
        $run = $this->runs->find($supplierId, $runId)
            ?? throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
        $validation = $this->runs->validation($supplierId, $validationId)
            ?? throw new \OutOfBoundsException(
                'Validace mzdového běhu nebyla nalezena.',
            );
        $result = is_array($receipt['result'] ?? null) ? $receipt['result'] : [];

        return new PayrollRunValidationOverrideResult(
            $run,
            $validation,
            (bool) ($result['granted'] ?? false),
            (bool) ($result['four_eyes_met'] ?? true),
            true,
        );
    }

    /** @param array<string,mixed> $receipt */
    private function replayBulk(
        int $supplierId,
        int $runId,
        array $receipt,
    ): PayrollRunValidationBulkOverrideResult {
        $run = $this->runs->find($supplierId, $runId)
            ?? throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
        $result = is_array($receipt['result'] ?? null) ? $receipt['result'] : [];
        $ids = array_map('intval', is_array($result['validation_ids'] ?? null) ? $result['validation_ids'] : []);

        return new PayrollRunValidationBulkOverrideResult(
            $run,
            $receipt['revision_id'] === null
                ? []
                : $this->storedValidations($supplierId, (int) $receipt['revision_id'], $ids),
            (int) ($result['granted_count'] ?? count($ids)),
            (int) ($result['skipped_count'] ?? 0),
            (bool) ($result['four_eyes_met'] ?? true),
            true,
        );
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function storedValidations(int $supplierId, int $revisionId, array $ids): array
    {
        $wanted = array_flip($ids);
        return array_values(array_filter(
            $this->runs->validations($supplierId, $revisionId),
            static fn (array $row): bool => isset($wanted[(int) $row['id']]),
        ));
    }

    private function begin(PDO $pdo): bool
    {
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        return $nested;
    }

    private function finish(PDO $pdo, bool $nested): void
    {
        if ($nested) {
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->commit();
        }
    }

    private function rollback(PDO $pdo, bool $nested): void
    {
        if ($nested) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
