<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Generický alias `note` v payloadu faktury (issue #38).
 *
 * OpenAPI (`InvoiceInput.note`) slibuje, že integrátor smí poslat jednu poznámku
 * pod klíčem `note` a systém ji uloží do `note_below_items`. Implementace ale
 * četla jen konkrétní klíče (`InvoiceRepository::createDraft/updateDraft`), takže
 * se `note` tiše zahazovalo — bez chyby i bez varování.
 *
 * Normalizace musí proběhnout PŘED zápisem do repository: `updateDraft` váže
 * `note_below_items = ?` z `$data['note_below_items'] ?? null` NEPODMÍNĚNĚ, tedy
 * dřív se alias nemá kam dostat a později už je poznámka přepsaná na NULL.
 *
 * Konkrétní klíč má vždycky přednost — když volající pošle `note_below_items`
 * (třeba i `null` jako explicitní vyprázdnění), `note` se zahodí. Jinak by dvě
 * pravdy v jednom těle tiše soupeřily o stejný sloupec.
 */
final class InvoiceNoteAlias
{
    /**
     * @param  array<string,mixed> $data  Syrové tělo požadavku
     * @return array<string,mixed>        Tělo bez klíče `note`, s doplněným `note_below_items`
     */
    public static function normalize(array $data): array
    {
        if (!array_key_exists('note', $data)) {
            return $data;
        }

        $note = $data['note'];
        unset($data['note']);

        // `note` není sloupec — nepouštěj ho dál ani když konkrétní klíč vyhrál.
        if (array_key_exists('note_below_items', $data)) {
            return $data;
        }

        // Skalár nebo null; pole/objekt pod tímhle klíčem je nesmysl a zahodíme ho
        // stejně tiše, jako by tam nebyl (validace poznámek tady žádná není).
        if ($note !== null && !is_scalar($note)) {
            return $data;
        }

        $data['note_below_items'] = $note === null ? null : (string) $note;

        return $data;
    }
}
