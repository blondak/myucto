<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Payload pro Ollama `POST /api/chat` — čistá funkce bez I/O.
 *
 * Hybridní vstup: PNG stránky jdou do `images`, textová vrstva PDF se připojí
 * k uživatelské zprávě. Malé vision modely chybují v číslicích; text je dává
 * přesně, obrázek dodává rozložení tabulek.
 */
final class OllamaChatRequest
{
    /** Výchozí kontext Ollamy (4096) se na fakturu s obrázky nevejde. */
    public const NUM_CTX = 32768;
    public const MAX_TEXT_CHARS = 20000;
    /**
     * Jak dlouho Ollama drží model v paměti po requestu (výchozí 5 min). Načtení modelu
     * po pauze trvá na slabší GPU desítky sekund a ukrojí je z časového limitu.
     */
    public const KEEP_ALIVE = '30m';
    /** Klíče, které nastavuje jen tahle třída — `$extra` je nepřepíše ani nedoplní. */
    private const CORE_KEYS = ['model', 'stream', 'messages', 'options', 'format', 'keep_alive'];
    public const TEXT_HEADER = 'Textová vrstva PDF (čísla dokladu, IBAN, variabilní symbol a částky ber přednostně odsud; obrázek použij pro rozložení):';

    /**
     * @param list<string>                    $pngPages surové PNG bajty stránek
     * @param array<string,mixed>|string|null $format   JSON schéma, 'json', nebo null
     * @param array<string,mixed>             $extra    provider-nativní fragment (např. `think`)
     * @return array<string,mixed>
     */
    public static function build(
        string $model,
        string $system,
        string $user,
        ?string $pdfText,
        array $pngPages,
        array|string|null $format,
        array $extra = [],
        ?int $numPredict = null,
        int $numCtx = self::NUM_CTX,
    ): array {
        // Guzzle `json` hází na nevalidním UTF-8 (rozbitá/nepřátelská PDF) — vše čistíme tady.
        $system = mb_scrub($system, 'UTF-8');
        $content = mb_scrub($user, 'UTF-8');
        $text = trim(mb_scrub((string) $pdfText, 'UTF-8'));
        if ($text !== '') {
            $content .= "\n\n" . self::TEXT_HEADER . "\n" . mb_substr($text, 0, self::MAX_TEXT_CHARS);
        }
        $userMessage = ['role' => 'user', 'content' => $content];
        if ($pngPages !== []) {
            $userMessage['images'] = array_map(static fn (string $png): string => base64_encode($png), array_values($pngPages));
        }

        $options = ['num_ctx' => $numCtx, 'temperature' => 0];
        if ($numPredict !== null) {
            $options['num_predict'] = $numPredict;
        }

        $payload = [
            'model'    => $model,
            'stream'   => false,
            'messages' => [['role' => 'system', 'content' => $system], $userMessage],
            'options'    => $options,
            'keep_alive' => self::KEEP_ALIVE,
        ];
        if ($format !== null) {
            $payload['format'] = $format;
        }
        return $payload + array_diff_key($extra, array_flip(self::CORE_KEYS));
    }
}
