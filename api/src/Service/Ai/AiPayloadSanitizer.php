<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use PDO;

final class AiPayloadSanitizer
{
    public const MESSAGE_WHITELIST = [
        'faktura', 'faktury', 'uhrada', 'uhrady', 'zaloha', 'zalohy', 'najem', 'najemne', 'najemneho',
        'poplatek', 'poplatky', 'urok', 'uroky', 'mzda', 'mzdy', 'mzdu', 'vyplata', 'vyplaty', 'vyplati',
        'jednatel', 'jednatele', 'zamestnanec', 'zamestnance', 'odmena', 'odmeny',
        'pojisteni', 'pojistne', 'socialni', 'zdravotni', 'dan', 'dane', 'dph', 'silnicni',
        'splatka', 'splatky', 'prevod', 'prevody', 'vlastni', 'vlastniho', 'ucet', 'uctu',
        'bezny', 'bezneho', 'sporeni', 'sporici', 'sporiciho', 'terminovany',
        'vklad', 'vklady', 'vyber', 'vybery', 'hotovost', 'hotovosti', 'pokladna', 'pokladny', 'pokladnu',
        'karta', 'kartou', 'platebni', 'kapitalizace', 'penale', 'predplatne', 'licence',
        'software', 'hosting', 'energie', 'elektrina', 'plyn', 'voda', 'telefon', 'internet',
        'kancelar', 'kancelarske', 'material', 'zbozi', 'sluzba', 'sluzby', 'doprava',
        'servis', 'oprava', 'skoleni', 'cestovne', 'stravne', 'ubytovani', 'reprezentace',
        'reklama', 'marketing', 'poradenstvi', 'ucetnictvi', 'pravni', 'audit', 'leasing',
        'uver', 'pujcka', 'jistina', 'dobropis', 'vratka', 'refundace', 'trzba', 'prijem',
        'vydaj', 'nakup', 'prodej', 'odvod', 'cssz', 'ossz', 'socialka', 'pojistovna',
        'financni', 'urad', 'srazkova', 'zalohova', 'danova', 'exekuce', 'prispevek',
        'leden', 'unor', 'brezen', 'duben', 'kveten', 'cerven', 'cervenec', 'srpen',
        'zari', 'rijen', 'listopad', 'prosinec', 'q1', 'q2', 'q3', 'q4', 'mesic', 'rok',
        'prvni', 'druhy', 'treti', 'ctvrty', 'rocni', 'mesicni', 'ctvrtletni', 'pii',
    ];

    public function __construct(private readonly Connection $db) {}

    /** @return array{ok:true,data:array<string,mixed>,text:string}|array{ok:false,error:string} */
    public function sanitizeBankTx(int $supplierId, array $tx, ?string $userContext = null): array
    {
        $salt = $this->salt($supplierId);
        if ($salt === null) {
            return ['ok' => false, 'error' => 'salt_missing'];
        }
        $amount = round(abs((float) ($tx['amount'] ?? 0)), 2);
        $direction = (float) ($tx['amount'] ?? 0) >= 0 ? 'in' : 'out';
        $account = AccountNumberNormalizer::canonical((string) ($tx['counterparty_account'] ?? '')) ?? '';
        $name = BankMessageNormalizer::normalizeKeepDigits(self::stripPii((string) ($tx['counterparty_name'] ?? '')));
        $specific = preg_replace('/\D/', '', (string) ($tx['specific_symbol'] ?? '')) ?? '';
        $vs = preg_replace('/\D/', '', (string) ($tx['variable_symbol'] ?? '')) ?? '';
        $vsShape = $this->variableSymbolShape($vs);
        $description = $this->sanitizeMessage((string) ($tx['description'] ?? ''));
        // Popis z banky prochází whitelistem (může nést jméno protistrany), uživatelský kontext ne —
        // ten je vědomě psaný proto, aby dodal chybějící souvislost, a whitelist by ji zahodil.
        $context = $userContext === null ? '' : $this->sanitizeUserContext($userContext);
        $data = [
            'direction' => $direction,
            'amount' => $amount,
            'currency' => strtoupper((string) ($tx['currency'] ?? $tx['statement_currency'] ?? 'CZK')),
            'month' => substr((string) ($tx['posted_at'] ?? ''), 0, 7),
            'counterparty_bank' => substr(preg_replace('/\D/', '', (string) ($tx['counterparty_bank'] ?? '')) ?? '', -4),
            'account_token' => $account === '' ? null : $this->token($salt, 'acct_', $account),
            'name_token' => $name === '' ? null : $this->token($salt, 'name_', $name),
            'specific_symbol_token' => $specific === '' ? null : $this->token($salt, 'ss_', $specific),
            'variable_symbol_shape' => $vsShape,
            'variable_symbol_token' => $vs === '' || $vsShape === 'rc_like' ? null : $this->token($salt, 'vs_', $vs),
            'constant_symbol' => substr(preg_replace('/\D/', '', (string) ($tx['constant_symbol'] ?? '')) ?? '', 0, 4),
            'message' => $description,
            'user_context' => $context,
        ];
        $parts = [
            'dir=' . $data['direction'],
            'amt=' . number_format($amount, 2, '.', ''),
            $data['currency'],
            'month=' . ($data['month'] ?: 'none'),
            'bank=' . ($data['counterparty_bank'] ?: 'none'),
            'acct=' . ($data['account_token'] ?? 'none'),
            'vs=' . $data['variable_symbol_shape'],
            'ks=' . ($data['constant_symbol'] ?: 'none'),
            'msg=' . ($description ?: 'none'),
        ];
        if ($context !== '') {
            $parts[] = 'context=' . $context;
        }
        return ['ok' => true, 'data' => $data, 'text' => implode(' ', $parts)];
    }

    /**
     * `$userContext` = doplňující dotaz účetní („je to nájem serverovny"). Prochází
     * stejnou cestou jako u bankovní transakce: popis z dokladu se filtruje whitelistem,
     * uživatelský kontext ne — ten je vědomě psaný proto, aby dodal chybějící souvislost,
     * a whitelist by ji zahodil.
     *
     * @return array{ok:true,data:array<string,mixed>,text:string}|array{ok:false,error:string}
     */
    public function sanitizePurchaseInvoice(int $supplierId, array $invoice, ?string $userContext = null): array
    {
        $salt = $this->salt($supplierId);
        if ($salt === null) {
            return ['ok' => false, 'error' => 'salt_missing'];
        }
        $vendor = BankMessageNormalizer::normalizeKeepDigits(self::stripPii((string) ($invoice['vendor_name'] ?? $invoice['supplier_name'] ?? '')));
        $description = $this->sanitizeMessage(implode(' ', array_filter([
            (string) ($invoice['description'] ?? ''),
            (string) ($invoice['note'] ?? ''),
        ])));
        $amount = round(abs((float) ($invoice['total_with_vat'] ?? $invoice['amount'] ?? 0)), 2);
        $context = $userContext === null ? '' : $this->sanitizeUserContext($userContext);
        $data = [
            'amount' => $amount,
            'currency' => strtoupper((string) ($invoice['currency'] ?? 'CZK')),
            'month' => substr((string) ($invoice['tax_date'] ?? $invoice['issue_date'] ?? ''), 0, 7),
            'vendor_token' => $vendor === '' ? null : $this->token($salt, 'name_', $vendor),
            'message' => $description,
            'user_context' => $context,
        ];
        $text = sprintf(
            'type=purchase amt=%.2f %s month=%s vendor=%s msg=%s',
            $amount,
            $data['currency'],
            $data['month'] ?: 'none',
            $data['vendor_token'] ?? 'none',
            $description ?: 'none',
        );
        if ($context !== '') {
            $text .= ' context=' . $context;
        }

        return ['ok' => true, 'data' => $data, 'text' => $text];
    }

    public function hmacToken(int $supplierId, string $prefix, string $value): string
    {
        $salt = $this->salt($supplierId);
        if ($salt === null) {
            throw new \RuntimeException('salt_missing');
        }
        return $this->token($salt, $prefix, $value);
    }

    /**
     * Volný text, kterým uživatel VĚDOMĚ dodává souvislost, jež z transakce není zřejmá.
     *
     * NEPOUŽÍVÁ whitelist slov jako sanitizeMessage(): ten je určený pro strojový popis z banky,
     * kde hrozí únik jména protistrany, a proto propustí jen známá slova. Na uživatelský text
     * aplikovaný whitelist ale zahodí právě tu informaci, kvůli které pole existuje — z věty
     * „jde o platbu kartou za pojištění mobilního telefonu" zbylo „kartou pojisteni", takže
     * model neměl jak poznat, že jde o pojištění majetku (548), a sáhl po pojistném účtu 525.
     *
     * Ochranou zůstává strip PII (e-mail, telefon, IBAN) + strop délky; UI uživatele u pole
     * explicitně varuje, ať osobní údaje nevkládá.
     */
    public function sanitizeUserContext(string $context): string
    {
        $clean = preg_replace('/\s+/u', ' ', self::stripPii($context)) ?? '';
        return mb_substr(trim($clean), 0, 300);
    }

    /**
     * Volný text z DOKLADU (popis položky, zdůvodnění vrácené modelem) — táž ochrana jako
     * {@see sanitizeUserContext()}, jen s vlastním stropem délky.
     *
     * Platí tu TENTÝŽ zákaz whitelistu, a to ještě naléhavěji: popis položky píše dodavatel,
     * takže je to volný text plný značek a typů („Toner HP 26X černý", „Kávovar DeLonghi
     * Magnifica S"). MESSAGE_WHITELIST zná jen účetní slovník, takže by z popisu zbylo
     * „toner" — a přesně ta zahozená slova rozhodují, jestli jde o materiál, nebo drobný
     * majetek. Sanitizace se nesmí plést s klasifikací.
     *
     * Statická schválně: nesahá do DB (žádný pseudonymizační salt), takže ji smí volat
     * i extraktor, který AiPayloadSanitizer injektovaný nemá.
     */
    public static function sanitizeItemText(string $text, int $maxLength = 200): string
    {
        $clean = preg_replace('/\s+/u', ' ', self::stripPii($text)) ?? '';
        return mb_substr(trim($clean), 0, max(1, $maxLength));
    }

    private function sanitizeMessage(string $message): string
    {
        $normalized = BankMessageNormalizer::normalizeKeepDigits(self::stripPii($message));
        if ($normalized === '') {
            return '';
        }
        $kept = [];
        $dropped = 0;
        foreach (preg_split('/\s+/', $normalized) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            if (ctype_digit($word)) {
                $kept[] = '<num>';
            } elseif (in_array($word, self::MESSAGE_WHITELIST, true)) {
                $kept[] = $word === 'pii' ? '<pii>' : $word;
            } else {
                $dropped++;
            }
        }
        if ($dropped > 0) {
            $kept[] = '<w×' . $dropped . '>';
        }
        return implode(' ', $kept);
    }

    private static function stripPii(string $value): string
    {
        $value = preg_replace('/\S+@\S+/u', ' pii ', $value) ?? '';
        $value = preg_replace('/\+?\d[\d\s().-]{8,}\d/u', ' pii ', $value) ?? '';
        return preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9\s]{8,34}\b/ui', ' pii ', $value) ?? '';
    }

    private function variableSymbolShape(string $vs): string
    {
        if ($vs === '') {
            return 'none';
        }
        if (strlen($vs) >= 9 && strlen($vs) <= 10) {
            $month = (int) substr($vs, 2, 2);
            if (($month >= 1 && $month <= 12) || ($month >= 51 && $month <= 62)) {
                return 'rc_like';
            }
        }
        if (strlen($vs) === 8) {
            return 'ico_like';
        }
        if (strlen($vs) >= 6 && strlen($vs) <= 10 && preg_match('/^(20|24|25)/', $vs) === 1) {
            return 'invoice_like';
        }
        return 'other';
    }

    private function token(string $salt, string $prefix, string $value): string
    {
        return $prefix . substr(hash_hmac('sha256', $value, $salt), 0, 12);
    }

    private function salt(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ai_pseudo_salt FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $salt = $stmt->fetchColumn();
        return is_string($salt) && strlen($salt) === 32 ? $salt : null;
    }
}
