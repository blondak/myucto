<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Odůvodnění výjimky u mzdové validace — a jeho minimum.
 *
 * ─── PROČ VŮBEC NĚJAKÉ MINIMUM ──────────────────────────────────────────────
 *
 * Schválená výjimka je jediná stopa, která po rozhodnutí zůstane. Za rok, při
 * kontrole nebo při reklamaci zaměstnance, se z ní musí dát přečíst, PROČ se
 * mzda vyplatila navzdory nálezu. „ok", „souhlasím", „—" nebo prázdno tuhle
 * roli nesplní; naopak vypadají jako doložené rozhodnutí, aniž by čímkoli byly.
 * Bezobsažné odůvodnění je proto horší než žádné — dělá z prázdna doklad.
 *
 * ─── ZVOLENÉ MINIMUM A PROČ PRÁVĚ TAKOVÉ ────────────────────────────────────
 *
 *  1. **20 znaků.** Skutečná věta („Zaměstnanec byl celý měsíc na neplaceném
 *     volnu.") má přes čtyřicet; odbyté odpovědi („ok", „schvaluji", „netýká
 *     se") se do dvaceti vejdou všechny. Hranice je nízko dost, aby nikoho
 *     neotravovala, a vysoko dost, aby ji nešlo trefit omylem.
 *  2. **Tři slova.** Jedno slovo není důvod, je to nálepka. Věta má podmět
 *     a přísudek, tedy nejméně dvě až tři slova — na tomhle se láme rozdíl
 *     mezi „schváleno" a „zaměstnanec nastoupil až v půli měsíce".
 *  3. **Čtyři různá písmena.** Bez toho projde „aaaaaaaaaaaaaaaaaaaaaa"
 *     i „....................". Délku i počet slov jde vyplnit výplní; tohle
 *     je nejlevnější test na to, že jde o text, ne o obcházení formuláře.
 *  4. **500 znaků** je strop sloupce `override_reason` v migraci 1210. Delší
 *     text by databáze uřízla potichu uprostřed slova, což je u dokladu horší
 *     než odmítnout ho rovnou.
 *
 * Obsahovou správnost tímhle nikdo neověří a ani se o to nesnažíme — to je
 * odpovědnost člověka, který výjimku podepisuje. Cílem je vyloučit odpovědi,
 * u kterých je na první pohled jisté, že o rozhodnutí neříkají nic.
 */
final class PayrollRunOverrideReason
{
    public const MIN_LENGTH = 20;

    public const MAX_LENGTH = 500;

    public const MIN_WORDS = 3;

    public const MIN_DISTINCT_LETTERS = 4;

    /**
     * Normalizuje a ověří odůvodnění výjimky.
     *
     * @throws \InvalidArgumentException když odůvodnění nesplní minimum
     */
    public static function normalize(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw new \InvalidArgumentException(
                'Důvod výjimky je povinný — bez odůvodnění nelze varování odklidit.',
            );
        }
        // Sjednocení bílých znaků: „ok\n\n\n\n\n\n\n\n\n\n\n" jinak projde na délku.
        $reason = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($reason === '') {
            throw new \InvalidArgumentException(
                'Důvod výjimky je povinný — bez odůvodnění nelze varování odklidit.',
            );
        }
        if (mb_strlen($reason) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Důvod výjimky smí mít nejvýš %d znaků.',
                self::MAX_LENGTH,
            ));
        }
        if (mb_strlen($reason) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Důvod výjimky musí mít alespoň %d znaků — z „ok" se za rok nikdo '
                    . 'nedozví, proč se mzda vyplatila.',
                self::MIN_LENGTH,
            ));
        }
        if (count(explode(' ', $reason)) < self::MIN_WORDS) {
            throw new \InvalidArgumentException(sprintf(
                'Důvod výjimky musí být věta o nejméně %d slovech, ne jedno slovo.',
                self::MIN_WORDS,
            ));
        }
        if (self::distinctLetters($reason) < self::MIN_DISTINCT_LETTERS) {
            throw new \InvalidArgumentException(
                'Důvod výjimky musí být čitelná věta, ne výplň znaků.',
            );
        }

        return $reason;
    }

    /**
     * Volitelné odůvodnění (odvolání výjimky) — když se vyplní, platí tatáž
     * pravidla; když se nevyplní, nic se nevynucuje.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeOptional(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw) && trim($raw) === '') {
            return null;
        }

        return self::normalize($raw);
    }

    private static function distinctLetters(string $reason): int
    {
        $letters = preg_replace('/[^\p{L}]/u', '', mb_strtolower($reason)) ?? '';
        $characters = preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY);

        return $characters === false ? 0 : count(array_unique($characters));
    }
}
