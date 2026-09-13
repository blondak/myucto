<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Jednotný výpočet e-mailových příjemců pro odchozí zprávy klientům (#86).
 *
 * Nahrazuje šest dříve duplicitních resolveRecipients() implementací
 * (SendEmailAction, ReminderService, AutoIssueAndSendService,
 * RequestApprovalAction, cron-send-approval-reminders, PaymentThanksMailer).
 *
 * Vstupy: typ zprávy + invoice row (client_id, client_main_email, project_id).
 *
 * Logika (priorita shora):
 *   1. Aktivní kontakty klienta (client_email_contacts) s účelem = typ zprávy;
 *      u upomínek fallback na účel `documents` (issue #86).
 *   2. Bez kontaktů pro daný účel platí PŘESNĚ dosavadní legacy logika:
 *        documents/reminders: main_email + project_billing_emails (append),
 *        approvals:           project_billing_emails NEBO main_email (replace,
 *                             nikdy nesměšovat).
 *
 * E-maily zakázky (project_billing_emails) se kombinují dle
 * projects.billing_emails_mode:
 *   auto    (default) — kontakty existují → append; legacy větev → dosavadní
 *                       per-typ sémantika (viz výše). 100% zpětná kompatibilita.
 *   append  — e-maily zakázky se vždy přidají (i pro approvals legacy větev).
 *   replace — e-maily zakázky (jsou-li) příjemce nahradí; prázdné → jako auto.
 *
 * Kopie dodavateli (audit vlastních odchozích zpráv): supplier.self_copy JSON
 * ({"documents":"cc","reminders":"off","approvals":"bcc"}) — chybějící klíč
 * nebo NULL sloupec = fallback na cfg flagy (smtp.cc_supplier_on_send/_reminder
 * → CC, approval.cc_supplier_on_approval/_reminder → BCC, legacy kind).
 * Klíč `approvals` platí pro žádost i schvalovací upomínku; cfg fallback je
 * rozlišuje přes $isApprovalReminder.
 *
 * Dedup napříč to/cc/bcc s prioritou to > cc > bcc, stabilní pořadí
 * (kontakty dle sort_order, e-maily zakázky dle position), validace
 * filter_var. `resolved` nese provenanci pro UI modal („kontakt: doklady" /
 * „zakázka" / „hlavní e-mail" / „kopie dodavateli").
 */
final class RecipientResolver
{
    public const TYPE_DOCUMENTS = 'documents';
    public const TYPE_REMINDERS = 'reminders';
    public const TYPE_APPROVALS = 'approvals';

    public const USAGES = ['communication', 'documents', 'reminders', 'approvals'];
    public const RECIPIENT_KINDS = ['to', 'cc', 'bcc'];

    /** Platné hodnoty supplier.self_copy[typ] (+ klíče = TYPE_* konstanty). */
    public const SELF_COPY_MODES = ['off', 'cc', 'bcc'];

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * @param array<string,mixed> $invoice řádek faktury (client_id, client_main_email, project_id, supplier_id)
     * @param bool $supplierCopy přidat kopii dodavateli dle supplier.self_copy/cfg
     *                           (false jen pro zprávy, které kopii historicky nemají — payment thanks)
     * @param bool $isApprovalReminder cfg fallback pro TYPE_APPROVALS: žádost vs. schvalovací upomínka
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>,
     *               resolved: list<array{email:string, recipient:string, source:string, usage:?string, label:?string}>}
     */
    public function resolve(string $type, array $invoice, bool $supplierCopy = true, bool $isApprovalReminder = false): array
    {
        if (!in_array($type, [self::TYPE_DOCUMENTS, self::TYPE_REMINDERS, self::TYPE_APPROVALS], true)) {
            throw new \InvalidArgumentException("Neznámý typ zprávy: {$type}");
        }

        $clientId  = (int) ($invoice['client_id'] ?? 0);
        $projectId = !empty($invoice['project_id']) ? (int) $invoice['project_id'] : null;
        $mainEmail = trim((string) ($invoice['client_main_email'] ?? ''));

        [$projectEmails, $mode] = $this->projectEmails($projectId, $type);

        // Kontakty klienta pro daný účel (upomínky: fallback na doklady).
        $entries = $this->contactEntries($clientId, $type);
        if ($entries === [] && $type === self::TYPE_REMINDERS) {
            $entries = $this->contactEntries($clientId, self::TYPE_DOCUMENTS);
        }

        $resolved = [];
        if ($entries !== []) {
            // Nový režim — kontakty jsou autoritativní pro daný účel.
            if ($mode === 'replace' && $projectEmails !== []) {
                foreach ($projectEmails as $pe) {
                    $resolved[] = ['email' => $pe['email'], 'recipient' => 'to', 'source' => 'project', 'usage' => null, 'label' => $pe['label']];
                }
            } else {
                foreach ($entries as $e) {
                    $resolved[] = $e;
                }
                foreach ($projectEmails as $pe) {
                    $resolved[] = ['email' => $pe['email'], 'recipient' => 'to', 'source' => 'project', 'usage' => null, 'label' => $pe['label']];
                }
            }
        } else {
            // Legacy větev — bez kontaktů se chová PŘESNĚ jako před #86.
            $useReplace = ($mode === 'replace')
                || ($mode === 'auto' && $type === self::TYPE_APPROVALS);
            if ($useReplace && $projectEmails !== []) {
                foreach ($projectEmails as $pe) {
                    $resolved[] = ['email' => $pe['email'], 'recipient' => 'to', 'source' => 'project', 'usage' => null, 'label' => $pe['label']];
                }
            } else {
                if ($mainEmail !== '') {
                    $resolved[] = ['email' => $mainEmail, 'recipient' => 'to', 'source' => 'main_email', 'usage' => null, 'label' => null];
                }
                foreach ($projectEmails as $pe) {
                    $resolved[] = ['email' => $pe['email'], 'recipient' => 'to', 'source' => 'project', 'usage' => null, 'label' => $pe['label']];
                }
            }
        }

        // Kopie dodavateli (CC/BCC dle supplier.self_copy, fallback cfg) — projde
        // společným dedupem: e-mail už přítomný v TO si nejsilnější roli ponechá.
        if ($supplierCopy) {
            $copy = $this->supplierCopy((int) ($invoice['supplier_id'] ?? 0), $type, $isApprovalReminder);
            if ($copy !== null) {
                $resolved[] = ['email' => $copy['email'], 'recipient' => $copy['recipient'], 'source' => 'supplier', 'usage' => null, 'label' => null];
            }
        }

        $out = $this->buckets($resolved);

        // Pojistka: žádný TO příjemce (např. kontakty jen s rolí cc/bcc — „kopie
        // účtárně, hlavní příjemce zůstává jednatel") → doplň main_email jako TO.
        // Jen pokud už nefiguruje v cc/bcc (tam ho uživatel zařadil vědomě).
        if ($out['to'] === [] && $mainEmail !== '' && filter_var($mainEmail, FILTER_VALIDATE_EMAIL)) {
            $key = mb_strtolower($mainEmail);
            $present = array_map(static fn (array $r) => mb_strtolower($r['email']), $out['resolved']);
            if (!in_array($key, $present, true)) {
                $entry = ['email' => $mainEmail, 'recipient' => 'to', 'source' => 'main_email', 'usage' => null, 'label' => null];
                $out['to'][] = $mainEmail;
                array_unshift($out['resolved'], $entry);
            }
        }
        return $out;
    }

    /**
     * Ruční úprava příjemců z modalu nad výsledkem resolve() (#60). Explicitní `to`
     * je autoritativní: modal předvyplnil i cc/bcc včetně kopie dodavateli, takže
     * co přišlo, je celý seznam, který uživatel viděl a schválil. Bez `to` se ručně
     * přidané cc/bcc připojí k vyřešeným. Stejnou sémantiku má SendEmailAction.
     *
     * @param array{to: list<string>, cc: list<string>, bcc: list<string>, resolved: list<array<string,mixed>>} $resolved
     * @param list<string>|null $to
     * @param list<string> $cc
     * @param list<string> $bcc
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>, resolved: list<array<string,mixed>>}
     */
    public static function applyOverrides(array $resolved, ?array $to, array $cc, array $bcc): array
    {
        $clean = static fn (array $list): array => array_values(array_unique(array_filter(
            array_map(static fn ($e) => trim((string) $e), $list),
            static fn (string $e) => $e !== '',
        )));

        if ($to !== null) {
            return ['to' => $clean($to), 'cc' => $clean($cc), 'bcc' => $clean($bcc), 'resolved' => []];
        }
        return [
            'to'       => $resolved['to'],
            'cc'       => $clean([...$resolved['cc'], ...$cc]),
            'bcc'      => $clean([...$resolved['bcc'], ...$bcc]),
            'resolved' => $resolved['resolved'],
        ];
    }

    /**
     * Kopie odchozí zprávy na e-mail dodavatele (audit vlastní pošty).
     *
     * Priorita: supplier.self_copy[$type] ('off'|'cc'|'bcc') > cfg flag (legacy
     * kind: documents/reminders → CC, approvals → BCC). Vrací null = neposílat
     * (vypnuto, supplier bez platného e-mailu).
     *
     * Public i pro cesty mimo resolve() — SendEmailAction s uživatelsky
     * editovanými příjemci resolver nevolá.
     *
     * @return array{email: string, recipient: 'cc'|'bcc'}|null
     */
    public function supplierCopy(int $supplierId, string $type, bool $isApprovalReminder = false): ?array
    {
        if ($supplierId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT email, self_copy FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $email = trim((string) $row['email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $mode = null;
        $selfCopy = $row['self_copy'] !== null ? json_decode((string) $row['self_copy'], true) : null;
        if (is_array($selfCopy)
            && isset($selfCopy[$type])
            && in_array($selfCopy[$type], self::SELF_COPY_MODES, true)
        ) {
            $mode = (string) $selfCopy[$type];
        }
        if ($mode === null) {
            // Cfg fallback — bool flagy z dob před per-supplier nastavením; kind
            // zachovává jejich historické chování (send/reminder=CC, approval=BCC).
            $enabled = match ($type) {
                self::TYPE_DOCUMENTS => (bool) $this->config->get('smtp.cc_supplier_on_send', false),
                self::TYPE_REMINDERS => (bool) $this->config->get('smtp.cc_supplier_on_reminder', false),
                self::TYPE_APPROVALS => $isApprovalReminder
                    ? (bool) $this->config->get('approval.cc_supplier_on_approval_reminder', true)
                    : (bool) $this->config->get('approval.cc_supplier_on_approval', true),
                default => false,
            };
            $mode = $enabled ? ($type === self::TYPE_APPROVALS ? 'bcc' : 'cc') : 'off';
        }
        return $mode === 'off' ? null : ['email' => $email, 'recipient' => $mode];
    }

    /**
     * Záznamy kontaktů klienta pro daný účel — jeden entry per (kontakt, účel).
     *
     * @return list<array{email:string, recipient:string, source:string, usage:?string, label:?string}>
     */
    private function contactEntries(int $clientId, string $usage): array
    {
        if ($clientId <= 0) {
            return [];
        }
        $entries = [];
        foreach ($this->activeContacts($clientId) as $contact) {
            foreach ($contact['usages'] as $u) {
                if (($u['usage'] ?? '') !== $usage) {
                    continue;
                }
                $recipient = in_array($u['recipient'] ?? 'to', self::RECIPIENT_KINDS, true)
                    ? (string) $u['recipient'] : 'to';
                $entries[] = [
                    'email'     => $contact['email'],
                    'recipient' => $recipient,
                    'source'    => 'contact',
                    'usage'     => $usage,
                    'label'     => $contact['label'],
                ];
            }
        }
        return $entries;
    }

    /**
     * @return list<array{email:string, label:?string, usages:list<array{usage:string, recipient:string}>}>
     */
    private function activeContacts(int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT email, label, usages FROM client_email_contacts
              WHERE client_id = ? AND is_active = 1
              ORDER BY sort_order, id'
        );
        $stmt->execute([$clientId]);
        $contacts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usages = json_decode((string) $row['usages'], true);
            $contacts[] = [
                'email'  => trim((string) $row['email']),
                'label'  => $row['label'] !== null && $row['label'] !== '' ? (string) $row['label'] : null,
                'usages' => is_array($usages) ? $usages : [],
            ];
        }
        return $contacts;
    }

    /**
     * E-maily zakázky relevantní pro daný typ zprávy. Sloupec `usages` (JSON
     * pole stringů) omezuje e-mail na typy zpráv; NULL/prázdné = všechny typy
     * (default, zpětná kompatibilita existujících řádků).
     *
     * @return array{0: list<array{email:string, label:?string}>, 1: string} [e-maily zakázky, mode]
     */
    private function projectEmails(?int $projectId, string $type): array
    {
        if ($projectId === null) {
            return [[], 'auto'];
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT billing_emails_mode FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $mode = (string) ($stmt->fetchColumn() ?: 'auto');
        if (!in_array($mode, ['auto', 'append', 'replace'], true)) {
            $mode = 'auto';
        }

        $stmt = $pdo->prepare(
            'SELECT email, label, usages FROM project_billing_emails WHERE project_id = ? ORDER BY position'
        );
        $stmt->execute([$projectId]);
        $emails = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $em = trim((string) $row['email']);
            if ($em === '') {
                continue;
            }
            $usages = $row['usages'] !== null ? json_decode((string) $row['usages'], true) : null;
            if (is_array($usages) && $usages !== [] && !in_array($type, $usages, true)) {
                continue; // e-mail omezen na jiné typy zpráv
            }
            $emails[] = ['email' => $em, 'label' => $row['label'] !== null && $row['label'] !== '' ? (string) $row['label'] : null];
        }
        return [$emails, $mode];
    }

    /**
     * Rozdělí resolved entries do to/cc/bcc: validace, dedup s prioritou
     * to > cc > bcc (duplicitní e-mail si nechá nejsilnější roli; v rámci
     * stejné role vyhrává první výskyt — stabilní pořadí).
     *
     * @param list<array{email:string, recipient:string, source:string, usage:?string, label:?string}> $resolved
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>,
     *               resolved: list<array{email:string, recipient:string, source:string, usage:?string, label:?string}>}
     */
    private function buckets(array $resolved): array
    {
        $priority = ['to' => 0, 'cc' => 1, 'bcc' => 2];

        // 1. validace + nejsilnější role per e-mail (case-insensitive klíč)
        $best = [];
        foreach ($resolved as $entry) {
            $em = trim($entry['email']);
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $key = mb_strtolower($em);
            // První výskyt v nejsilnější roli vyhrává (pořadí entries je stabilní).
            if (!isset($best[$key]) || $priority[$entry['recipient']] < $priority[$best[$key]['recipient']]) {
                $entry['email'] = $em;
                $best[$key] = $entry;
            }
        }

        // 2. buckety ve stabilním pořadí podle prvního výskytu v $resolved
        $out = ['to' => [], 'cc' => [], 'bcc' => [], 'resolved' => []];
        $seen = [];
        foreach ($resolved as $entry) {
            $key = mb_strtolower(trim($entry['email']));
            if ($key === '' || !isset($best[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $final = $best[$key];
            $out[$final['recipient']][] = $final['email'];
            $out['resolved'][] = $final;
        }
        return $out;
    }
}
