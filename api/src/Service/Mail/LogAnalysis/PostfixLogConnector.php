<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\LogAnalysis;

/**
 * Konektor pro logy Postfixu (Linux MTA, typicky hostitel Docker instalace).
 *
 * Formát řádku (syslog):
 *   "Sep 18 21:19:05.123456 host postfix/smtp[123]: 05D9F508034A: to=<a@example.org>, relay=…, status=sent (250 …)"
 * Postfix `maillog_file` (postlogd) a klasický syslog píší BSD čas bez roku;
 * journald export (`journalctl -o short-iso`) a rsyslog v RFC 3339 formátu
 * nesou ISO čas. Oba tvary se čtou; chybějící rok se odvodí z data v názvu
 * rotovaného souboru (`mail.log-20260922`), jinak z dneška, s přetočením přes
 * Nový rok (prosincový řádek v lednovém souboru patří do minulého roku).
 *
 * Postfix loguje po frontových ID (queue ID), ne po SMTP session:
 *  - smtpd `client=` / pickup `uid= from=` = zpráva vstoupila → {@see SmtpLogEvent::KIND_SUBMISSION}
 *  - qmgr `from=<…>, size=…` = odesílatel obálky
 *  - smtp/lmtp/local/virtual/error `to=<…>, relay=…, status=…` = doručovací pokus → KIND_DELIVERY
 *  - cleanup `reject:`, qmgr `status=expired`, bounce notifikace, `NOQUEUE: reject:` → KIND_NOTICE
 *  - cleanup `header Subject:` (jen s header_checks `/^Subject:/ INFO`) = předmět zprávy
 *
 * Queue ID slouží jako `messageId` i `session`, takže UI spojí podání se všemi
 * doručovacími pokusy téže zprávy.
 */
final class PostfixLogConnector implements SmtpLogConnectorInterface
{
    private const LINE_RE = '/^(?:(?<iso>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?)(?:Z|[+-]\d{2}:?\d{2})?'
        . '|(?<bsd>[A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}(?:\.\d+)?))'
        . '\s+\S+\s+(?<prog>postfix[^\[\s:]*)(?:\[\d+\])?:\s+(?<msg>.*)$/';

    private const QUEUE_ID_RE = '/^([0-9A-Za-z]{8,20}):\s+(.*)$/';

    // Slova na začátku zprávy, která vypadají jako queue ID, ale nejsou.
    private const NOT_QUEUE_IDS = ['warning', 'NOQUEUE', 'statistics', 'fatal', 'panic', 'error', 'info'];

    private const DELIVERY_AGENTS = ['smtp', 'lmtp', 'local', 'virtual', 'error', 'retry', 'pipe', 'discard'];

    private const MONTHS = [
        'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
        'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
    ];

    public function key(): string
    {
        return 'postfix';
    }

    public function label(): string
    {
        return 'Postfix';
    }

    public function matchesFile(string $path, string $firstChunk): bool
    {
        if (preg_match('/^\S.*?\spostfix[^\[\s:]*(?:\[\d+\])?:\s/m', $firstChunk)) {
            return true;
        }
        // Čerstvě rotovaný (prázdný) soubor nebo začátek bez postfix řádku.
        $base = strtolower(basename($path));
        return trim($firstChunk) === '' && (str_starts_with($base, 'mail') || str_contains($base, 'postfix'));
    }

    public function parse(string $contents, string $sourceFile): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        [$refYear, $refMonth] = $this->referenceDate($sourceFile);

        /** @var array<string,array{ts:string,client_host:?string,client_ip:?string,from:?string,subject:?string,submitted:bool,rejected:?string,rcpts:array<string,bool>,deliveries:list<array<string,mixed>>,notices:list<array<string,mixed>>}> $queue */
        $queue = [];
        $order = [];
        /** @var list<SmtpLogEvent> $loose */
        $loose = [];

        foreach ($lines as $line) {
            if ($line === '' || !preg_match(self::LINE_RE, $line, $m)) {
                continue;
            }
            $ts = $m['iso'] !== ''
                ? $this->isoTs($m['iso'])
                : $this->bsdTs($m['bsd'], $refYear, $refMonth);
            if ($ts === null) {
                continue;
            }
            $service = $this->service($m['prog']);
            $msg = $m['msg'];

            if (str_starts_with($msg, 'NOQUEUE: ')) {
                $event = $this->noQueueReject($ts, substr($msg, 9), $sourceFile);
                if ($event !== null) {
                    $loose[] = $event;
                }
                continue;
            }

            if (!preg_match(self::QUEUE_ID_RE, $msg, $q) || in_array($q[1], self::NOT_QUEUE_IDS, true)) {
                continue;
            }
            $id = $q[1];
            $rest = $q[2];

            if (!isset($queue[$id])) {
                $queue[$id] = [
                    'ts' => $ts, 'client_host' => null, 'client_ip' => null, 'from' => null,
                    'subject' => null, 'submitted' => false, 'rejected' => null,
                    'rcpts' => [], 'deliveries' => [], 'notices' => [],
                ];
                $order[] = $id;
            }
            $entry = &$queue[$id];

            if ($service === 'smtpd' && preg_match('/^client=([^\[,\s]+)(?:\[([^\]]*)\])?/', $rest, $c)) {
                $entry['submitted'] = true;
                $entry['ts'] = $ts;
                $entry['client_host'] = $c[1] !== 'unknown' ? $c[1] : null;
                $entry['client_ip'] = ($c[2] ?? '') !== '' ? $c[2] : null;
            } elseif ($service === 'pickup' && preg_match('/^uid=\d+ from=<([^>]*)>/', $rest, $c)) {
                $entry['submitted'] = true;
                $entry['ts'] = $ts;
                $entry['client_host'] = 'localhost';
                $entry['from'] ??= $c[1] !== '' ? $c[1] : null;
            } elseif ($service === 'cleanup') {
                if (preg_match('/^(?:info|warning): header Subject: (.*?) from \S+; from=</', $rest, $c)) {
                    $entry['subject'] = $this->decodeSubject($c[1]);
                } elseif (preg_match('/^(?:reject|milter-reject|discard): (.*?)(?:; from=<([^>]*)> to=<([^>]*)>.*)?$/', $rest, $c)) {
                    $entry['rejected'] = $c[1];
                    $entry['notices'][] = [
                        'ts' => $ts, 'status' => SmtpLogEvent::STATUS_REJECTED, 'response' => $c[1],
                        'recipients' => ($c[3] ?? '') !== '' ? [$c[3]] : [],
                        'code' => $this->code($c[1]),
                    ];
                }
            } elseif ($service === 'qmgr') {
                if (preg_match('/^from=<([^>]*)>, status=expired, (.*)$/', $rest, $c)) {
                    $entry['from'] ??= $c[1] !== '' ? $c[1] : null;
                    $entry['notices'][] = [
                        'ts' => $ts, 'status' => SmtpLogEvent::STATUS_REJECTED,
                        'response' => 'expired, ' . $c[2], 'recipients' => [], 'code' => null,
                    ];
                } elseif (preg_match('/^from=<([^>]*)>/', $rest, $c)) {
                    $entry['from'] ??= $c[1] !== '' ? $c[1] : null;
                }
            } elseif ($service === 'bounce' && preg_match('/^sender (non-delivery|delay|delivery status) notification: (\S+)/', $rest, $c)) {
                $entry['notices'][] = [
                    'ts' => $ts, 'status' => SmtpLogEvent::STATUS_INFO,
                    'response' => 'sender ' . $c[1] . ' notification: ' . $c[2], 'recipients' => [], 'code' => null,
                ];
            } elseif (in_array($service, self::DELIVERY_AGENTS, true)) {
                $delivery = $this->delivery($ts, $rest);
                if ($delivery !== null) {
                    $entry['deliveries'][] = $delivery;
                    $entry['rcpts'][$delivery['recipient']] = true;
                }
            }
            unset($entry);
        }

        $events = [];
        foreach ($order as $id) {
            $e = $queue[$id];
            if ($e['submitted']) {
                $events[] = new SmtpLogEvent(
                    ts: $e['ts'],
                    kind: SmtpLogEvent::KIND_SUBMISSION,
                    status: $e['rejected'] !== null ? SmtpLogEvent::STATUS_REJECTED : SmtpLogEvent::STATUS_QUEUED,
                    mailFrom: $e['from'],
                    recipients: array_keys($e['rcpts']),
                    remoteHost: $e['client_host'],
                    remoteIp: $e['client_ip'],
                    code: null,
                    response: $e['rejected'],
                    messageId: $id,
                    sourceFile: $sourceFile,
                    session: $id,
                    subject: $e['subject'],
                );
            }
            foreach ($e['deliveries'] as $d) {
                $events[] = new SmtpLogEvent(
                    ts: $d['ts'],
                    kind: SmtpLogEvent::KIND_DELIVERY,
                    status: $d['status'],
                    mailFrom: $e['from'],
                    recipients: [$d['recipient']],
                    remoteHost: $d['host'],
                    remoteIp: $d['ip'],
                    code: $d['code'],
                    response: $d['response'],
                    messageId: $id,
                    sourceFile: $sourceFile,
                    session: $id,
                    subject: $e['subject'],
                );
            }
            foreach ($e['notices'] as $n) {
                $events[] = new SmtpLogEvent(
                    ts: $n['ts'],
                    kind: SmtpLogEvent::KIND_NOTICE,
                    status: $n['status'],
                    mailFrom: $e['from'],
                    recipients: $n['recipients'] !== [] ? $n['recipients'] : array_keys($e['rcpts']),
                    remoteHost: null,
                    remoteIp: null,
                    code: $n['code'],
                    response: $n['response'],
                    messageId: $id,
                    sourceFile: $sourceFile,
                    session: $id,
                    subject: $e['subject'],
                );
            }
        }

        return array_merge($events, $loose);
    }

    /**
     * `to=<a@example.org>, orig_to=<…>, relay=mx.example.org[192.0.2.1]:25, delay=…, dsn=2.0.0, status=sent (250 2.0.0 Ok)`
     *
     * @return array{ts:string,recipient:string,host:?string,ip:?string,status:string,code:?int,response:?string}|null
     */
    private function delivery(string $ts, string $rest): ?array
    {
        if (!preg_match('/^to=<([^>]*)>,(?: orig_to=<[^>]*>,)? relay=([^,]+),.*?\bstatus=([a-z]+)(?: \((.*)\))?$/', $rest, $m)) {
            return null;
        }
        $host = null;
        $ip = null;
        $relay = $m[2];
        if (preg_match('/^([^\[]+)\[([^\]]*)\]/', $relay, $r)) {
            $host = $r[1];
            $ip = $r[2] !== '' ? $r[2] : null;
        } elseif ($relay !== 'none') {
            $host = $relay;
        }
        $response = ($m[4] ?? '') !== '' ? $m[4] : null;

        $status = match ($m[3]) {
            'sent'                            => SmtpLogEvent::STATUS_DELIVERED,
            'deferred'                        => SmtpLogEvent::STATUS_DEFERRED,
            'bounced', 'expired', 'undeliverable' => SmtpLogEvent::STATUS_REJECTED,
            'deliverable'                     => SmtpLogEvent::STATUS_INFO,
            default                           => SmtpLogEvent::STATUS_ERROR,
        };

        return [
            'ts'        => $ts,
            'recipient' => $m[1],
            'host'      => $host,
            'ip'        => $ip,
            'status'    => $status,
            'code'      => $response !== null ? $this->code($response) : null,
            'response'  => $response,
        ];
    }

    /**
     * `reject: RCPT from host[192.0.2.9]: 554 5.7.1 <x@example.org>: Relay access denied; from=<a@example.com> to=<x@example.org> proto=ESMTP helo=<h>`
     */
    private function noQueueReject(string $ts, string $rest, string $sourceFile): ?SmtpLogEvent
    {
        if (!preg_match('/^(?:reject|milter-reject): \S+ from ([^\[\s]+)(?:\[([^\]]*)\])?: (.*?)(?:; from=<([^>]*)> to=<([^>]*)>.*)?$/', $rest, $m)) {
            return null;
        }
        return new SmtpLogEvent(
            ts: $ts,
            kind: SmtpLogEvent::KIND_NOTICE,
            status: SmtpLogEvent::STATUS_REJECTED,
            mailFrom: ($m[4] ?? '') !== '' ? $m[4] : null,
            recipients: ($m[5] ?? '') !== '' ? [$m[5]] : [],
            remoteHost: $m[1] !== 'unknown' ? $m[1] : null,
            remoteIp: ($m[2] ?? '') !== '' ? $m[2] : null,
            code: $this->code($m[3]),
            response: $m[3],
            messageId: null,
            sourceFile: $sourceFile,
            session: 'NOQUEUE',
        );
    }

    /**
     * SMTP kód z textu odpovědi: „250 2.0.0 Ok", „host mx[…] said: 550 5.1.1 …",
     * „… refused to talk to me: 421 …". Bez kódu (spojení selhalo) null.
     */
    private function code(string $text): ?int
    {
        if (preg_match('/(?:^|said: |to me: |: )([245]\d{2})[ -]/', $text, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function service(string $prog): string
    {
        $pos = strrpos($prog, '/');
        return $pos !== false ? substr($prog, $pos + 1) : $prog;
    }

    private function isoTs(string $iso): string
    {
        return str_replace('T', ' ', substr($iso, 0, 19)) . $this->millis(substr($iso, 19));
    }

    private function bsdTs(string $bsd, int $refYear, int $refMonth): ?string
    {
        if (!preg_match('/^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d{2}:\d{2}:\d{2})(\.\d+)?$/', $bsd, $m)) {
            return null;
        }
        $month = self::MONTHS[$m[1]] ?? null;
        if ($month === null) {
            return null;
        }
        $year = $month > $refMonth ? $refYear - 1 : $refYear;
        return sprintf('%04d-%02d-%02d %s', $year, $month, (int) $m[2], $m[3]) . $this->millis($m[4] ?? '');
    }

    private function millis(string $fraction): string
    {
        $digits = ltrim($fraction, '.');
        return '.' . str_pad(substr($digits, 0, 3), 3, '0');
    }

    /**
     * Rok a měsíc, ke kterému se vztahují řádky bez roku: datum v názvu
     * rotovaného souboru (logrotate `dateext`), jinak dnešek.
     *
     * @return array{int,int}
     */
    private function referenceDate(string $sourceFile): array
    {
        $base = basename($sourceFile);
        if (preg_match('/(20\d{2})-?(\d{2})-?\d{2}/', $base, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return [(int) $m[1], (int) $m[2]];
        }
        return [(int) date('Y'), (int) date('n')];
    }

    private function decodeSubject(string $raw): string
    {
        $raw = trim($raw);
        if (str_contains($raw, '=?')) {
            $decoded = @mb_decode_mimeheader($raw);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }
        return $raw;
    }
}
