<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\LogAnalysis;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Univerzální analýza logů poštovního serveru.
 *
 * Najde log soubory dle cfg glob vzoru (`smtp_log.path`), vybere konektor
 * (`smtp_log.connector`), nechá ho rozparsovat na jednotné {@see SmtpLogEvent}
 * a sestaví agregovaný přehled: kam co bylo doručeno, kde nastal problém.
 *
 * Rozšíření na další server = přidat třídu {@see SmtpLogConnectorInterface}
 * do {@see self::CONNECTORS} a vybrat ji v cfg. Zbytek (filtry, agregace,
 * UI, endpoint) je formátově nezávislý a funguje beze změny.
 */
final class SmtpLogAnalyzer
{
    /**
     * Registr dostupných konektorů: cfg klíč → třída. Přidej sem nový server.
     *
     * @var array<string,class-string<SmtpLogConnectorInterface>>
     */
    private const CONNECTORS = [
        'hmailserver' => HMailServerLogConnector::class,
        'mailenable'  => MailEnableLogConnector::class,
        'postfix'     => PostfixLogConnector::class,
    ];

    private const DEFAULT_MAX_FILES = 60;
    private const DEFAULT_MAX_BYTES = 20_971_520; // 20 MB / soubor
    // Bez filtru data se parsuje od nejnovějšího souboru, dokud počet událostí
    // nepřekročí tento strop — ať se u velkých serverů nečte celá historie na
    // každý požadavek (normalizuje napříč objemem: málo pošty = víc dnů, hodně
    // pošty = jen poslední dny). Starší se doberou přes filtr data. Cfg
    // `smtp_log.window_events`. Při zadaném filtru data se strop neuplatní.
    private const DEFAULT_WINDOW_EVENTS = 5000;

    // E-mailové akce v activity_log, které nesou příjemce + fakturu (pro korelaci).
    private const EMAIL_ACTIONS = [
        'invoice.sent',
        'invoice.reminder_sent',
        'invoice.approval_reminder_sent',
        'invoice.payment_thanks_sent',
        'recurring.reminder_sent',
    ];

    // Časové okno pro spárování log události s odeslaným e-mailem (sekundy).
    // Pokrývá i retry odložených doručení (hMailServer plánuje +60 min).
    private const CORRELATION_WINDOW_S = 6 * 3600;

    public function __construct(
        private readonly Config $config,
        // Nullable BEZ defaultu — PHP-DI autowiruje Connection (s `= null` by ho
        // přeskočil a injektnul null → korelace s fakturami by tiše nefungovala).
        private readonly ?Connection $db,
    ) {}

    /**
     * Je analýza nakonfigurovaná a zapnutá?
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('smtp_log.enabled', false)
            && $this->connector() !== null
            && (string) $this->config->get('smtp_log.path', '') !== '';
    }

    /**
     * Seznam podporovaných konektorů pro UI (key → label).
     *
     * @return list<array{key:string,label:string}>
     */
    public function availableConnectors(): array
    {
        $out = [];
        foreach (self::CONNECTORS as $key => $cls) {
            /** @var SmtpLogConnectorInterface $inst */
            $inst = new $cls();
            $out[] = ['key' => $key, 'label' => $inst->label()];
        }
        return $out;
    }

    /**
     * Spustí analýzu s volitelnými filtry.
     *
     * @param array{
     *   date_from?:?string, date_to?:?string, status?:?string, kind?:?string,
     *   search?:?string, limit?:int, offset?:int
     * } $filters
     * @return array<string,mixed>
     */
    public function analyze(array $filters = []): array
    {
        $connector = $this->connector();
        $pattern = (string) $this->config->get('smtp_log.path', '');
        if ($connector === null) {
            return $this->emptyResult('connector_unknown', $pattern);
        }

        // Rozliš tři případy prázdna:
        //  - 'unreadable'      glob našel soubory, ale žádný nejde přečíst (oprávnění souborů)
        //  - 'dir_unreadable'  adresář s logy neexistuje NEBO do něj aplikace nevidí
        //                      (typicky IIS app pool / chybí IIS_IUSRS na složce)
        //  - 'no_files'        adresář čitelný, ale vzoru nic neodpovídá (špatný vzor)
        $globMatched = count($this->globMatches($pattern));
        $allFiles = $this->resolveFiles();
        if ($allFiles === []) {
            if ($globMatched > 0) {
                $reason = 'unreadable';
            } else {
                $dir = $this->patternDir($pattern);
                $reason = ($dir !== '' && !@is_dir($dir)) ? 'dir_unreadable' : 'no_files';
            }
            return $this->emptyResult($reason, $pattern, $globMatched);
        }

        // Okno: s filtrem data jen soubory toho rozsahu; bez filtru se parsuje od
        // nejnovějšího, dokud se nepřekročí event-strop (zbytek se vynechá).
        $hasDateFilter = $this->normDate((string) ($filters['date_from'] ?? '')) !== ''
            || $this->normDate((string) ($filters['date_to'] ?? '')) !== '';
        $files = $this->selectWindowFiles($allFiles, (string) ($filters['date_from'] ?? ''), (string) ($filters['date_to'] ?? ''));
        $softCap = $hasDateFilter ? 0 : max(0, (int) $this->config->get('smtp_log.window_events', self::DEFAULT_WINDOW_EVENTS));

        $maxBytes = (int) $this->config->get('smtp_log.max_bytes', self::DEFAULT_MAX_BYTES);

        /** @var list<SmtpLogEvent> $events */
        $events = [];
        $scanned = [];
        $capped = false;
        foreach ($files as $path) {
            $size = @filesize($path);
            $chunk = $this->readHead($path, 4096);
            if ($chunk === null || !$connector->matchesFile($path, $chunk)) {
                continue;
            }
            $contents = $this->readFile($path, $maxBytes);
            if ($contents === null) {
                continue;
            }
            $parsed = $connector->parse($contents, basename($path));
            foreach ($parsed as $e) {
                $events[] = $e;
            }
            $scanned[] = [
                'file'      => basename($path),
                'size'      => $size !== false ? $size : null,
                'truncated' => $size !== false && $size > $maxBytes,
                'events'    => count($parsed),
            ];
            if ($softCap > 0 && count($events) >= $softCap) {
                $capped = true;
                break; // nejnovější soubory jsou první → máme poslední dění
            }
        }
        $windowed = $capped || count($scanned) < count($allFiles);

        // Řazení nejnovější první (stabilní dle ts).
        usort($events, static fn (SmtpLogEvent $a, SmtpLogEvent $b) => strcmp($b->ts, $a->ts));

        $summary = $this->summarize($events);

        $filtered = $this->applyFilters($events, $filters);
        $total = count($filtered);

        $limit = max(1, min(1000, (int) ($filters['limit'] ?? 200)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $page = array_slice($filtered, $offset, $limit);

        // Korelace s odeslanými e-maily aplikace → odkaz na fakturu + varsymbol.
        // Jen na zobrazenou stránku (efektivní); robustní napříč konektory.
        $pageArrays = $this->enrichWithInvoices(
            array_map(static fn (SmtpLogEvent $e) => $e->toArray(), $page)
        );

        return [
            'enabled'    => true,
            'connector'  => ['key' => $connector->key(), 'label' => $connector->label()],
            'reason'     => $scanned === [] ? 'no_matching_files' : null,
            'path'       => $pattern,
            'scanned'    => $scanned,
            'window'     => [
                'files_total'  => count($allFiles),
                'files_parsed' => count($scanned),
                'limited'      => $windowed,   // true = část historie vynechána (rozšiř filtrem data)
            ],
            'summary'    => $summary,
            'events'     => $pageArrays,
            'total'      => $total,
            'limit'      => $limit,
            'offset'     => $offset,
        ];
    }

    /**
     * Analýza doručení pro konkrétní fakturu — pro box „SMTP analýza" v detailu.
     *
     * Najde, kdy a komu aplikace fakturu (vč. upomínek/poděkování) odeslala
     * (z activity_log), a v logu poštovního serveru dohledá související události
     * pro tytéž příjemce v **den odeslání a den následující** (retry odložených
     * doručení padají typicky do dalšího dne). Nic jiného z logu neukáže.
     *
     * @return array<string,mixed>
     */
    public function analyzeForInvoice(int $invoiceId): array
    {
        $empty = ['enabled' => $this->isEnabled(), 'sent' => false, 'sends' => [], 'events' => [], 'recipients' => [], 'connector' => null];
        if (!$this->isEnabled() || $this->db === null) {
            return $empty;
        }
        $connector = $this->connector();
        if ($connector === null) {
            return $empty;
        }

        $sends = $this->invoiceSends($invoiceId);
        if ($sends === []) {
            return ['enabled' => true, 'sent' => false, 'sends' => [], 'events' => [], 'recipients' => [], 'connector' => ['key' => $connector->key(), 'label' => $connector->label()]];
        }

        // Self adresy dodavatele (kopie sobě v CC/BCC, From, reply-to) — vyřadit.
        // Jinak by se faktura párovala na VEŠKEROU poštu, co dodavateli ten den
        // přišla (self adresa je na každé faktuře) → samé nesouvisející události.
        $self = $this->supplierSelfAddresses($invoiceId);

        // Příjemci faktury (bez self) + cílové dny (den odeslání a +1).
        $recipients = [];
        $days = [];
        $minEpoch = null;
        $maxEpoch = null;
        foreach ($sends as $s) {
            foreach ($s['recipients'] as $r) {
                $lr = strtolower($r);
                if (in_array($lr, $self, true)) {
                    continue;
                }
                $recipients[$lr] = true;
            }
            $epoch = strtotime($s['ts']) ?: 0;
            $days[date('Y-m-d', $epoch)] = true;
            $days[date('Y-m-d', $epoch + 86400)] = true;
            $minEpoch = $minEpoch === null ? $epoch : min($minEpoch, $epoch);
            $maxEpoch = $maxEpoch === null ? $epoch : max($maxEpoch, $epoch);
        }

        // Faktura šla jen sobě (žádný externí příjemce) — není co v logu hledat.
        if ($recipients === []) {
            return ['enabled' => true, 'sent' => true, 'connector' => ['key' => $connector->key(), 'label' => $connector->label()], 'sends' => $sends, 'recipients' => [], 'events' => []];
        }

        $maxBytes = (int) $this->config->get('smtp_log.max_bytes', self::DEFAULT_MAX_BYTES);
        $events = [];
        foreach ($this->resolveFiles() as $path) {
            if (!$this->fileTouchesDays($path, $days)) {
                continue; // levné: přeskoč soubory mimo cílové dny (datum v názvu)
            }
            $chunk = $this->readHead($path, 4096);
            if ($chunk === null || !$connector->matchesFile($path, $chunk)) {
                continue;
            }
            $contents = $this->readFile($path, $maxBytes);
            if ($contents === null) {
                continue;
            }
            foreach ($connector->parse($contents, basename($path)) as $ev) {
                $day = substr($ev->ts, 0, 10);
                if (!isset($days[$day]) || $ev->recipients === []) {
                    continue;
                }
                $evRcpts = array_map('strtolower', $ev->recipients);
                if (array_intersect($evRcpts, array_keys($recipients)) === []) {
                    continue;
                }
                $events[] = $ev;
            }
        }

        usort($events, static fn (SmtpLogEvent $a, SmtpLogEvent $b) => strcmp($b->ts, $a->ts));

        // Per-příjemce rollup (poslední stav + počty) z doručovacích pokusů.
        $rollup = [];
        foreach ($recipients as $addr => $_) {
            $rollup[$addr] = ['recipient' => $addr, 'delivered' => 0, 'deferred' => 0, 'rejected' => 0, 'error' => 0, 'last_status' => null, 'last_ts' => ''];
        }
        foreach ($events as $ev) {
            if ($ev->kind !== SmtpLogEvent::KIND_DELIVERY) {
                continue;
            }
            foreach (array_map('strtolower', $ev->recipients) as $addr) {
                if (!isset($rollup[$addr])) {
                    continue;
                }
                if (isset($rollup[$addr][$ev->status])) {
                    $rollup[$addr][$ev->status]++;
                }
                if ($ev->ts > $rollup[$addr]['last_ts']) {
                    $rollup[$addr]['last_ts'] = $ev->ts;
                    $rollup[$addr]['last_status'] = $ev->status;
                }
            }
        }

        return [
            'enabled'    => true,
            'sent'       => true,
            'connector'  => ['key' => $connector->key(), 'label' => $connector->label()],
            'sends'      => $sends,
            'recipients' => array_values($rollup),
            'events'     => array_map(static fn (SmtpLogEvent $e) => $e->toArray(), $events),
        ];
    }

    /**
     * Odeslání dané faktury z activity_log (sent/upomínky/poděkování) —
     * čas + příjemci + typ akce.
     *
     * @return list<array{ts:string,recipients:list<string>,action:string}>
     */
    private function invoiceSends(int $invoiceId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::EMAIL_ACTIONS), '?'));
        $sql = "SELECT al.action, al.payload, al.created_at
                  FROM activity_log al
                 WHERE al.action IN ($placeholders)
                   AND (
                        (al.entity_type = 'invoice' AND al.entity_id = ?)
                     OR JSON_UNQUOTE(JSON_EXTRACT(al.payload, '$.invoice_id')) = ?
                   )
              ORDER BY al.created_at ASC
                 LIMIT 200";
        try {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([...self::EMAIL_ACTIONS, $invoiceId, (string) $invoiceId]);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $payload = $r['payload'] !== null ? json_decode((string) $r['payload'], true) : [];
            if (!is_array($payload)) {
                $payload = [];
            }
            $rcpts = $this->recipientsFromPayload($payload);
            if ($rcpts === []) {
                continue;
            }
            $out[] = [
                'ts'         => (string) $r['created_at'],
                'recipients' => $rcpts,
                'action'     => (string) $r['action'],
            ];
        }
        return $out;
    }

    /**
     * Self adresy dodavatele dané faktury — kopie sobě se z analýzy vyřazuje,
     * protože je na každé faktuře a zaplevelila by výsledek nesouvisející poštou.
     * Zahrnuje e-mail dodavatele (dle invoice.supplier_id) + globální From/reply-to.
     *
     * @return list<string> lowercased adresy
     */
    private function supplierSelfAddresses(int $invoiceId): array
    {
        $set = [];
        foreach (['smtp.from_email', 'smtp.reply_to_email'] as $key) {
            $v = strtolower(trim((string) $this->config->get($key, '')));
            if ($v !== '') {
                $set[$v] = true;
            }
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT s.email FROM invoices i JOIN supplier s ON s.id = i.supplier_id WHERE i.id = ?'
            );
            $stmt->execute([$invoiceId]);
            $email = strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
            if ($email !== '') {
                $set[$email] = true;
            }
        } catch (\Throwable) {
            // bez DB self adresy nezjistíme — vrátíme aspoň cfg adresy
        }
        return array_keys($set);
    }

    /**
     * Levný prefilter: nese název souboru datum patřící do cílových dnů?
     * Podporuje `YYYY-MM-DD` (hMailServer) i `YYMMDD` (MailEnable). Když datum
     * nelze z názvu vyčíst, soubor se NEpřeskočí (parsuje se pro jistotu).
     *
     * @param array<string,bool> $days  klíče 'Y-m-d'
     */
    private function fileTouchesDays(string $path, array $days): bool
    {
        $base = basename($path);
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $base, $m)) {
            return isset($days[$m[1]]);
        }
        if (preg_match('/(\d{2})(\d{2})(\d{2})(?!\d)/', $base, $m)) {
            return isset($days['20' . $m[1] . '-' . $m[2] . '-' . $m[3]]);
        }
        return true;
    }

    /**
     * Doplní k událostem odkaz na fakturu z aplikačního activity_logu.
     * Páruje přes příjemce + nejbližší čas odeslání (do CORRELATION_WINDOW_S).
     * Fallback: varsymbol z předmětu (MailEnable nese subject) → dohledání invoice_id.
     * Vždy nastaví klíče `invoice_id` a `invoice_varsymbol` (null, když nenapárováno
     * nebo bez DB — CLI/testy), ať má JSON konzistentní tvar.
     *
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private function enrichWithInvoices(array $events): array
    {
        foreach ($events as &$e) {
            $e['invoice_id'] = null;
            $e['invoice_varsymbol'] = null;
        }
        unset($e);

        if ($this->db === null || $events === []) {
            return $events;
        }

        try {
            $tsList = array_values(array_filter(array_map(static fn ($e) => (string) ($e['ts'] ?? ''), $events)));
            if ($tsList === []) {
                return $events;
            }
            $from = date('Y-m-d H:i:s', (strtotime(min($tsList)) ?: time()) - self::CORRELATION_WINDOW_S);
            $to   = date('Y-m-d H:i:s', (strtotime(max($tsList)) ?: time()) + self::CORRELATION_WINDOW_S);
            $index = $this->loadSentIndex($from, $to);
        } catch (\Throwable) {
            return $events; // korelace je best-effort
        }

        foreach ($events as &$e) {
            $recipients = array_map('strtolower', array_map('strval', (array) ($e['recipients'] ?? [])));
            $ets = strtotime((string) ($e['ts'] ?? ''));
            if ($recipients === [] || $ets === false) {
                continue;
            }
            $best = null;
            $bestDelta = null;
            foreach ($index as $entry) {
                if (array_intersect($recipients, $entry['recipients']) === []) {
                    continue;
                }
                $delta = abs($ets - $entry['ts']);
                if ($delta > self::CORRELATION_WINDOW_S) {
                    continue;
                }
                if ($bestDelta === null || $delta < $bestDelta) {
                    $bestDelta = $delta;
                    $best = $entry;
                }
            }
            if ($best !== null) {
                $e['invoice_id'] = $best['invoice_id'];
                $e['invoice_varsymbol'] = $best['varsymbol'];
            }
        }
        unset($e);

        $this->fillFromSubject($events);

        return $events;
    }

    /**
     * Načte odeslané e-maily z activity_log v daném okně → záznamy s množinou
     * příjemců (lowercased), invoice_id a varsymbolem.
     *
     * @return list<array{ts:int,recipients:list<string>,invoice_id:?int,varsymbol:?string}>
     */
    private function loadSentIndex(string $from, string $to): array
    {
        $placeholders = implode(',', array_fill(0, count(self::EMAIL_ACTIONS), '?'));
        $sql = "SELECT al.payload, al.created_at,
                       i.id AS invoice_id, i.varsymbol
                  FROM activity_log al
             LEFT JOIN invoices i ON i.id = COALESCE(
                       CASE WHEN al.entity_type = 'invoice' THEN al.entity_id END,
                       JSON_UNQUOTE(JSON_EXTRACT(al.payload, '$.invoice_id'))
                   )
                 WHERE al.action IN ($placeholders)
                   AND al.created_at BETWEEN ? AND ?
              ORDER BY al.id DESC
                 LIMIT 5000";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([...self::EMAIL_ACTIONS, $from, $to]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $payload = $r['payload'] !== null ? json_decode((string) $r['payload'], true) : [];
            if (!is_array($payload)) {
                $payload = [];
            }
            $rcpts = $this->recipientsFromPayload($payload);
            if ($rcpts === []) {
                continue;
            }
            $out[] = [
                'ts'         => strtotime((string) $r['created_at']) ?: 0,
                'recipients' => $rcpts,
                'invoice_id' => $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null,
                'varsymbol'  => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            ];
        }
        return $out;
    }

    /**
     * Sjednotí příjemce z payloadu (to/cc/bcc/recipients; string|pole) → lowercased pole.
     *
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function recipientsFromPayload(array $payload): array
    {
        $all = [];
        foreach (['to', 'cc', 'bcc', 'recipients'] as $key) {
            $raw = $payload[$key] ?? [];
            if (is_string($raw)) {
                $raw = [$raw];
            }
            if (is_array($raw)) {
                foreach ($raw as $v) {
                    if (is_string($v) && trim($v) !== '') {
                        $all[] = strtolower(trim($v));
                    }
                }
            }
        }
        return array_values(array_unique($all));
    }

    /**
     * Fallback korelace pro nespárované události: varsymbol z předmětu
     * (myinvoice posílá „Faktura {VS} — …", MailEnable subject loguje) → ověř
     * existenci faktury v DB a teprve pak nalinkuj (cizí předměty se nenapárují).
     *
     * @param list<array<string,mixed>> $events
     */
    private function fillFromSubject(array &$events): void
    {
        $candidates = [];
        foreach ($events as $e) {
            if (($e['invoice_id'] ?? null) !== null) {
                continue;
            }
            $vs = $this->varsymbolFromSubject((string) ($e['subject'] ?? ''));
            if ($vs !== null) {
                $candidates[$vs] = true;
            }
        }
        if ($candidates === []) {
            return;
        }

        $vsList = array_keys($candidates);
        try {
            $placeholders = implode(',', array_fill(0, count($vsList), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT id, varsymbol FROM invoices WHERE varsymbol IN ($placeholders)");
            $stmt->execute($vsList);
            $map = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $map[(string) $r['varsymbol']] = (int) $r['id'];
            }
        } catch (\Throwable) {
            return;
        }

        foreach ($events as &$e) {
            if (($e['invoice_id'] ?? null) !== null) {
                continue;
            }
            $vs = $this->varsymbolFromSubject((string) ($e['subject'] ?? ''));
            if ($vs !== null && isset($map[$vs])) {
                $e['invoice_id'] = $map[$vs];
                $e['invoice_varsymbol'] = $vs;
            }
        }
        unset($e);
    }

    private function varsymbolFromSubject(string $subject): ?string
    {
        if ($subject === '') {
            return null;
        }
        // „Faktura 2606004 — …", „Invoice 2606004", „proforma 2606004", „záloha …".
        if (preg_match('/\b(?:Faktura|Invoice|proforma|zálohu|záloha|zálohy)\s+([0-9][0-9A-Za-z\/\-]{2,})/iu', $subject, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * @param list<SmtpLogEvent> $events
     * @return array<string,mixed>
     */
    private function summarize(array $events): array
    {
        $byStatus = [];
        $byDay = [];
        $byHost = [];
        $problems = [];     // doručovací problémy (deferred/rejected/error)
        $recipients = [];   // per-příjemce rollup

        foreach ($events as $e) {
            $byStatus[$e->status] = ($byStatus[$e->status] ?? 0) + 1;

            $day = substr($e->ts, 0, 10);
            if ($day !== '') {
                $byDay[$day] ??= ['delivered' => 0, 'deferred' => 0, 'rejected' => 0, 'queued' => 0, 'error' => 0, 'info' => 0];
                $byDay[$day][$e->status] = ($byDay[$day][$e->status] ?? 0) + 1;
            }

            if ($e->kind === SmtpLogEvent::KIND_DELIVERY && $e->remoteHost !== null) {
                $byHost[$e->remoteHost] ??= ['delivered' => 0, 'deferred' => 0, 'rejected' => 0, 'error' => 0];
                $byHost[$e->remoteHost][$e->status] = ($byHost[$e->remoteHost][$e->status] ?? 0) + 1;
            }

            // Per-příjemce jen z doručovacích pokusů (kam co reálně šlo).
            if ($e->kind === SmtpLogEvent::KIND_DELIVERY) {
                foreach ($e->recipients as $rcpt) {
                    $recipients[$rcpt] ??= ['delivered' => 0, 'deferred' => 0, 'rejected' => 0, 'error' => 0, 'last_ts' => '', 'last_status' => ''];
                    if (isset($recipients[$rcpt][$e->status])) {
                        $recipients[$rcpt][$e->status]++;
                    }
                    if ($e->ts > $recipients[$rcpt]['last_ts']) {
                        $recipients[$rcpt]['last_ts'] = $e->ts;
                        $recipients[$rcpt]['last_status'] = $e->status;
                    }
                }
            }

            if (in_array($e->status, [SmtpLogEvent::STATUS_DEFERRED, SmtpLogEvent::STATUS_REJECTED, SmtpLogEvent::STATUS_ERROR], true)
                && $e->kind !== SmtpLogEvent::KIND_SUBMISSION) {
                $problems[] = $e->toArray();
            }
        }

        krsort($byDay);
        arsort($byStatus);

        // Top hosty dle objemu.
        uasort($byHost, static fn ($a, $b) => array_sum($b) <=> array_sum($a));

        $recipientsList = [];
        foreach ($recipients as $addr => $r) {
            $recipientsList[] = ['recipient' => $addr] + $r;
        }
        usort($recipientsList, static fn ($a, $b) => strcmp($b['last_ts'], $a['last_ts']));

        return [
            'total_events'  => count($events),
            'deliveries'    => array_sum(array_map(static fn ($e) => $e->kind === SmtpLogEvent::KIND_DELIVERY ? 1 : 0, $events)),
            'submissions'   => array_sum(array_map(static fn ($e) => $e->kind === SmtpLogEvent::KIND_SUBMISSION ? 1 : 0, $events)),
            'by_status'     => $byStatus,
            'by_day'        => $byDay,
            'by_host'       => $byHost,
            'recipients'    => array_slice($recipientsList, 0, 200),
            'problems'      => array_slice($problems, 0, 200),
        ];
    }

    /**
     * @param list<SmtpLogEvent> $events
     * @param array<string,mixed> $filters
     * @return list<SmtpLogEvent>
     */
    private function applyFilters(array $events, array $filters): array
    {
        $from   = $this->normDate((string) ($filters['date_from'] ?? ''));
        $to     = $this->normDate((string) ($filters['date_to'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        $kind   = (string) ($filters['kind'] ?? '');
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return array_values(array_filter($events, static function (SmtpLogEvent $e) use ($from, $to, $status, $kind, $search) {
            $day = substr($e->ts, 0, 10);
            if ($from !== '' && $day < $from) return false;
            if ($to !== '' && $day > $to) return false;
            // 'rejected_error' = složený filtr karty „Odmítnuto" (rejected + error).
            if ($status === 'rejected_error') {
                if ($e->status !== SmtpLogEvent::STATUS_REJECTED && $e->status !== SmtpLogEvent::STATUS_ERROR) return false;
            } elseif ($status !== '' && $e->status !== $status) {
                return false;
            }
            if ($kind !== '' && $e->kind !== $kind) return false;
            if ($search !== '') {
                $hay = mb_strtolower(implode(' ', [
                    $e->mailFrom ?? '',
                    implode(' ', $e->recipients),
                    $e->remoteHost ?? '',
                    $e->remoteIp ?? '',
                    $e->response ?? '',
                    $e->messageId ?? '',
                ]));
                if (!str_contains($hay, $search)) return false;
            }
            return true;
        }));
    }

    /**
     * Syrové shody glob vzoru (bez filtru čitelnosti) — pro odlišení
     * „špatná cesta" (0 shod) od „nečitelné kvůli oprávněním" (shody > 0,
     * ale žádný soubor nejde přečíst).
     *
     * @return list<string>
     */
    private function globMatches(string $pattern): array
    {
        if ($pattern === '') {
            return [];
        }
        return array_values(glob($pattern, GLOB_NOSORT) ?: []);
    }

    /**
     * Najde a seřadí log soubory dle glob vzoru (nejnovější dle mtime první),
     * omezené na `smtp_log.max_files`.
     *
     * @return list<string>
     */
    private function resolveFiles(): array
    {
        $pattern = (string) $this->config->get('smtp_log.path', '');
        // glob() na Windows zvládá / i \.
        $matches = $this->globMatches($pattern);
        $files = array_values(array_filter($matches, static fn ($p) => is_file($p) && is_readable($p)));

        usort($files, static function (string $a, string $b): int {
            $ma = @filemtime($a) ?: 0;
            $mb = @filemtime($b) ?: 0;
            return $mb <=> $ma;
        });

        $maxFiles = max(1, (int) $this->config->get('smtp_log.max_files', self::DEFAULT_MAX_FILES));
        return array_slice($files, 0, $maxFiles);
    }

    /**
     * Adresářová část glob vzoru (vše před posledním oddělovačem). Pro
     * rozlišení „adresář nejde otevřít" od „vzoru nic neodpovídá".
     */
    private function patternDir(string $pattern): string
    {
        $pattern = str_replace('\\', '/', $pattern);
        $pos = strrpos($pattern, '/');
        return $pos !== false ? substr($pattern, 0, $pos) : '';
    }

    /**
     * Vybere soubory k parsování. Bez filtru data: jen nejnovějších N (denních)
     * souborů (`smtp_log.window_files`). S filtrem data: jen soubory, jejichž
     * datum v názvu spadá do rozsahu (slack -1 den, události přetékají do dalšího
     * dne). Soubory bez rozpoznatelného data se ponechají (jistota).
     *
     * @param list<string> $files  seřazené nejnovější dle mtime první
     * @return list<string>
     */
    private function selectWindowFiles(array $files, string $dateFrom, string $dateTo): array
    {
        $df = $this->normDate($dateFrom);
        $dt = $this->normDate($dateTo);

        if ($df !== '' || $dt !== '') {
            $lo = $df !== '' ? date('Y-m-d', (strtotime($df) ?: 0) - 86400) : '';
            $out = [];
            foreach ($files as $p) {
                $d = $this->fileDate($p);
                if ($d === null) {
                    $out[] = $p;
                    continue;
                }
                if ($lo !== '' && $d < $lo) {
                    continue;
                }
                if ($dt !== '' && $d > $dt) {
                    continue;
                }
                $out[] = $p;
            }
            return $out;
        }

        // Bez filtru data vrať vše (nejnovější první) — parse loop přestane
        // u event-stropu, takže se reálně načtou jen poslední dny.
        return $files;
    }

    /**
     * Datum z názvu log souboru: `YYYY-MM-DD` (hMailServer) nebo `YYMMDD`
     * (MailEnable) → 'Y-m-d'. Null když nelze rozpoznat.
     */
    private function fileDate(string $path): ?string
    {
        $base = basename($path);
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $base, $m)) {
            return $m[1];
        }
        if (preg_match('/(\d{2})(\d{2})(\d{2})(?!\d)/', $base, $m)) {
            return '20' . $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        return null;
    }

    private function connector(): ?SmtpLogConnectorInterface
    {
        $key = (string) $this->config->get('smtp_log.connector', 'hmailserver');
        $cls = self::CONNECTORS[$key] ?? null;
        if ($cls === null) {
            return null;
        }
        /** @var SmtpLogConnectorInterface */
        return new $cls();
    }

    private function readHead(string $path, int $bytes): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $data = @fread($fh, $bytes);
        @fclose($fh);
        return $data === false ? null : $data;
    }

    private function readFile(string $path, int $maxBytes): ?string
    {
        $size = @filesize($path);
        if ($size !== false && $size > $maxBytes) {
            // Čteme jen konec souboru (nejnovější záznamy), ať nepřekročíme strop.
            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                return null;
            }
            @fseek($fh, -$maxBytes, SEEK_END);
            // Zahodíme prvních (neúplných) pár znaků po seeku do poloviny řádku.
            $data = @stream_get_contents($fh);
            @fclose($fh);
            if ($data === false) {
                return null;
            }
            $nl = strpos($data, "\n");
            return $nl !== false ? substr($data, $nl + 1) : $data;
        }
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyResult(string $reason, string $path = '', int $globMatched = 0): array
    {
        $connector = $this->connector();
        return [
            'enabled'      => $this->isEnabled(),
            'connector'    => $connector !== null ? ['key' => $connector->key(), 'label' => $connector->label()] : null,
            'reason'       => $reason,
            'path'         => $path,
            'glob_matched' => $globMatched,
            'scanned'      => [],
            'summary'   => [
                'total_events' => 0, 'deliveries' => 0, 'submissions' => 0,
                'by_status' => [], 'by_day' => [], 'by_host' => [],
                'recipients' => [], 'problems' => [],
            ],
            'events'    => [],
            'total'     => 0,
            'limit'     => 0,
            'offset'    => 0,
        ];
    }

    private function normDate(string $s): string
    {
        $s = trim($s);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 ? $s : '';
    }
}
