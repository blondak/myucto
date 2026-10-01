<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/**
 * Limity a kontroly souborů, které serverový MCP přenáší mezi Node a PHP.
 *
 * Soubor tam putuje jako base64 uvnitř JSON zpráv (JSON-RPC požadavek, zadání
 * mostu, interní požadavek na API, odpověď zpět), takže jedno PDF prochází
 * několika procesy. Všechny stropy proto vycházejí z jednoho místa: kdyby jeden
 * článek pustil víc než další, soubor by se ztratil uprostřed řetězu s obecnou
 * chybou místo srozumitelného „soubor je moc velký".
 *
 * `MAX_FILE_BYTES` a `MAX_ENVELOPE_BYTES` zrcadlí `HOSTED_MAX_FILE_BYTES`
 * a `HOSTED_MAX_ENVELOPE_BYTES` v `MCP/src/client.mjs`; shodu hlídá test.
 */
final class McpFileLimits
{
    /** Strop jednoho staženého nebo nahraného souboru. */
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;

    /** Strop těla požadavku: soubor + hlavičky multipartu a pole formuláře. */
    public const MAX_BODY_BYTES = self::MAX_FILE_BYTES + 64 * 1024;

    /** Strop JSON zprávy, která nese tělo v base64 (base64 = 4/3 velikosti + rezerva). */
    public const MAX_ENVELOPE_BYTES = 12 * 1024 * 1024;

    /** Strop jednoho řádku výstupu reléového mostu (zpráva + JSON obálka výsledku). */
    public const MAX_LINE_BYTES = self::MAX_ENVELOPE_BYTES + 1024 * 1024;

    /**
     * Typy souborů, které smí MCP nahrát. Sjednocení toho, co přijímají cílové
     * akce (přílohy faktur, PDF přijaté faktury, import ISDOC, Dokumenty, média
     * zboží). Akce si typ dál ověřují samy z obsahu; tohle je pojistka mostu,
     * aby přes něj neprošlo nic, co žádný nástroj neposílá (HTML, SVG, skripty).
     *
     * @var list<string>
     */
    public const ALLOWED_UPLOAD_TYPES = [
        'application/pdf',
        'application/xml',
        'text/xml',
        'application/zip',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/heic',
        'image/heif',
        'text/plain',
        'text/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
    ];

    private const BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /**
     * Ověří tvar a velikost base64 bez dekódování. Výjimka nese HTTP status
     * v kódu: 400 = neplatné base64, 413 = příliš velké.
     */
    public static function assertBase64(mixed $encoded, int $maxBytes = self::MAX_BODY_BYTES): void
    {
        if (!is_string($encoded) || $encoded === '') {
            throw new \InvalidArgumentException('Tělo požadavku není base64.', 400);
        }
        $length = strlen($encoded);
        if (intdiv($length, 4) * 3 > $maxBytes + 2) {
            throw new \InvalidArgumentException('Soubor je příliš velký pro přenos přes MCP.', 413);
        }
        $data = strspn($encoded, self::BASE64_ALPHABET);
        $padding = $length - $data;
        if ($length % 4 !== 0 || $padding > 2 || ($padding > 0 && strspn($encoded, '=', $data) !== $padding)) {
            throw new \InvalidArgumentException('Tělo požadavku není platné base64.', 400);
        }
    }

    public static function decodeBase64(mixed $encoded, int $maxBytes = self::MAX_BODY_BYTES): string
    {
        self::assertBase64($encoded, $maxBytes);
        $decoded = base64_decode((string) $encoded, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Tělo požadavku není platné base64.', 400);
        }
        if (strlen($decoded) > $maxBytes) {
            throw new \InvalidArgumentException('Soubor je příliš velký pro přenos přes MCP.', 413);
        }
        return $decoded;
    }

    /**
     * Název souboru bez cesty a bez znaků, které nepatří do názvu na disku ani
     * do hlavičky. Vrací null, když z názvu nic nezbude.
     */
    public static function sanitizeFilename(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        $name = substr($name, (int) strrpos('/' . $name, '/'));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>|*?:]/', '_', $name);
        $name = trim($name, ". _\t");
        if ($name === '' || mb_check_encoding($name, 'UTF-8') === false) {
            return null;
        }
        if (strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = mb_strcut(pathinfo($name, PATHINFO_FILENAME), 0, 190, 'UTF-8');
            $name = $base . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }
}
