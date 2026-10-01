<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionAttemptDeletionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Co se z historie pokusů smí smazat.
 *
 * Ledger pokusů je záměrně append-only — běžná cesta ven je zahození, po kterém
 * řádek zůstane i s odpovědí úřadu. Trvalé smazání je výjimka pro záznam, který
 * NIC nedokládá; jakmile by se jím dal zahodit důkaz o odeslání, je z výjimky
 * díra do evidence. Proto se testuje především to, co projít NESMÍ.
 */
final class PayrollSubmissionAttemptDeletionRulesTest extends TestCase
{
    /** Pokus s protokolem od úřadu je doklad o odeslání. */
    public function testCompletedAttemptCannotBeDeleted(): void
    {
        $reason = $this->service()->blockedReason([
            'status' => 'completed',
            'sent_at' => '2026-10-01 08:00:00',
            'correlation_reference' => 'ABC123',
        ]);

        self::assertNotNull($reason);
        self::assertStringContainsString('protokol', $reason);
    }

    /**
     * Pokus „možná doručeno" je jediná stopa, že požadavek odešel. Jeho
     * smazání by obešlo potvrzení opakování a pustilo druhé odeslání.
     */
    public function testPossiblyDeliveredAttemptCannotBeDeleted(): void
    {
        $reason = $this->service()->blockedReason([
            'status' => 'possibly_delivered',
            'sent_at' => null,
            'correlation_reference' => null,
        ]);

        self::assertNotNull($reason);
        self::assertStringContainsString('Dohledejte protokol', $reason);
    }

    /**
     * Úřad pokus převzal (CorrelationID, čas odeslání), protokol jen ještě
     * nebyl dotažený nebo podání odmítl. Dřív šel takový pokus smazat, protože
     * k němu nebyla připnutá dodejka — tedy ostré podání.
     *
     * @param array<string,mixed> $attempt
     */
    #[DataProvider('dispatchedAttempts')]
    public function testAttemptTakenOverByAuthorityCannotBeDeleted(array $attempt): void
    {
        $reason = $this->service()->blockedReason($attempt);

        self::assertNotNull($reason);
        self::assertStringContainsString('odešel na úřad', $reason);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function dispatchedAttempts(): iterable
    {
        yield 'čeká na protokol' => [[
            'status' => 'awaiting_protocol',
            'sent_at' => '2026-10-01 08:00:00',
            'correlation_reference' => 'ABC123',
        ]];
        yield 'čeká na protokol bez času' => [[
            'status' => 'awaiting_protocol',
            'sent_at' => null,
            'correlation_reference' => null,
        ]];
        yield 'odesláno' => [[
            'status' => 'sent',
            'sent_at' => null,
            'correlation_reference' => null,
        ]];
        yield 'odmítnuto protokolem' => [[
            'status' => 'failed',
            'sent_at' => '2026-10-01 08:00:00',
            'correlation_reference' => 'ABC123',
        ]];
        yield 'propadlo po převzetí' => [[
            'status' => 'expired',
            'sent_at' => null,
            'correlation_reference' => 'ABC123',
        ]];
    }

    /**
     * Pokus, který nikdy neodešel nebo selhal dřív, než ho úřad převzal, nic
     * nedokládá — přesně ten případ, kvůli kterému mazání vzniklo.
     *
     * @param array<string,mixed> $attempt
     */
    #[DataProvider('neverTakenOverAttempts')]
    public function testAttemptNeverTakenOverIsDeletable(array $attempt): void
    {
        self::assertNull($this->service()->blockedReason($attempt));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function neverTakenOverAttempts(): iterable
    {
        yield 'připraveno' => [[
            'status' => 'prepared',
            'sent_at' => null,
            'correlation_reference' => null,
        ]];
        yield 'selhalo před převzetím' => [[
            'status' => 'failed',
            'sent_at' => null,
            'correlation_reference' => null,
        ]];
        yield 'zahozeno před převzetím' => [[
            'status' => 'expired',
            'sent_at' => null,
            'correlation_reference' => '',
        ]];
    }

    /** Smazání převzatého pokusu se do repozitáře vůbec nedostane. */
    public function testDeleteRefusesAttemptTakenOverByAuthority(): void
    {
        $attempts = $this->createMock(PayrollSubmissionTransportAttemptRepository::class);
        $attempts->method('find')->willReturn([
            'id' => 2,
            'submission_id' => 4,
            'attempt_no' => 1,
            'channel' => 'vrep',
            'status' => 'completed',
            'sent_at' => '2026-10-01 08:00:00',
            'correlation_reference' => 'ABC123',
        ]);
        $attempts->expects(self::never())->method('delete');

        $this->expectException(\DomainException::class);
        (new PayrollSubmissionAttemptDeletionService($attempts))->delete(11, 'production', 2, 3);
    }

    private function service(): PayrollSubmissionAttemptDeletionService
    {
        return new PayrollSubmissionAttemptDeletionService(
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
        );
    }
}
