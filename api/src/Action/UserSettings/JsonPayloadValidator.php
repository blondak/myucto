<?php

declare(strict_types=1);

namespace MyInvoice\Action\UserSettings;

/**
 * Validace opaque JSON payloadu ukládaných filtrů a preferencí (§3.4).
 *
 * BE payload neinterpretuje — jen validuje tvar/velikost/hloubku a vrací kanonický
 * re-encoded tvar k uložení. Aplikace filtru probíhá výhradně na FE.
 */
final class JsonPayloadValidator
{
    public const MAX_BYTES = 16384;

    /**
     * Vrací error code (viz §3.4) nebo null při validním payloadu.
     *
     * $maxDepth: default 3 (R2 — ploché filtry/preference). Preferenční endpoint volá se 4
     * kvůli §10 nav.order (`{sections:[...], items:{sectionKey:[...]}}` je o úroveň hlubší);
     * PHP počítá kontejnerové úrovně o 1 výš, než je intuice.
     */
    public static function validate(string $raw, int $maxDepth = 3): ?string
    {
        if (strlen($raw) > self::MAX_BYTES) {
            return 'payload_too_large';
        }

        try {
            $decoded = json_decode($raw, true, $maxDepth, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $e->getCode() === JSON_ERROR_DEPTH ? 'payload_too_deep' : 'validation_failed';
        }

        // Prázdný objekt {} dekóduje na [] a array_is_list([]) je true — je ale validní.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return 'validation_failed';
        }

        return null;
    }

    /**
     * Kanonický re-encoded tvar k uložení. Volat AŽ po úspěšném validate().
     */
    public static function canonicalize(string $raw, int $maxDepth = 3): string
    {
        $decoded = json_decode($raw, true, $maxDepth, JSON_THROW_ON_ERROR);
        if ($decoded === []) {
            return '{}'; // zachovat objektovou sémantiku prázdného payloadu
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
