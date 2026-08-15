<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Odvození ROZHODNÉHO DNE DORUČENÍ zprávy dodané do datové schránky.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co říká zákon
 * ═══════════════════════════════════════════════════════════════════════════
 * § 17 zák. 300/2008 Sb., o elektronických úkonech a autorizované konverzi
 * dokumentů — „Doručování dokumentů orgánů veřejné moci prostřednictvím datové
 * schránky":
 *
 *   odst. 3 — dokument dodaný do schránky je doručen okamžikem, kdy se do ní
 *             přihlásí osoba s přístupem k němu,
 *   odst. 4 — nepřihlásí-li se taková osoba **ve lhůtě 10 dnů ode dne, kdy byl
 *             dokument dodán**, považuje se za doručený **posledním dnem té
 *             lhůty**; to neplatí, vylučuje-li jiný právní předpis náhradní
 *             doručení,
 *   odst. 6 — doručení podle odst. 3 i 4 má účinky doručení do vlastních rukou.
 *
 * Počítání: lhůta stanovená podle dnů počíná běžet dnem NÁSLEDUJÍCÍM po dni,
 * kdy došlo ke skutečnosti určující počátek jejího běhu (§ 33 odst. 2 DŘ).
 * Dodání dne D tedy dává lhůtu D+1 … D+10 a fikci na D+10. Připadne-li poslední
 * den na sobotu, neděli nebo svátek, je posledním dnem nejblíže následující
 * pracovní den (§ 33 odst. 4 DŘ) — a právě proto tenhle výpočet sahá na
 * {@see CzechWorkingDays}, jediný zdroj svátků v aplikaci.
 *
 * Zákonný desátý den se ukládá i před posunem ({@see ResolvedDelivery::$fictionStatutoryOn}):
 * posun je výklad, dodání je fakt, a auditor musí vidět obojí.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ Fikce nemá univerzální platnost — a tady je nejsnazší se seknout
 * ═══════════════════════════════════════════════════════════════════════════
 * § 17 upravuje doručování ORGÁNŮ VEŘEJNÉ MOCI. Zpráva od soukromé osoby jde
 * podle § 18a (poštovní datová zpráva) a ten v odstavci 2 zná JEN doručení
 * přihlášením — **žádnou fikci**. Kdyby se fikce uplatnila na poštovní datovou
 * zprávu, aplikace by vyrobila den doručení, který právně nenastal, a od něj
 * pak počítala lhůty.
 *
 * Proto se fikce spouští jedině při `$senderIsPublicAuthority === true`.
 * `null` (odesílatel není v číselníku, tedy nevíme) i `false` vedou na
 * {@see DeliveryBasis::Unknown} — poctivé „nevíme", ne tichý předpoklad.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Prázdno není odpověď
 * ═══════════════════════════════════════════════════════════════════════════
 * Chybějící čas dodání nevede na „doručeno dnes" ani na „nedoručeno". Vede na
 * `Unknown` s vysvětlením. Stejně tak běžící lhůta je {@see DeliveryBasis::Pending},
 * ne `Unknown` — mezi „ještě ne" a „nevíme" je rozdíl a UI ho ukazuje.
 */
final readonly class DeliveryFictionCalculator
{
    /**
     * @param ?\DateTimeImmutable $deliveredAt dmDeliveryTime — DODÁNÍ do schránky
     * @param ?\DateTimeImmutable $acceptedAt dmAcceptanceTime — DORUČENÍ podle ISDS
     *        (přihlášením i fikcí). Pozor na jméno: s přijetím podání úřadem
     *        nemá nic společného.
     * @param ?bool $senderIsPublicAuthority null = nevíme
     * @param string $fictionDaysSource `ruleset` nebo `statute`
     */
    public function resolve(
        ?\DateTimeImmutable $deliveredAt,
        ?\DateTimeImmutable $acceptedAt,
        ?bool $senderIsPublicAuthority,
        int $fictionDays,
        string $fictionDaysSource,
        \DateTimeImmutable $today,
    ): ResolvedDelivery {
        if ($fictionDays < 1) {
            throw new \InvalidArgumentException('Lhůta fikce doručení musí být aspoň jeden den.');
        }

        $today = self::day($today);
        $acceptedOn = $acceptedAt !== null ? self::day($acceptedAt) : null;

        // ── Bez času dodání neumíme spočítat vůbec nic o fikci ──
        if ($deliveredAt === null) {
            if ($acceptedOn !== null) {
                return $this->result(
                    DeliveryBasis::Login,
                    $acceptedOn,
                    null,
                    null,
                    null,
                    null,
                    $senderIsPublicAuthority,
                    'Doručeno přihlášením do schránky (§ 17 odst. 3 zák. 300/2008 Sb.). '
                    . 'Čas dodání zpráva nenese, lhůtu fikce proto neověřujeme.',
                );
            }

            return $this->result(
                DeliveryBasis::Unknown,
                null,
                null,
                null,
                null,
                null,
                $senderIsPublicAuthority,
                'Zpráva nenese čas dodání ani doručení. Rozhodný den doručení neznáme — '
                . 'doplňte ho ručně, jinak od něj nelze počítat žádnou lhůtu.',
            );
        }

        // ── Fikce se počítá jen tam, kde ji zákon zná ──
        if ($senderIsPublicAuthority !== true) {
            if ($acceptedOn !== null) {
                return $this->result(
                    DeliveryBasis::Login,
                    $acceptedOn,
                    null,
                    null,
                    null,
                    null,
                    $senderIsPublicAuthority,
                    'Doručeno přihlášením do schránky. Odesílatel není doložený jako orgán veřejné moci, '
                    . 'takže fikci doručení podle § 17 odst. 4 zák. 300/2008 Sb. neuplatňujeme.',
                );
            }

            return $this->result(
                DeliveryBasis::Unknown,
                null,
                null,
                null,
                null,
                null,
                $senderIsPublicAuthority,
                'Zpráva je dodaná, ale odesílatel není doložený jako orgán veřejné moci. '
                . 'Fikce doručení se na poštovní datovou zprávu (§ 18a zák. 300/2008 Sb.) nevztahuje, '
                . 'takže den doručení neurčujeme — potvrďte ho ručně.',
            );
        }

        $deliveredOnDay = self::day($deliveredAt);
        // § 33 odst. 2 DŘ: lhůta podle dnů běží ode dne následujícího po dodání,
        // poslední den je tedy dodání + délka lhůty.
        $statutory = $deliveredOnDay->modify('+' . $fictionDays . ' days');
        $due = CzechWorkingDays::shiftToWorkingDay($statutory);
        $shiftNote = $statutory->format('Y-m-d') !== $due->format('Y-m-d')
            ? ' Desátý den připadl na den pracovního klidu, konec lhůty se posunul na '
              . $due->format('j. n. Y') . ' (§ 33 odst. 4 daňového řádu).'
            : '';

        if ($acceptedOn !== null) {
            $basis = $acceptedOn->format('Y-m-d') === $due->format('Y-m-d')
                ? DeliveryBasis::LoginOrFiction
                : DeliveryBasis::Login;

            return $this->result(
                $basis,
                $acceptedOn,
                $statutory,
                $due,
                $fictionDays,
                $fictionDaysSource,
                true,
                $basis === DeliveryBasis::LoginOrFiction
                    ? 'Doručeno ' . $acceptedOn->format('j. n. Y') . '. Padlo to přesně na den, kdy by nastala '
                      . 'fikce doručení, takže z dat nepoznáme, jestli šlo o přihlášení podle § 17 odst. 3, '
                      . 'nebo o fikci podle § 17 odst. 4. Rozhodný den je u obou stejný.' . $shiftNote
                    : 'Doručeno přihlášením do schránky ' . $acceptedOn->format('j. n. Y')
                      . ' (§ 17 odst. 3 zák. 300/2008 Sb.), tedy dřív, než mohla nastat fikce.' . $shiftNote,
            );
        }

        if ($today > $due) {
            return $this->result(
                DeliveryBasis::Fiction,
                $due,
                $statutory,
                $due,
                $fictionDays,
                $fictionDaysSource,
                true,
                'Do schránky se nikdo nepřihlásil ve lhůtě ' . $fictionDays . ' dnů od dodání, '
                . 'zpráva se proto považuje za doručenou ' . $due->format('j. n. Y')
                . ' (§ 17 odst. 4 zák. 300/2008 Sb.).' . $shiftNote,
            );
        }

        return $this->result(
            DeliveryBasis::Pending,
            null,
            $statutory,
            $due,
            $fictionDays,
            $fictionDaysSource,
            true,
            'Zpráva je dodaná, doručená zatím není. Nepřihlásí-li se nikdo do schránky, '
            . 'bude se považovat za doručenou ' . $due->format('j. n. Y')
            . ' (§ 17 odst. 4 zák. 300/2008 Sb.).' . $shiftNote,
        );
    }

    private function result(
        DeliveryBasis $basis,
        ?\DateTimeImmutable $deliveredOn,
        ?\DateTimeImmutable $statutory,
        ?\DateTimeImmutable $due,
        ?int $fictionDays,
        ?string $fictionDaysSource,
        ?bool $senderIsPublicAuthority,
        string $note,
    ): ResolvedDelivery {
        return new ResolvedDelivery(
            $basis,
            $deliveredOn,
            $statutory,
            $due,
            $fictionDays,
            $fictionDaysSource,
            $senderIsPublicAuthority,
            $note,
        );
    }

    /** Půlnoc v Praze — lhůty běží po dnech, ne po hodinách. */
    private static function day(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value->setTimezone(new \DateTimeZone('Europe/Prague'))->setTime(0, 0);
    }
}
