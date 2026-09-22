<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail\LogAnalysis;

use MyInvoice\Service\Mail\LogAnalysis\HMailServerLogConnector;
use MyInvoice\Service\Mail\LogAnalysis\MailEnableLogConnector;
use MyInvoice\Service\Mail\LogAnalysis\PostfixLogConnector;
use MyInvoice\Service\Mail\LogAnalysis\SmtpLogEvent;
use PHPUnit\Framework\TestCase;

/**
 * Parsery logů poštovních serverů → jednotné {@see SmtpLogEvent}.
 * Syntetická data (žádné reálné adresy/IP) — viz konvence repa.
 *
 * Kontrakty:
 *  - SMTP-IN/SMTPD = podání (submission), obálka tak, jak přišla.
 *  - SMTP-OU/SMTPC = doručení (delivery), stav z odpovědi (2xx delivered,
 *    4xx deferred, 5xx rejected; host-level CONN selhání u MailEnable).
 */
final class SmtpLogConnectorTest extends TestCase
{
    public function testHMailServerParsesDeliveredDeferredAndSubmission(): void
    {
        // Podání 3 příjemcům + 2 doručení (1 delivered, 1 deferred) + odložení.
        $log = implode("\n", [
            '"SMTPD"	100	1	"2026-01-02 10:00:00.000"	"127.0.0.1"	"RECEIVED: MAIL FROM:<sender@example.com>"',
            '"SMTPD"	100	1	"2026-01-02 10:00:00.001"	"127.0.0.1"	"RECEIVED: RCPT TO:<a@example.org>"',
            '"SMTPD"	100	1	"2026-01-02 10:00:00.002"	"127.0.0.1"	"SENT: 250 OK"',
            '"SMTPD"	100	1	"2026-01-02 10:00:00.003"	"127.0.0.1"	"RECEIVED: RCPT TO:<b@example.net>"',
            '"SMTPD"	100	1	"2026-01-02 10:00:00.004"	"127.0.0.1"	"SENT: 250 OK"',
            '"SMTPD"	100	1	"2026-01-02 10:00:00.005"	"127.0.0.1"	"RECEIVED: DATA"',
            '"SMTPD"	100	1	"2026-01-02 10:00:00.006"	"127.0.0.1"	"SENT: 354 OK, send."',
            '"SMTPD"	100	1	"2026-01-02 10:00:00.007"	"127.0.0.1"	"SENT: 250 Queued (0.000 seconds)"',
            '"APPLICATION"	101	"2026-01-02 10:00:00.008"	"SMTPDeliverer - Message 5: Delivering message from sender@example.com to a@example.org, b@example.net. File: C:\\x.eml"',
            // Doručení A → delivered
            '"SMTPC"	102	2	"2026-01-02 10:00:01.000"	"203.0.113.10"	"RECEIVED: 220 mx.example.org ESMTP"',
            '"SMTPC"	102	2	"2026-01-02 10:00:01.001"	"203.0.113.10"	"SENT: MAIL FROM:<sender@example.com>"',
            '"SMTPC"	102	2	"2026-01-02 10:00:01.002"	"203.0.113.10"	"RECEIVED: 250 2.1.0 Sender OK"',
            '"SMTPC"	102	2	"2026-01-02 10:00:01.003"	"203.0.113.10"	"SENT: RCPT TO:<a@example.org>"',
            '"SMTPC"	102	2	"2026-01-02 10:00:01.004"	"203.0.113.10"	"RECEIVED: 250 2.1.5 Recipient OK"',
            '"SMTPC"	102	2	"2026-01-02 10:00:01.005"	"203.0.113.10"	"SENT: DATA"',
            '"SMTPC"	102	2	"2026-01-02 10:00:01.006"	"203.0.113.10"	"RECEIVED: 354 go"',
            '"SMTPC"	102	2	"2026-01-02 10:00:01.007"	"203.0.113.10"	"SENT: [nl]."',
            '"SMTPC"	102	2	"2026-01-02 10:00:01.100"	"203.0.113.10"	"RECEIVED: 250 2.0.0 Queued mail for delivery"',
            // Doručení B → deferred (450 na RCPT)
            '"SMTPC"	103	3	"2026-01-02 10:00:02.000"	"203.0.113.20"	"RECEIVED: 220 mx.example.net ESMTP"',
            '"SMTPC"	103	3	"2026-01-02 10:00:02.001"	"203.0.113.20"	"SENT: MAIL FROM:<sender@example.com>"',
            '"SMTPC"	103	3	"2026-01-02 10:00:02.002"	"203.0.113.20"	"RECEIVED: 250 Ok"',
            '"SMTPC"	103	3	"2026-01-02 10:00:02.003"	"203.0.113.20"	"SENT: RCPT TO:<b@example.net>"',
            '"SMTPC"	103	3	"2026-01-02 10:00:02.004"	"203.0.113.20"	"RECEIVED: 450 4.3.2 Service currently unavailable"',
            '"APPLICATION"	101	"2026-01-02 10:00:02.100"	"SMTPDeliverer - Message 5: Message could not be delivered. Scheduling it for later delivery in 60 minutes."',
        ]);

        $events = (new HMailServerLogConnector())->parse($log, 'hmailserver_2026-01-02.log');

        $submission = $this->firstOfKind($events, SmtpLogEvent::KIND_SUBMISSION);
        self::assertNotNull($submission);
        self::assertSame('sender@example.com', $submission->mailFrom);
        self::assertSame(['a@example.org', 'b@example.net'], $submission->recipients);
        self::assertSame(SmtpLogEvent::STATUS_QUEUED, $submission->status);
        self::assertSame('5', $submission->messageId, 'submission se má napárovat na Message 5');

        $deliveries = array_values(array_filter($events, fn ($e) => $e->kind === SmtpLogEvent::KIND_DELIVERY));
        self::assertCount(2, $deliveries);

        $byHost = [];
        foreach ($deliveries as $d) {
            $byHost[$d->remoteHost] = $d;
        }
        self::assertSame(SmtpLogEvent::STATUS_DELIVERED, $byHost['mx.example.org']->status);
        self::assertSame(250, $byHost['mx.example.org']->code);
        self::assertSame(['a@example.org'], $byHost['mx.example.org']->recipients);

        self::assertSame(SmtpLogEvent::STATUS_DEFERRED, $byHost['mx.example.net']->status);
        self::assertSame(450, $byHost['mx.example.net']->code);

        // Notice o odložení.
        $notice = $this->firstOfKind($events, SmtpLogEvent::KIND_NOTICE);
        self::assertNotNull($notice);
        self::assertSame(SmtpLogEvent::STATUS_DEFERRED, $notice->status);
    }

    public function testHMailServerRemoteHostNotConfusedByStartTls(): void
    {
        // STARTTLS odpověď „220 2.0.0 …" nesmí přepsat hostname z banneru.
        $log = implode("\n", [
            '"SMTPC"	200	9	"2026-01-03 08:00:00.000"	"198.51.100.5"	"RECEIVED: 220 mail.example.com ESMTP"',
            '"SMTPC"	200	9	"2026-01-03 08:00:00.001"	"198.51.100.5"	"SENT: STARTTLS"',
            '"SMTPC"	200	9	"2026-01-03 08:00:00.002"	"198.51.100.5"	"RECEIVED: 220 2.0.0 Ready to start TLS"',
            '"SMTPC"	200	9	"2026-01-03 08:00:00.003"	"198.51.100.5"	"SENT: MAIL FROM:<x@example.com>"',
            '"SMTPC"	200	9	"2026-01-03 08:00:00.004"	"198.51.100.5"	"RECEIVED: 250 Ok"',
            '"SMTPC"	200	9	"2026-01-03 08:00:00.005"	"198.51.100.5"	"SENT: RCPT TO:<y@example.org>"',
            '"SMTPC"	200	9	"2026-01-03 08:00:00.006"	"198.51.100.5"	"RECEIVED: 250 Ok"',
            '"SMTPC"	200	9	"2026-01-03 08:00:00.007"	"198.51.100.5"	"SENT: [nl]."',
            '"SMTPC"	200	9	"2026-01-03 08:00:00.100"	"198.51.100.5"	"RECEIVED: 250 2.0.0 OK"',
        ]);

        $events = (new HMailServerLogConnector())->parse($log, 'h.log');
        $d = $this->firstOfKind($events, SmtpLogEvent::KIND_DELIVERY);
        self::assertNotNull($d);
        self::assertSame('mail.example.com', $d->remoteHost);
        self::assertSame(SmtpLogEvent::STATUS_DELIVERED, $d->status);
    }

    public function testMailEnableParsesRejectionWithSubjectAndHost(): void
    {
        // SMTP-OU: delivered jednomu příjemci, rejected druhému (552). Předmět z posledního pole.
        $subject = 'Objednavka c. 123';
        $rows = [
            // delivered
            "01/02/26 09:00:00\tSMTP-OU\tAAAA1111.MAI\t10\t203.0.113.30\tCONN\t\t220 mx.good.example ESMTP\t0\t10\t\t$subject",
            "01/02/26 09:00:00\tSMTP-OU\tAAAA1111.MAI\t10\t203.0.113.30\tMAIL\tMAIL FROM:<shop@example.com> SIZE=1000\t250 OK\t40\t10\t\t$subject",
            "01/02/26 09:00:00\tSMTP-OU\tAAAA1111.MAI\t10\t203.0.113.30\tRCPT\tRCPT TO:<buyer@example.org>\t250 OK\t30\t10\t\t$subject",
            "01/02/26 09:00:01\tSMTP-OU\tAAAA1111.MAI\t10\t203.0.113.30\tDATE\t.\t250 2.0.0 OK queued\t1000\t10\t\t$subject",
            // rejected (antispam policy on RCPT)
            "01/02/26 09:05:00\tSMTP-OU\tBBBB2222.MAI\t11\t203.0.113.40\tCONN\t\t220 mx.strict.example ESMTP\t0\t10\t\t$subject",
            "01/02/26 09:05:00\tSMTP-OU\tBBBB2222.MAI\t11\t203.0.113.40\tMAIL\tMAIL FROM:<shop@example.com> SIZE=1000\t250 Sender OK\t40\t10\t\t$subject",
            "01/02/26 09:05:01\tSMTP-OU\tBBBB2222.MAI\t11\t203.0.113.40\tRCPT\tRCPT TO:<blocked@example.net>\t552 5.1.1 Mailbox delivery failure policy error\t30\t10\t\t$subject",
        ];
        $log = "[01/02/26 09:00:00]****************** LOG FILE STARTED *******************\n" . implode("\n", $rows);

        $events = (new MailEnableLogConnector())->parse($log, 'SMTP-Activity-260102.log');
        self::assertCount(2, $events);

        $byHost = [];
        foreach ($events as $e) {
            $byHost[$e->remoteHost] = $e;
        }

        $good = $byHost['mx.good.example'];
        self::assertSame(SmtpLogEvent::STATUS_DELIVERED, $good->status);
        self::assertSame('shop@example.com', $good->mailFrom);
        self::assertSame(['buyer@example.org'], $good->recipients);
        self::assertSame($subject, $good->subject);
        self::assertSame('2026-01-02 09:00:00', $good->ts, 'MM/DD/YY → YYYY-MM-DD normalizace');

        $bad = $byHost['mx.strict.example'];
        self::assertSame(SmtpLogEvent::STATUS_REJECTED, $bad->status);
        self::assertSame(552, $bad->code);
        self::assertSame(['blocked@example.net'], $bad->recipients);
    }

    public function testMailEnableConnLevelRejectionHasNoRecipients(): void
    {
        // Host odmítne naši IP už na CONN (antispam) — žádný RCPT neproběhne.
        $rows = [
            "01/02/26 11:00:00\tSMTP-OU\tCCCC3333.MAI\t12\t203.0.113.50\tCONN\t\t554 5.7.1 antispam25.example rejected\t0\t10\t\tHello",
        ];
        $log = implode("\n", $rows);
        $events = (new MailEnableLogConnector())->parse($log, 'SMTP-Activity-260102.log');
        self::assertCount(1, $events);
        self::assertSame(SmtpLogEvent::STATUS_REJECTED, $events[0]->status);
        self::assertSame(554, $events[0]->code);
        self::assertSame([], $events[0]->recipients);
    }

    public function testMailEnableSubmissionIsInbound(): void
    {
        $rows = [
            "01/02/26 12:00:00\tSMTP-IN\tDDDD4444.MAI\t13\t127.0.0.1\tMAIL\tMAIL FROM:<local@example.com>\t250 OK\t40\t10\t\tNewsletter",
            "01/02/26 12:00:00\tSMTP-IN\tDDDD4444.MAI\t13\t127.0.0.1\tRCPT\tRCPT TO:<sub@example.org>\t250 OK\t30\t10\t\tNewsletter",
            "01/02/26 12:00:00\tSMTP-IN\tDDDD4444.MAI\t13\t127.0.0.1\tDATE\t\t250 OK\t9000\t10\t\tNewsletter",
        ];
        $events = (new MailEnableLogConnector())->parse(implode("\n", $rows), 'SMTP-Activity-260102.log');
        self::assertCount(1, $events);
        self::assertSame(SmtpLogEvent::KIND_SUBMISSION, $events[0]->kind);
        self::assertSame(SmtpLogEvent::STATUS_QUEUED, $events[0]->status);
        self::assertSame('Newsletter', $events[0]->subject);
    }

    public function testPostfixJoinsSubmissionAndDeliveriesByQueueId(): void
    {
        // maillog_file (postlogd): BSD čas bez roku s mikrosekundami.
        $log = implode("\n", [
            'Jan  2 10:00:00.123456 mail.example.com postfix/smtpd[100]: connect from unknown[172.18.0.2]',
            'Jan  2 10:00:00.200000 mail.example.com postfix/smtpd[100]: 1A2B3C4D5E: client=unknown[172.18.0.2]',
            'Jan  2 10:00:00.210000 mail.example.com postfix/cleanup[101]: 1A2B3C4D5E: info: header Subject: =?UTF-8?B?RmFrdHVyYSAyNjAxMDAx?= from unknown[172.18.0.2]; from=<sender@example.com> to=<a@example.org> proto=ESMTP helo=<app>',
            'Jan  2 10:00:00.220000 mail.example.com postfix/cleanup[101]: 1A2B3C4D5E: message-id=<abc@example.com>',
            'Jan  2 10:00:00.230000 mail.example.com postfix/qmgr[50]: 1A2B3C4D5E: from=<sender@example.com>, size=2048, nrcpt=2 (queue active)',
            'Jan  2 10:00:01.500000 mail.example.com postfix/smtp[102]: 1A2B3C4D5E: to=<a@example.org>, relay=mx.example.org[203.0.113.10]:25, delay=1.3, delays=0.03/0/0.5/0.8, dsn=2.0.0, status=sent (250 2.0.0 Ok: queued as XYZ)',
            'Jan  2 10:00:02.000000 mail.example.com postfix/smtp[103]: 1A2B3C4D5E: to=<b@example.net>, relay=mx.example.net[203.0.113.20]:25, delay=1.8, delays=0.03/0/1/0.8, dsn=4.3.2, status=deferred (host mx.example.net[203.0.113.20] said: 450 4.3.2 Service currently unavailable (in reply to RCPT TO command))',
            'Jan  2 11:00:02.000000 mail.example.com postfix/smtp[104]: 1A2B3C4D5E: to=<b@example.net>, relay=mx.example.net[203.0.113.20]:25, delay=3600, delays=3599/0/0.5/0.5, dsn=5.1.1, status=bounced (host mx.example.net[203.0.113.20] said: 550 5.1.1 User unknown (in reply to RCPT TO command))',
            'Jan  2 11:00:02.100000 mail.example.com postfix/bounce[105]: 1A2B3C4D5E: sender non-delivery notification: 9F8E7D6C5B',
            'Jan  2 11:00:02.200000 mail.example.com postfix/qmgr[50]: 1A2B3C4D5E: removed',
        ]);

        $events = (new PostfixLogConnector())->parse($log, 'mail.log-2026-01-02');

        $submission = $this->firstOfKind($events, SmtpLogEvent::KIND_SUBMISSION);
        self::assertNotNull($submission);
        self::assertSame('2026-01-02 10:00:00.200', $submission->ts);
        self::assertSame('sender@example.com', $submission->mailFrom);
        self::assertSame(['a@example.org', 'b@example.net'], $submission->recipients);
        self::assertSame('172.18.0.2', $submission->remoteIp);
        self::assertSame('Faktura 2601001', $submission->subject);
        self::assertSame('1A2B3C4D5E', $submission->messageId);

        $deliveries = array_values(array_filter($events, static fn (SmtpLogEvent $e) => $e->kind === SmtpLogEvent::KIND_DELIVERY));
        self::assertCount(3, $deliveries);
        self::assertSame(SmtpLogEvent::STATUS_DELIVERED, $deliveries[0]->status);
        self::assertSame(250, $deliveries[0]->code);
        self::assertSame('mx.example.org', $deliveries[0]->remoteHost);
        self::assertSame('203.0.113.10', $deliveries[0]->remoteIp);
        self::assertSame(SmtpLogEvent::STATUS_DEFERRED, $deliveries[1]->status);
        self::assertSame(450, $deliveries[1]->code);
        self::assertSame(SmtpLogEvent::STATUS_REJECTED, $deliveries[2]->status);
        self::assertSame(550, $deliveries[2]->code);
        self::assertSame(['b@example.net'], $deliveries[2]->recipients);
        foreach ($events as $e) {
            self::assertSame('Faktura 2601001', $e->subject, 'Předmět se propíše ke všem událostem zprávy');
        }

        $notice = $this->firstOfKind($events, SmtpLogEvent::KIND_NOTICE);
        self::assertNotNull($notice);
        self::assertSame(SmtpLogEvent::STATUS_INFO, $notice->status);
    }

    public function testPostfixReadsIsoTimestampsAndNoQueueReject(): void
    {
        // journalctl -o short-iso / rsyslog RFC 3339.
        $log = implode("\n", [
            '2026-01-02T10:00:00+01:00 mail.example.com postfix/smtpd[100]: NOQUEUE: reject: RCPT from unknown[198.51.100.7]: 554 5.7.1 <x@example.org>: Relay access denied; from=<spam@example.net> to=<x@example.org> proto=ESMTP helo=<bad>',
            '2026-01-02T10:05:00.5+01:00 mail.example.com postfix/pickup[110]: 0A1B2C3D4E: uid=33 from=<www-data>',
            '2026-01-02T10:05:01+01:00 mail.example.com postfix/smtp[111]: 0A1B2C3D4E: to=<a@example.org>, relay=none, delay=30, delays=0/0/30/0, dsn=4.4.1, status=deferred (connect to mx.example.org[203.0.113.10]:25: Connection timed out)',
        ]);

        $events = (new PostfixLogConnector())->parse($log, 'mail.log');

        $reject = array_values(array_filter($events, static fn (SmtpLogEvent $e) => $e->kind === SmtpLogEvent::KIND_NOTICE));
        self::assertCount(1, $reject);
        self::assertSame(SmtpLogEvent::STATUS_REJECTED, $reject[0]->status);
        self::assertSame(554, $reject[0]->code);
        self::assertSame(['x@example.org'], $reject[0]->recipients);
        self::assertSame('198.51.100.7', $reject[0]->remoteIp);
        self::assertSame('2026-01-02 10:00:00.000', $reject[0]->ts);

        $submission = $this->firstOfKind($events, SmtpLogEvent::KIND_SUBMISSION);
        self::assertNotNull($submission);
        self::assertSame('2026-01-02 10:05:00.500', $submission->ts);
        self::assertSame('www-data', $submission->mailFrom);

        $delivery = $this->firstOfKind($events, SmtpLogEvent::KIND_DELIVERY);
        self::assertNotNull($delivery);
        self::assertSame(SmtpLogEvent::STATUS_DEFERRED, $delivery->status);
        self::assertNull($delivery->remoteHost);
        self::assertNull($delivery->code);
    }

    public function testPostfixYearRollsBackForDecemberLinesInJanuaryFile(): void
    {
        $log = 'Dec 31 23:59:59 mail.example.com postfix/smtp[1]: 1A2B3C4D5E: to=<a@example.org>, relay=mx.example.org[203.0.113.10]:25, delay=1, delays=0/0/0.5/0.5, dsn=2.0.0, status=sent (250 Ok)';

        $events = (new PostfixLogConnector())->parse($log, 'mail.log-2027-01-01');

        self::assertCount(1, $events);
        self::assertSame('2026-12-31 23:59:59.000', $events[0]->ts);
    }

    public function testConnectorMatchesFileGuards(): void
    {
        $pf = new PostfixLogConnector();
        self::assertTrue($pf->matchesFile('/data/log/mail/mail.log', 'Jan  2 10:00:00.123456 mail.example.com postfix/qmgr[50]: 1A2B3C4D5E: removed'));
        self::assertTrue($pf->matchesFile('/data/log/mail/mail.log', ''), 'Čerstvě rotovaný prázdný soubor');
        self::assertFalse($pf->matchesFile('/data/log/app-2026-01-02.log', '[2026-01-02 10:00:00] app.INFO: x'));

        $hm = new HMailServerLogConnector();
        self::assertTrue($hm->matchesFile('C:/logs/hmailserver_2026-01-02.log', ''));

        $me = new MailEnableLogConnector();
        self::assertTrue($me->matchesFile('C:/logs/SMTP-Activity-260102.log', ''));
        self::assertFalse($me->matchesFile('C:/logs/SMTP-Debug-260102.log', ''), 'Debug sada se má ignorovat');
    }

    /**
     * @param list<SmtpLogEvent> $events
     */
    private function firstOfKind(array $events, string $kind): ?SmtpLogEvent
    {
        foreach ($events as $e) {
            if ($e->kind === $kind) {
                return $e;
            }
        }
        return null;
    }
}
