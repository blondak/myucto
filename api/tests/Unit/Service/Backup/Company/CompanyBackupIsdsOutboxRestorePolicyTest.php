<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupIsdsRestorePolicy;
use MyInvoice\Service\Backup\Company\CompanyBackupRowTransformException;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionRestoreReview;
use PHPUnit\Framework\TestCase;

final class CompanyBackupIsdsOutboxRestorePolicyTest extends TestCase
{
    public function testReadyWithAwaitingGatewaySessionRequiresReviewWithoutInventingConfirmation(): void
    {
        $source = $this->sourceRow('ready');

        $restored = CompanyBackupIsdsRestorePolicy::reviewOutboxRow($source, true);

        self::assertSame('ready', $restored['dispatch_state']);
        self::assertSame(SubmissionRestoreReview::CODE, $restored['last_error_code']);
        self::assertSame(SubmissionRestoreReview::MESSAGE, $restored['last_error_message']);
        self::assertNull($restored['confirmed_by']);
        self::assertNull($restored['confirmed_at']);
        self::assertSame($this->sourceRow('ready'), $source);
    }

    public function testRestoredReadyRowIsRejectedByRuntimeDispatchGuard(): void
    {
        $restored = CompanyBackupIsdsRestorePolicy::reviewOutboxRow($this->sourceRow('ready'), true);

        try {
            SubmissionRestoreReview::assertDispatchAllowed($restored);
            self::fail('Označené podání nesmí projít běžnou bránou odeslání.');
        } catch (SubmissionChannelException $e) {
            self::assertSame(SubmissionRestoreReview::CODE, $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testReadyWithoutAwaitingGatewaySessionIsUnchanged(): void
    {
        $source = $this->sourceRow('ready');

        self::assertSame($source, CompanyBackupIsdsRestorePolicy::reviewOutboxRow($source, false));

        $source['last_error_code'] = SubmissionRestoreReview::CODE;
        $source['last_error_message'] = SubmissionRestoreReview::MESSAGE;
        self::assertSame($source, CompanyBackupIsdsRestorePolicy::reviewOutboxRow($source, false));
    }

    public function testInFlightAndUncertainDispatchesRequireReviewRegardlessOfGatewaySession(): void
    {
        foreach (['sending', 'send_uncertain'] as $state) {
            foreach ([false, true] as $hasAwaitingGatewaySession) {
                $source = $this->sourceRow($state);
                $restored = CompanyBackupIsdsRestorePolicy::reviewOutboxRow($source, $hasAwaitingGatewaySession);

                self::assertSame($state, $restored['dispatch_state']);
                self::assertSame(SubmissionRestoreReview::CODE, $restored['last_error_code']);
                self::assertSame(SubmissionRestoreReview::MESSAGE, $restored['last_error_message']);
                self::assertSame($source['confirmed_by'], $restored['confirmed_by']);
                self::assertSame($source['confirmed_at'], $restored['confirmed_at']);
                self::assertSame($this->sourceRow($state), $source);
            }
        }
    }

    public function testKnownResolvedAndClosedDispatchesRemainUnchanged(): void
    {
        foreach (['sent', 'delivered', 'failed', 'cancelled'] as $state) {
            foreach ([false, true] as $hasAwaitingGatewaySession) {
                $source = $this->sourceRow($state);
                self::assertSame(
                    $source,
                    CompanyBackupIsdsRestorePolicy::reviewOutboxRow($source, $hasAwaitingGatewaySession),
                    $state,
                );
            }
        }
    }

    public function testEpoDispatchRemainsUnchangedEvenWhenGatewayFlagIsSet(): void
    {
        foreach (['ready', 'sending', 'send_uncertain', 'sent', 'delivered', 'failed', 'cancelled'] as $state) {
            $source = $this->sourceRow($state);
            $source['channel'] = 'epo';
            self::assertSame($source, CompanyBackupIsdsRestorePolicy::reviewOutboxRow($source, true));
        }
    }

    public function testUnknownOrMissingDispatchStateFailsClosed(): void
    {
        foreach (['unknown', 'SENDING', null, 0] as $state) {
            $row = $this->sourceRow('ready');
            $row['dispatch_state'] = $state;
            $this->assertInvalidState($row);
        }

        $row = $this->sourceRow('ready');
        unset($row['dispatch_state']);
        $this->assertInvalidState($row);
    }

    public function testUnknownOrMissingChannelFailsClosed(): void
    {
        foreach (['other', 'ISDS', null, 0] as $channel) {
            $row = $this->sourceRow('ready');
            $row['channel'] = $channel;
            $this->assertInvalidChannel($row);
        }

        $row = $this->sourceRow('ready');
        unset($row['channel']);
        $this->assertInvalidChannel($row);
    }

    /** @return array<string,mixed> */
    private function sourceRow(string $state): array
    {
        return [
            'id' => 7,
            'channel' => 'isds',
            'dispatch_state' => $state,
            'last_error_code' => 'source_error',
            'last_error_message' => 'Původní diagnostika',
            'confirmed_by' => $state === 'ready' ? null : 11,
            'confirmed_at' => $state === 'ready' ? null : '2025-01-01 00:00:00',
            'external_message_id' => in_array($state, ['sent', 'delivered'], true) ? 'SYNTHETIC-MESSAGE' : null,
            'sent_at' => in_array($state, ['sent', 'delivered'], true) ? '2025-01-01 00:00:01' : null,
        ];
    }

    /** @param array<string,mixed> $row */
    private function assertInvalidState(array $row): void
    {
        try {
            CompanyBackupIsdsRestorePolicy::reviewOutboxRow($row, true);
            self::fail('Neznámý stav podání nesmí projít obnovou.');
        } catch (CompanyBackupRowTransformException $e) {
            self::assertSame('submission_dispatch_state_invalid', $e->errorCode);
            self::assertSame('table:submission_outbox', $e->registryKey);
            self::assertSame('dispatch_state', $e->column);
        }
    }

    /** @param array<string,mixed> $row */
    private function assertInvalidChannel(array $row): void
    {
        try {
            CompanyBackupIsdsRestorePolicy::reviewOutboxRow($row, true);
            self::fail('Neznámý kanál podání nesmí projít obnovou.');
        } catch (CompanyBackupRowTransformException $e) {
            self::assertSame('submission_channel_invalid', $e->errorCode);
            self::assertSame('table:submission_outbox', $e->registryKey);
            self::assertSame('channel', $e->column);
        }
    }
}
