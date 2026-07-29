<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Note;

use MyInvoice\Http\Json;
use MyInvoice\Repository\JournalEntryNoteRepository;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Sdílená validace těla poznámky. Body i pinned jsou volitelné u PATCH (částečná
 * editace) a body povinné u POST — proto oddělené metody místo jedné.
 */
trait JournalNoteActionSupport
{
    /**
     * Normalizuje a ověří text poznámky. Vrací null a naplní $err při chybě.
     */
    protected function validateBody(mixed $raw, Response $response, ?Response &$err): ?string
    {
        $err = null;
        if (!is_string($raw)) {
            $err = Json::error($response, 'validation_failed', 'Pole body musí být text.', 422);
            return null;
        }
        // Normalizace konců řádků + ořez okrajů; vnitřní formátování necháváme být.
        $body = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        if ($body === '') {
            $err = Json::error($response, 'validation_failed', 'Poznámka nesmí být prázdná.', 422);
            return null;
        }
        if (mb_strlen($body) > JournalEntryNoteRepository::MAX_BODY_LENGTH) {
            $err = Json::error(
                $response,
                'validation_failed',
                'Poznámka smí mít nejvýše ' . JournalEntryNoteRepository::MAX_BODY_LENGTH . ' znaků.',
                422
            );
            return null;
        }
        return $body;
    }

    /**
     * Volitelný boolean z těla požadavku. Vrací null když klíč chybí.
     * Akceptuje true/false, 1/0, "1"/"0" — FE posílá JSON bool, testy občas int.
     */
    protected function optionalBool(array $body, string $key): ?bool
    {
        if (!array_key_exists($key, $body)) {
            return null;
        }
        $v = $body[$key];
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes'], true);
        }
        return null;
    }
}
