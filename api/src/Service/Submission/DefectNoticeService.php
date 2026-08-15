<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\Submission\SubmissionDefectNoticeRepository;
use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Clock\ClockInterface;

/**
 * Evidence výzev k odstranění vad podání (§ 74 daňového řádu).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to musí existovat
 * ═══════════════════════════════════════════════════════════════════════════
 * Doručenka z datové schránky dokládá, že podání DORAZILO. O tom, jestli ho
 * úřad přijal, neříká nic — a když ho nepřijme, přijde po dnech výzva podle
 * § 74 DŘ. Aplikace ji dosud neznala vůbec, takže vadné podání tiše zestárlo:
 * ve frontě svítilo „doručeno", zatímco lhůta k odstranění vady běžela a
 * uplynula. § 74 odst. 4 na to má jasnou odpověď — podání se stává NEÚČINNÝM.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co se sem NEZAPISUJE
 * ═══════════════════════════════════════════════════════════════════════════
 * Ani jedna cesta téhle třídy nesahá na `submission_outbox.acceptance_state`.
 * Výzva je totiž pravý opak důkazu o přijetí: dokládá, že podání přijaté
 * NENÍ. Osu vyřízení posouvá jen protokol nebo lidské potvrzení
 * ({@see \MyInvoice\Service\Submission\Channel\AcceptanceEvidence}) — a pro
 * výzvu tam slovo nikdy nebude, stejně jako ho tam nemá doručenka.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Fail-closed napříč
 * ═══════════════════════════════════════════════════════════════════════════
 *   - Bez termínu je stav {@see DefectNoticeStatus::Unknown}, ne „v pořádku".
 *   - Bez písmene § 74 odst. 1 je následek {@see DefectConsequence::Unknown},
 *     ne „neúčinnost nehrozí".
 *   - Prázdný seznam výzev znamená „žádná zaevidovaná", ne „žádná nepřišla" —
 *     aplikace výzvy sama nerozpoznává ({@see InboxMessageClassifier} u nich
 *     legitimně končí na `unclassified`) a UI to musí říct nahlas.
 */
final readonly class DefectNoticeService
{
    private const AUTHORITY_KINDS = ['tax_office', 'cssz', 'health_insurer', 'other'];
    private const ENVIRONMENTS = ['production', 'test'];

    public function __construct(
        private SubmissionDefectNoticeRepository $notices,
        private SubmissionInboxRepository $inbox,
        private SubmissionOutboxRepository $outbox,
        private DefectNoticeAssessor $assessor,
        private DeliveryResolutionService $delivery,
        private ActivityLogger $activity,
        private ClockInterface $clock,
    ) {}

    public function isSupported(): bool
    {
        return $this->notices->isAvailable();
    }

    /**
     * Zaeviduje výzvu.
     *
     * @param array{
     *   outbox_id?:?int, inbox_message_id?:?int, response_outbox_id?:?int,
     *   notice_reference?:?string, authority_kind?:?string, defect_ground?:?string,
     *   delivered_on?:?string, respond_by_on?:?string, stated_period_days?:?int,
     *   note?:?string
     * } $input
     * @return array<string,mixed>
     */
    public function record(int $supplierId, string $environment, array $input, ?int $userId = null): array
    {
        $this->assertEnvironment($environment);
        $this->assertSupported();

        $inboxMessageId = self::nullableInt($input['inbox_message_id'] ?? null);
        $deliveredOn = self::date($input['delivered_on'] ?? null, 'den doručení výzvy');

        if ($inboxMessageId !== null) {
            $existing = $this->notices->findByInboxMessage($supplierId, $inboxMessageId);
            if ($existing !== null) {
                // Tatáž zpráva nesmí vyrobit druhou výzvu — jinak by se jedna
                // lhůta počítala dvakrát a uživatel by nevěděl, která platí.
                return $this->decorate($existing) + ['created' => false];
            }
            $message = $this->inbox->findById($supplierId, $inboxMessageId);
            if ($message === null || (string) $message['environment'] !== $environment) {
                throw new SubmissionChannelException(
                    'inbox_message_not_found',
                    'Zpráva, ze které výzva pochází, v tomhle prostředí není.',
                    404,
                );
            }
            // Den doručení se přebírá z už uloženého závěru (§ 17 odst. 3/4),
            // ne z času stažení. Když ho ještě nemáme, dopočítá se teď.
            $deliveredOn ??= self::date($message['delivered_on'] ?? null, 'den doručení výzvy')
                ?? $this->delivery->evaluate($message)->deliveredOn;
        }

        $outboxId = $this->assertOutbox($supplierId, $environment, self::nullableInt($input['outbox_id'] ?? null));
        $responseOutboxId = $this->assertOutbox(
            $supplierId,
            $environment,
            self::nullableInt($input['response_outbox_id'] ?? null),
        );

        $ground = self::ground($input['defect_ground'] ?? null);
        $assessment = $this->assessor->assess(
            $deliveredOn,
            self::date($input['respond_by_on'] ?? null, 'konec lhůty'),
            self::nullableInt($input['stated_period_days'] ?? null),
            $ground,
            null,
            $this->today(),
        );

        $row = $this->notices->create([
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'outbox_id' => $outboxId,
            'inbox_message_id' => $inboxMessageId,
            'notice_reference' => self::text($input['notice_reference'] ?? null, 128),
            'authority_kind' => self::authorityKind($input['authority_kind'] ?? null),
            'defect_ground' => $ground->value,
            'consequence' => $ground->consequence()->value,
            'delivered_on' => $deliveredOn?->format('Y-m-d'),
            'respond_by_on' => $assessment->respondBy?->format('Y-m-d'),
            'respond_by_source' => $assessment->respondBySource,
            'stated_period_days' => $assessment->respondBySource === 'derived_from_days'
                ? self::nullableInt($input['stated_period_days'] ?? null)
                : null,
            'respond_by_shifted' => $assessment->respondByShifted ? 1 : 0,
            'status' => $assessment->status->value,
            'responded_on' => null,
            'response_outbox_id' => $responseOutboxId,
            'outcome' => $assessment->outcome->value,
            'note' => self::text($input['note'] ?? null, 1000),
            'created_by' => $userId,
        ]);

        $this->activity->log(
            'submission_defect_notice_recorded',
            $userId,
            'submission_defect_notice',
            (int) $row['id'],
            [
                'legal_basis' => '§ 74 daňového řádu',
                'defect_ground' => $ground->value,
                'consequence' => $ground->consequence()->value,
                'respond_by_on' => $row['respond_by_on'],
            ],
            null,
            null,
            $supplierId,
        );

        return $this->decorate($row) + ['created' => true];
    }

    /**
     * Zaznamená, že jsme vadu odstranili.
     *
     * `respondedOn` je den, kdy naše podání odešlo — ne kdy jsme si to
     * poznamenali. Rozdíl mezi „stihli jsme to" a „nestihli" se u lhůty
     * počítá na dny.
     *
     * @return array<string,mixed>
     */
    public function recordResponse(
        int $supplierId,
        int $noticeId,
        int $expectedRowVersion,
        string $respondedOn,
        ?int $responseOutboxId,
        ?int $userId = null,
    ): array {
        $this->assertSupported();
        $notice = $this->requireNotice($supplierId, $noticeId);
        $responded = self::date($respondedOn, 'den odpovědi');
        if ($responded === null) {
            throw new SubmissionChannelException('invalid_response_date', 'Den odpovědi není platné datum.', 400);
        }
        if ($notice['responded_on'] !== null) {
            throw new SubmissionChannelException(
                'response_already_recorded',
                'Odpověď na tuhle výzvu už je zaznamenaná. Přepsat ji nejde — je to doklad.',
                409,
            );
        }

        return $this->reassess(
            $supplierId,
            $notice,
            $expectedRowVersion,
            [
                'responded_on' => $responded,
                'response_outbox_id' => $this->assertOutbox(
                    $supplierId,
                    (string) $notice['environment'],
                    $responseOutboxId,
                ) ?? self::nullableInt($notice['response_outbox_id']),
            ],
            $userId,
            'submission_defect_notice_answered',
        );
    }

    /**
     * Doplní nebo opraví údaje z papíru a přepočítá, co z toho plyne.
     *
     * @param array{
     *   outbox_id?:?int, notice_reference?:?string, authority_kind?:?string,
     *   defect_ground?:?string, delivered_on?:?string, respond_by_on?:?string,
     *   stated_period_days?:?int, note?:?string, withdrawn?:bool
     * } $input
     * @return array<string,mixed>
     */
    public function amend(int $supplierId, int $noticeId, int $expectedRowVersion, array $input, ?int $userId = null): array
    {
        $this->assertSupported();
        $notice = $this->requireNotice($supplierId, $noticeId);

        return $this->reassess(
            $supplierId,
            $notice,
            $expectedRowVersion,
            [
                'outbox_id' => array_key_exists('outbox_id', $input)
                    ? $this->assertOutbox($supplierId, (string) $notice['environment'], self::nullableInt($input['outbox_id']))
                    : self::nullableInt($notice['outbox_id']),
                'notice_reference' => array_key_exists('notice_reference', $input)
                    ? self::text($input['notice_reference'], 128)
                    : $notice['notice_reference'],
                'authority_kind' => array_key_exists('authority_kind', $input)
                    ? self::authorityKind($input['authority_kind'])
                    : (string) $notice['authority_kind'],
                'ground' => array_key_exists('defect_ground', $input)
                    ? self::ground($input['defect_ground'])
                    : self::ground($notice['defect_ground']),
                'delivered_on' => array_key_exists('delivered_on', $input)
                    ? self::date($input['delivered_on'], 'den doručení výzvy')
                    : self::date($notice['delivered_on'], 'den doručení výzvy'),
                'stated_respond_by' => array_key_exists('respond_by_on', $input)
                    ? self::date($input['respond_by_on'], 'konec lhůty')
                    : ((string) $notice['respond_by_source'] === 'stated_in_notice'
                        ? self::date($notice['respond_by_on'], 'konec lhůty')
                        : null),
                'stated_period_days' => array_key_exists('stated_period_days', $input)
                    ? self::nullableInt($input['stated_period_days'])
                    : self::nullableInt($notice['stated_period_days']),
                'note' => array_key_exists('note', $input) ? self::text($input['note'], 1000) : $notice['note'],
                'withdrawn' => (bool) ($input['withdrawn'] ?? ((string) $notice['status'] === DefectNoticeStatus::Withdrawn->value)),
            ],
            $userId,
            'submission_defect_notice_amended',
        );
    }

    /**
     * Výzvy firmy i s aktuálním vyhodnocením.
     *
     * @return array{supported:bool,items:list<array<string,mixed>>,notice:string}
     */
    public function list(int $supplierId, string $environment, bool $openOnly = false): array
    {
        $this->assertEnvironment($environment);
        if (!$this->isSupported()) {
            return [
                'supported' => false,
                'items' => [],
                'notice' => 'Evidence výzev není v databázi k dispozici (chybí migrace 1394). '
                    . 'Prázdný seznam tady neznamená, že žádná výzva nepřišla.',
            ];
        }

        return [
            'supported' => true,
            'items' => array_map(
                fn (array $row): array => $this->decorate($row),
                $this->notices->listForSupplier($supplierId, $environment, $openOnly),
            ),
            'notice' => 'Výzvy sem zapisuje člověk. Aplikace je z datové schránky sama nerozpoznává, '
                . 'takže prázdný seznam znamená „žádná zaevidovaná", ne „žádná nepřišla".',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listForSubmission(int $supplierId, int $outboxId): array
    {
        if (!$this->isSupported()) {
            return [];
        }

        return array_map(
            fn (array $row): array => $this->decorate($row),
            $this->notices->listForOutbox($supplierId, $outboxId),
        );
    }

    // ───────────────────────── interní ─────────────────────────

    /**
     * @param array<string,mixed> $notice
     * @param array{
     *   outbox_id?:?int, notice_reference?:?string, authority_kind?:string,
     *   ground?:DefectGround, delivered_on?:?\DateTimeImmutable,
     *   stated_respond_by?:?\DateTimeImmutable, stated_period_days?:?int,
     *   responded_on?:?\DateTimeImmutable, response_outbox_id?:?int,
     *   note?:?string, withdrawn?:bool
     * } $changes
     * @return array<string,mixed>
     */
    private function reassess(
        int $supplierId,
        array $notice,
        int $expectedRowVersion,
        array $changes,
        ?int $userId,
        string $activityAction,
    ): array {
        $ground = $changes['ground'] ?? self::ground($notice['defect_ground']);

        $deliveredOn = array_key_exists('delivered_on', $changes)
            ? $changes['delivered_on']
            : self::date($notice['delivered_on'], 'den doručení výzvy');
        $statedRespondBy = array_key_exists('stated_respond_by', $changes)
            ? $changes['stated_respond_by']
            : ((string) $notice['respond_by_source'] === 'stated_in_notice'
                ? self::date($notice['respond_by_on'], 'konec lhůty')
                : null);
        $statedPeriodDays = array_key_exists('stated_period_days', $changes)
            ? $changes['stated_period_days']
            : self::nullableInt($notice['stated_period_days']);
        $respondedOn = array_key_exists('responded_on', $changes)
            ? $changes['responded_on']
            : self::date($notice['responded_on'], 'den odpovědi');

        $assessment = $this->assessor->assess(
            $deliveredOn,
            $statedRespondBy,
            $statedPeriodDays,
            $ground,
            $respondedOn,
            $this->today(),
            (bool) ($changes['withdrawn'] ?? ((string) $notice['status'] === DefectNoticeStatus::Withdrawn->value)),
        );

        $updated = $this->notices->update($supplierId, (int) $notice['id'], $expectedRowVersion, [
            'outbox_id' => $changes['outbox_id'] ?? self::nullableInt($notice['outbox_id']),
            'notice_reference' => $changes['notice_reference'] ?? $notice['notice_reference'],
            'authority_kind' => $changes['authority_kind'] ?? (string) $notice['authority_kind'],
            'defect_ground' => $ground->value,
            'consequence' => $ground->consequence()->value,
            'delivered_on' => $deliveredOn?->format('Y-m-d'),
            'respond_by_on' => $assessment->respondBy?->format('Y-m-d'),
            'respond_by_source' => $assessment->respondBySource,
            'stated_period_days' => $assessment->respondBySource === 'derived_from_days' ? $statedPeriodDays : null,
            'respond_by_shifted' => $assessment->respondByShifted ? 1 : 0,
            'status' => $assessment->status->value,
            'responded_on' => $respondedOn?->format('Y-m-d'),
            'response_outbox_id' => $changes['response_outbox_id'] ?? self::nullableInt($notice['response_outbox_id']),
            'outcome' => $assessment->outcome->value,
            'note' => $changes['note'] ?? $notice['note'],
        ]);

        if ($updated === null) {
            throw new SubmissionChannelException(
                'defect_notice_conflict',
                'Výzvu mezitím změnil někdo jiný. Načtěte ji znovu a zopakujte změnu.',
                409,
            );
        }

        $this->activity->log(
            $activityAction,
            $userId,
            'submission_defect_notice',
            (int) $updated['id'],
            [
                'legal_basis' => '§ 74 daňového řádu',
                'status' => $updated['status'],
                'outcome' => $updated['outcome'],
            ],
            null,
            null,
            $supplierId,
        );

        return $this->decorate($updated);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorate(array $row): array
    {
        $assessment = $this->assessor->assess(
            self::date($row['delivered_on'], 'den doručení výzvy'),
            (string) $row['respond_by_source'] === 'stated_in_notice'
                ? self::date($row['respond_by_on'], 'konec lhůty')
                : null,
            self::nullableInt($row['stated_period_days']),
            self::ground($row['defect_ground']),
            self::date($row['responded_on'], 'den odpovědi'),
            $this->today(),
            (string) $row['status'] === DefectNoticeStatus::Withdrawn->value,
        );

        return $row + ['assessment' => $assessment->toArray()];
    }

    /** @return array<string,mixed> */
    private function requireNotice(int $supplierId, int $noticeId): array
    {
        $notice = $this->notices->find($supplierId, $noticeId);
        if ($notice === null) {
            throw new SubmissionChannelException('defect_notice_not_found', 'Výzva nebyla nalezena.', 404);
        }

        return $notice;
    }

    private function assertOutbox(int $supplierId, string $environment, ?int $outboxId): ?int
    {
        if ($outboxId === null) {
            return null;
        }
        $row = $this->outbox->find($supplierId, $outboxId);
        if ($row === null || (string) $row['environment'] !== $environment) {
            throw new SubmissionChannelException(
                'submission_not_found',
                'Podání, ke kterému má výzva patřit, v tomhle prostředí není.',
                404,
            );
        }

        return $outboxId;
    }

    private function assertSupported(): void
    {
        if (!$this->isSupported()) {
            throw new SubmissionChannelException(
                'defect_notices_unavailable',
                'Evidence výzev k odstranění vad není k dispozici (chybí migrace 1394).',
                503,
            );
        }
    }

    private function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new SubmissionChannelException('invalid_environment', 'Neznámé prostředí.', 400);
        }
    }

    private function today(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'));
    }

    private static function ground(mixed $value): DefectGround
    {
        if ($value instanceof DefectGround) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return DefectGround::Unknown;
        }
        // Neznámý řetězec NESMÍ spadnout na některé z konkrétních písmen —
        // z „nerozumím" by se stalo tvrzení o následku.
        return DefectGround::tryFrom($value) ?? DefectGround::Unknown;
    }

    private static function authorityKind(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::AUTHORITY_KINDS, true)) {
            return $value;
        }

        return 'other';
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/D', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private static function date(mixed $value, string $field): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Prague'));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new SubmissionChannelException(
                'invalid_date',
                'Údaj „' . $field . '" není platné datum ve tvaru RRRR-MM-DD.',
                400,
            );
        }

        return $date;
    }
}
