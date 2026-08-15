<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\ZfoExtractor;

/**
 * Přečte nahraný soubor jako doručenku z datové schránky.
 *
 * Rozbalení dělá {@see ZfoExtractor} — odladěný na skutečných ZFO, s vlastním
 * BER/DER parserem a zákazem DOCTYPE. Druhý parser tady nevzniká; tahle třída
 * jen vytáhne z obecných metadat to, co je potřeba ke spárování, a hlavně
 * **říká nahlas, co je se souborem špatně.**
 *
 * ── Proč tolik různých chyb ─────────────────────────────────────────────────
 * Uživatel bude nahrávat i nesprávné soubory: PDF výpis místo ZFO, zprávu
 * z cizí schránky, poškozený download. Kdyby všechny skončily jedním „nepodařilo
 * se", neměl by podle čeho jednat. Každý případ má proto vlastní kód a větu,
 * která říká, co udělat.
 */
final readonly class DeliveryReceiptReader
{
    /** Ochrana proti nahrání něčeho, co ZFO ani vzdáleně není. */
    private const MAX_BYTES = 100 * 1024 * 1024;

    public function __construct(private ZfoExtractor $zfo) {}

    /**
     * @throws DocumentException když soubor není čitelná datová zpráva
     */
    public function read(string $bytes): DeliveryReceipt
    {
        if ($bytes === '') {
            throw new DocumentException(
                'receipt_empty',
                'Nahraný soubor je prázdný. Stáhněte doručenku z datové schránky znovu.',
                422,
            );
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new DocumentException(
                'receipt_too_large',
                'Soubor je větší, než jakou zprávu datová schránka umí vydat.',
                413,
            );
        }
        if (!ZfoExtractor::looksLikeZfo($bytes)) {
            throw new DocumentException(
                'receipt_not_zfo',
                'Tohle není datová zpráva ve formátu ZFO. V detailu odeslané zprávy '
                . 'v datové schránce zvolte stažení doručenky (soubor .zfo), ne tisk do PDF.',
                422,
            );
        }

        $parsed = $this->zfo->extract($bytes); // DocumentException propadne s vlastním kódem

        /** @var array<string,mixed> $meta */
        $meta = $parsed['metadata'];

        $messageId = $this->str($meta['dm_id'] ?? null);
        if ($messageId === null) {
            throw new DocumentException(
                'receipt_missing_message_id',
                'V souboru není ID datové zprávy, takže ho nejde k ničemu přiřadit. '
                . 'Zkontrolujte, že jde o doručenku ke konkrétní zprávě, ne o obecný výpis.',
                422,
            );
        }

        return new DeliveryReceipt(
            messageId: $messageId,
            senderBoxId: $this->boxId($meta['sender_box_id'] ?? null),
            senderName: $this->str($meta['sender_name'] ?? null),
            recipientBoxId: $this->boxId($meta['recipient_box_id'] ?? null),
            recipientName: $this->str($meta['recipient_name'] ?? null),
            senderIdent: $this->str($meta['sender_ident'] ?? null),
            subject: $this->str($meta['annotation'] ?? null),
            deliveryTime: $this->time($meta['delivery_time'] ?? null),
            acceptanceTime: $this->time($meta['acceptance_time'] ?? null),
            rawSha256: hash('sha256', $bytes),
            metadata: $meta,
        );
    }

    private function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** ID schránky je 7 znaků [a-z0-9]; cokoliv jiného je překlep, ne schránka. */
    private function boxId(mixed $value): ?string
    {
        $value = $this->str($value);
        if ($value === null) {
            return null;
        }
        $value = strtolower($value);

        return preg_match('/^[a-z0-9]{7}$/', $value) === 1 ? $value : null;
    }

    private function time(mixed $value): ?\DateTimeImmutable
    {
        $value = $this->str($value);
        if ($value === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
