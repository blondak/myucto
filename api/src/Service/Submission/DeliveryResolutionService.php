<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\ActivityLogger;
use Psr\Clock\ClockInterface;

/**
 * Určí a ULOŽÍ rozhodný den doručení u příchozí zprávy.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se to ukládá, a ne počítá za běhu
 * ═══════════════════════════════════════════════════════════════════════════
 * Rozhodný den doručení je počátek běhu navazujících lhůt — u výzvy podle
 * § 74 DŘ, u odvolání, u žádosti podle § 17 odst. 5 zák. 300/2008 Sb. Kdyby se
 * dopočítával při každém čtení, měnil by se podle toho, KDY se člověk zeptá
 * (dnes „lhůta běží", zítra „doručeno fikcí") a zpětně by nešlo doložit, co
 * aplikace tvrdila v době, kdy se podle toho rozhodovalo. Uložený závěr se
 * navíc dá porovnat s tím, co tvrdí úřad.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Kdo je orgán veřejné moci — a proč se to nehádá
 * ═══════════════════════════════════════════════════════════════════════════
 * Fikci doručení zná jen § 17 (doručování orgánů veřejné moci). Poštovní datová
 * zpráva podle § 18a fikci nemá. Odesílatele proto ověřujeme proti číselníku
 * {@see SubmissionRecipientRepository} — a to jen podle ID schránky, které je
 * fakt, ne podle jména odesílatele, které je dojem.
 *
 * Když v číselníku není, vrací se `null` = **nevíme**, ne `false`. Aplikace
 * v takovém případě fikci neuplatní ({@see DeliveryFictionCalculator}), ale ani
 * netvrdí, že odesílatel orgánem veřejné moci není — na to nemá důkaz. Ten
 * rozdíl uvidí uživatel ve větě u zprávy a může odesílatele do číselníku
 * doplnit.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Přepočet není přepisování historie
 * ═══════════════════════════════════════════════════════════════════════════
 * {@see refresh()} projde zprávy ve stavu `pending`/`unknown` a přepočítá je.
 * Přepis závěru je správně: běžící lhůta se má proměnit ve fikci ve chvíli,
 * kdy uplyne, a dodatečně známý čas přihlášení má fikci vytlačit. Uzamčené
 * závěry, které se s fakty rozejdou, jsou nebezpečnější než měnitelné.
 * Dohledatelnost drží `delivery_resolved_at` a auditní stopa.
 */
final readonly class DeliveryResolutionService
{
    /** Druhy příjemců z číselníku, které doručují jako orgán veřejné moci. */
    private const PUBLIC_AUTHORITY_KINDS = ['tax_office', 'cssz', 'health_insurer'];

    public function __construct(
        private SubmissionInboxRepository $inbox,
        private SubmissionRecipientRepository $recipients,
        private DeliveryFictionCalculator $calculator,
        private SubmissionLegalRules $rules,
        private ActivityLogger $activity,
        private ClockInterface $clock,
    ) {}

    /** Umí databáze závěr o doručení vůbec uložit (migrace 1394)? */
    public function isSupported(): bool
    {
        return $this->inbox->supportsDeliveryResolution();
    }

    /**
     * Vyhodnotí jednu zprávu a zapíše závěr.
     *
     * @param array<string,mixed> $message řádek z `submission_inbox_messages`
     */
    public function resolveMessage(array $message, ?int $actorUserId = null): ResolvedDelivery
    {
        $supplierId = (int) $message['supplier_id'];
        $resolved = $this->evaluate($message);

        if (!$this->inbox->supportsDeliveryResolution()) {
            return $resolved;
        }

        $changed = ($message['delivery_basis'] ?? null) !== $resolved->basis->value
            || ($message['delivered_on'] ?? null) !== $resolved->deliveredOn?->format('Y-m-d');

        $this->inbox->saveDeliveryResolution($supplierId, (int) $message['id'], $resolved->toRow());

        // Doručení fikcí je právně významná událost, kterou nikdo neodklikl —
        // nastala tím, že nikdo nic neudělal. Musí být v auditní stopě.
        if ($changed && $resolved->basis === DeliveryBasis::Fiction) {
            $this->activity->log(
                'databox_delivery_fiction_applied',
                $actorUserId,
                'databox',
                (int) $message['id'],
                [
                    'legal_basis' => '§ 17 odst. 4 zák. 300/2008 Sb.',
                    'delivered_on' => $resolved->deliveredOn?->format('Y-m-d'),
                    'fiction_days' => $resolved->fictionDays,
                    'fiction_days_source' => $resolved->fictionDaysSource,
                ],
                null,
                null,
                $supplierId,
            );
        }

        return $resolved;
    }

    /**
     * Přepočítá zprávy, u kterých se závěr může změnit pouhým během času.
     *
     * @return array{checked:int,changed:int,delivered_by_fiction:int}
     */
    public function refresh(int $supplierId, string $environment, ?int $actorUserId = null): array
    {
        $result = ['checked' => 0, 'changed' => 0, 'delivered_by_fiction' => 0];
        if (!$this->inbox->supportsDeliveryResolution()) {
            return $result;
        }

        foreach ($this->inbox->listDeliveryPending($supplierId, $environment) as $message) {
            $result['checked']++;
            $before = (string) ($message['delivery_basis'] ?? DeliveryBasis::Unknown->value);
            $resolved = $this->resolveMessage($message, $actorUserId);
            if ($resolved->basis->value !== $before) {
                $result['changed']++;
            }
            if ($resolved->basis === DeliveryBasis::Fiction) {
                $result['delivered_by_fiction']++;
            }
        }

        return $result;
    }

    /**
     * Čistý výpočet bez zápisu — používá ho i UI náhled.
     *
     * @param array<string,mixed> $message
     */
    public function evaluate(array $message): ResolvedDelivery
    {
        // ⚠️ U doručenky se fikce NEPOČÍTÁ, i když nese tatáž razítka.
        // Doručenka popisuje NAŠE odeslané podání: `delivered_at` je dodání do
        // schránky ÚŘADU. § 17 přitom upravuje doručování orgánů veřejné moci
        // směrem k nám — opačný směr je úkon podle § 18 a lhůta je zachována už
        // podáním datové zprávy do schránky správce daně (§ 35 odst. 1 písm. d
        // daňového řádu). Fikce by tu vyrobila den, který nic neznamená.
        if ((string) ($message['classification'] ?? '') === InboxMessageClassifier::DELIVERY_RECEIPT) {
            return new ResolvedDelivery(
                DeliveryBasis::Unknown,
                null,
                null,
                null,
                null,
                null,
                null,
                'Doručenka k odeslanému podání. Fikce doručení podle § 17 odst. 4 zák. 300/2008 Sb. '
                . 'se týká zpráv, které úřad doručuje nám, ne našich podání úřadu.',
            );
        }

        $deliveredAt = self::time($message['delivered_at'] ?? null);
        $acceptedAt = self::time($message['accepted_at'] ?? null);
        $today = \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'));

        // Lhůta platná ke dni DODÁNÍ, ne k dnešku: kdyby se délka lhůty někdy
        // změnila, na starou zprávu se musí použít ta, která platila tehdy.
        $onDate = ($deliveredAt ?? $today)->format('Y-m-d');
        $days = $this->rules->fictionDays($onDate);

        return $this->calculator->resolve(
            $deliveredAt,
            $acceptedAt,
            $this->senderIsPublicAuthority((int) $message['supplier_id'], $message['sender_box_id'] ?? null),
            $days->value,
            $days->source,
            $today,
        );
    }

    /**
     * `true` jen s doloženým ID schránky v číselníku. Jinak `null` = nevíme.
     * Hodnota `false` se nevrací nikdy — na tvrzení „tohle není orgán veřejné
     * moci" aplikace důkaz nemá.
     */
    private function senderIsPublicAuthority(int $supplierId, mixed $senderBoxId): ?bool
    {
        if (!is_string($senderBoxId) || $senderBoxId === '') {
            return null;
        }
        $box = strtolower($senderBoxId);

        foreach ($this->recipients->listVisible($supplierId) as $recipient) {
            $candidate = $recipient['isds_box_id'] ?? null;
            if (!is_string($candidate) || strtolower($candidate) !== $box) {
                continue;
            }
            if (in_array((string) $recipient['kind'], self::PUBLIC_AUTHORITY_KINDS, true)) {
                return true;
            }
        }

        return null;
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Prague'));
        } catch (\Throwable) {
            return null;
        }
    }
}
