<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchGate;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionAbandonService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * Zahození rozdělaného odeslání a návrat podání k odeslání.
 *
 * NÁLEZ, KTERÝ TO VYNUTIL
 * ------------------------------------------------------------------------------
 * ČSSZ zprávu převzala (HTTP 200, CorrelationID), ale zpracovat ji odmítla:
 * „Pověření k dané e-službě ('CSSZ_JMHZ') není zaznamenáno v registru podávajících
 * na OSSZ nebo certifikát, kterým je e-podání podepsáno, není zaznamenán v registru
 * podávajících na OSSZ." Odeslané tedy nebylo nic, jenže podání uvízlo ve stavu
 * `submitted`, ze kterého nevedla cesta nikam:
 *
 *   - na `ready` (jediný stav, ze kterého se smí odesílat) se nedalo vrátit odnikud,
 *   - klíč `uq_payroll_submissions_regular` pouští na jednu povinnost jediné řádné
 *     podání, takže nešlo založit ani nové,
 *   - odeslaný pokus blokoval další odeslání.
 *
 * Povinnost byla z aplikace trvale nepodatelná i poté, co by účetní příčinu u OSSZ
 * vyřídila.
 *
 * O opakování rozhoduje ČLOVĚK, ne automatika podle textu odpovědi: důvodů, proč
 * úřad podání nepřijme, je víc, než kolik jich umíme spolehlivě rozpoznat.
 */
final class PayrollSubmissionAbandonRulesTest extends TestCase
{
    private PayrollSubmissionStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new PayrollSubmissionStateMachine();
    }

    /** JÁDRO NÁLEZU: z odeslaného podání musí vést cesta zpět k odeslání. */
    public function testStuckSubmissionCanReturnToReady(): void
    {
        self::assertTrue(
            $this->machine->canTransition('submitted', 'ready'),
            'Podání, které úřad nepřijal, se musí dát vrátit k odeslání.',
        );
    }

    /**
     * Komentář u mapování stavů říká, že po „nebylo přijato" i „zamítnuto" musí
     * zaměstnavatel poslat nové hlášení — bez návratu na `ready` k tomu ale
     * nevedla žádná cesta.
     */
    public function testRejectedSubmissionCanReturnToReady(): void
    {
        self::assertTrue($this->machine->canTransition('rejected', 'ready'));
    }

    public function testProcessingAndWaitingForIdentityCanReturnToReady(): void
    {
        self::assertTrue($this->machine->canTransition('processing', 'ready'));
        self::assertTrue($this->machine->canTransition('waiting_for_identity', 'ready'));
    }

    /**
     * DRUHÁ POLOVINA PRAVIDLA: přijaté podání se vrátit NESMÍ. Tam u úřadu něco JE
     * a opakované odeslání by vyrobilo duplicitu; oprava vede přes opravné podání.
     */
    public function testAcceptedSubmissionCannotReturnToReady(): void
    {
        self::assertFalse(
            $this->machine->canTransition('accepted', 'ready'),
            'Přijaté podání se znovu neodesílá — vzniklá duplicita se u úřadu nedá vzít zpět.',
        );
        self::assertFalse($this->machine->canTransition('partially_accepted', 'ready'));
    }

    public function testAcceptedIsNotAmongReopenableStatuses(): void
    {
        self::assertNotContains('accepted', PayrollSubmissionStateMachine::REOPENABLE_STATUSES);
        self::assertNotContains('partially_accepted', PayrollSubmissionStateMachine::REOPENABLE_STATUSES);
        self::assertContains('submitted', PayrollSubmissionStateMachine::REOPENABLE_STATUSES);
    }

    /** Vědomě zahozený pokus přestane blokovat další odeslání. */
    public function testAbandonedAttemptAllowsRetry(): void
    {
        self::assertTrue(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'expired',
            'error_code' => PayrollDispatchGate::ABANDONED_ERROR_CODE,
            'sent_at' => '2026-09-04 08:30:00',
        ]));
    }

    /**
     * Pokus, který vzdala AUTOMATIKA, blokuje dál — tam se pořád neví, co úřad
     * přijal. Rozdíl je právě v tom, že u zahození rozhodl člověk, který odpověď
     * úřadu viděl.
     */
    public function testAttemptExpiredByAutomationStillBlocks(): void
    {
        self::assertFalse(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'expired',
            'error_code' => 'jmhz_poll_budget_exhausted',
            'sent_at' => '2026-09-04 08:30:00',
        ]));
    }

    /** Původní pravidlo zůstává: co aplikaci neopustilo, jde poslat znovu. */
    public function testFailedBeforeSendingStillAllowsRetry(): void
    {
        self::assertTrue(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'failed',
            'sent_at' => null,
        ]));
    }

    /** A odeslaný pokus, který selhal až potom, dál blokuje. */
    public function testFailedAfterSendingStillBlocks(): void
    {
        self::assertFalse(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'failed',
            'sent_at' => '2026-09-04 08:30:00',
        ]));
    }

    /**
     * Pokus čekající na protokol MUSÍ jít zahodit.
     *
     * Přesně tenhle stav služba řeší: ČSSZ zprávu převezme a odmítne ji až
     * protokolem, takže pokus skončí v `awaiting_protocol`. Ve výčtu
     * zahoditelných stavů ale chyběl, takže ho zahození minulo — pokus se dál
     * doptával na výsledek a povinnost zůstala nepodatelná. Test čte přímo
     * konstantu služby, aby ho nešlo obejít úpravou textu komentáře.
     */
    public function testAwaitingProtocolAttemptIsAbandonable(): void
    {
        $statuses = (new \ReflectionClassConstant(
            PayrollSubmissionAbandonService::class,
            'OPEN_ATTEMPT_STATUSES',
        ))->getValue();

        self::assertContains(
            'awaiting_protocol',
            $statuses,
            'Pokus čekající na protokol je právě ten, kvůli kterému zahození vzniklo.',
        );
        // Ostatní otevřené stavy tím nesmí vypadnout.
        foreach (['prepared', 'sent', 'completed'] as $open) {
            self::assertContains($open, $statuses);
        }
        // Terminální stavy se zahazovat nemají — nic už nedrží otevřené.
        foreach (['failed', 'expired'] as $terminal) {
            self::assertNotContains($terminal, $statuses);
        }
    }

    /**
     * Pravidlo žije ve DVOU kopiích — v bráně a v SQL fronty odeslání. Kdyby se
     * rozešly, nabízela by fronta odeslání tam, kde ho „Stav odeslání" zakazuje.
     */
    public function testQueueSqlKnowsTheSameExceptions(): void
    {
        $method = new \ReflectionMethod(
            \MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository::class,
            'blockingAttemptSql',
        );
        $sql = $method->invoke(null, 'attempt');
        self::assertIsString($sql);

        self::assertStringContainsString(
            'attempt.status = "failed" AND attempt.sent_at IS NULL',
            $sql,
        );
        self::assertStringContainsString(
            'AND attempt.error_code IN ("' . PayrollDispatchGate::ABANDONED_ERROR_CODE
                . '", "' . PayrollDispatchGate::RETRY_CONFIRMED_ERROR_CODE . '"))',
            (string) preg_replace('/\s+/', ' ', $sql),
            'Fronta odeslání musí znát tytéž výjimky jako PayrollDispatchGate.',
        );
    }

    /**
     * NÁLEZ: selhání PO odeslání požadavku (vypršený čas, ztracená odpověď)
     * se zapisovalo jako `failed` bez `sent_at`, a brána ho proto pustila
     * znovu. Stav „možná doručeno" opakování blokuje s větou, co dělat.
     */
    public function testPossiblyDeliveredAttemptBlocksRetryWithGuidance(): void
    {
        $attempt = [
            'status' => PayrollDispatchGate::POSSIBLY_DELIVERED_STATUS,
            'error_code' => 'jmhz_vrep_response_lost',
            'sent_at' => null,
            'attempt_no' => 1,
        ];

        self::assertFalse(PayrollDispatchGate::attemptAllowsRetry($attempt));
        $reason = (new PayrollDispatchGate())->blockedReason(
            ['submission_status' => 'ready', 'attempt' => $attempt, 'outbox' => null],
            (new PayrollDispatchCapabilityCatalog())->forAgenda('JMHZ25'),
            'test',
            0,
        );
        self::assertIsString($reason);
        self::assertStringContainsString('možná doručeno', $reason);
        self::assertStringContainsString('dohledejte protokol', $reason);
    }

    /** Po výslovném potvrzení účetní brána opakování pustí. */
    public function testConfirmedRetryAllowsSendingAgain(): void
    {
        self::assertTrue(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'expired',
            'error_code' => PayrollDispatchGate::RETRY_CONFIRMED_ERROR_CODE,
            'sent_at' => null,
        ]));
    }

    /** Odpověď 20022 „shodné podání už existuje": originál je u ČSSZ. */
    public function testOriginalAtCsszAttemptExplainsWhatToDo(): void
    {
        $reason = PayrollDispatchGate::possiblyDeliveredReason([
            'status' => PayrollDispatchGate::POSSIBLY_DELIVERED_STATUS,
            'error_code' => PayrollDispatchGate::ORIGINAL_AT_CSSZ_ERROR_CODE,
        ]);

        self::assertIsString($reason);
        self::assertStringContainsString('Originál podání je u ČSSZ', $reason);
        self::assertStringContainsString('protokol originálu', $reason);
    }
}
