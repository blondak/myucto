<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/**
 * Doručenka stažená z datové schránky, přečtená ze ZFO.
 *
 * ── Past, kvůli které tahle třída existuje ──────────────────────────────────
 * ISDS má dvě časová razítka a jejich jména svádějí k záměně:
 *
 *   `dmDeliveryTime`   — DODÁNÍ: zpráva dorazila do schránky příjemce.
 *                        Z našeho pohledu okamžik, kdy podání opustilo
 *                        odesílatele → mapuje se na `sent_at`.
 *   `dmAcceptanceTime` — DORUČENÍ: příjemce se přihlásil, nebo uplynula
 *                        desetidenní fikce (§ 17 odst. 4 zák. 300/2008 Sb.).
 *                        Jmenuje se „acceptance", ale s PŘIJETÍM podání úřadem
 *                        nemá nic společného → mapuje se na `delivered_at`,
 *                        nikdy na osu vyřízení.
 *
 * Proto tu nejsou syrová jména z ISDS, ale {@see sentAt()} a {@see deliveredAt()}:
 * pojmenování je tady jediná obrana, protože typ je u obou stejný.
 *
 * ⚠️ Podpis a časové razítko doručenky NEOVĚŘUJEME. {@see \MyInvoice\Service\Document\ZfoExtractor}
 * obsah jen rozbalí; CMS podpis nekontroluje. Doručenka je proto v tomhle
 * modelu **nahlášený**, ne ověřený důkaz a `receipt_signature_status` zůstává
 * `unverified`.
 */
final readonly class DeliveryReceipt
{
    /**
     * @param array<string,mixed> $metadata syrová metadata z {@see \MyInvoice\Service\Document\ZfoExtractor}
     */
    public function __construct(
        /** dmID — identifikátor datové zprávy, ke které doručenka patří. */
        public string $messageId,
        public ?string $senderBoxId,
        public ?string $senderName,
        public ?string $recipientBoxId,
        public ?string $recipientName,
        /** dmSenderIdent — naše spisová značka, pokud ji zpráva nese. */
        public ?string $senderIdent,
        /** dmAnnotation — věc zprávy. */
        public ?string $subject,
        /** dmDeliveryTime — dodání do schránky. */
        public ?\DateTimeImmutable $deliveryTime,
        /** dmAcceptanceTime — doručení (přihlášením nebo fikcí). NE přijetí úřadem. */
        public ?\DateTimeImmutable $acceptanceTime,
        public string $rawSha256,
        public array $metadata = [],
    ) {}

    /**
     * Kdy zpráva odešla. Dodání do schránky příjemce je nejbližší doložitelný
     * okamžik odeslání, který doručenka nese — vlastní čas odeslání v ní není.
     */
    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->deliveryTime;
    }

    /**
     * Kdy byla zpráva doručena. Fallback na dodání je záměrný: dokud se příjemce
     * nepřihlásil, doručenka nese jen dodání, a to je pořád doklad o tom, že
     * zpráva ve schránce úřadu je.
     */
    public function deliveredAt(): ?\DateTimeImmutable
    {
        return $this->acceptanceTime ?? $this->deliveryTime;
    }

    /** Nese doručenka naši spisovou značku? Jediná plně automatická vazba. */
    public function hasSenderIdent(): bool
    {
        return $this->senderIdent !== null && $this->senderIdent !== '';
    }
}
