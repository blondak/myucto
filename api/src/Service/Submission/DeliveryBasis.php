<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/**
 * Čím je doručení zprávy do datové schránky podložené.
 *
 * ── Proč to není jen „doručeno / nedoručeno" ────────────────────────────────
 * Zákon zná dvě cesty k doručení a rozdíl mezi nimi je právně významný:
 *
 *   § 17 odst. 3 zák. 300/2008 Sb. — „Dokument, který byl dodán do datové
 *   schránky, je doručen okamžikem, kdy se do datové schránky přihlásí osoba,
 *   která má s ohledem na rozsah svého oprávnění přístup k dodanému dokumentu."
 *
 *   § 17 odst. 4 — „Nepřihlásí-li se do datové schránky osoba podle odstavce 3
 *   ve lhůtě 10 dnů ode dne, kdy byl dokument dodán do datové schránky,
 *   považuje se tento dokument za doručený posledním dnem této lhůty; to
 *   neplatí, vylučuje-li jiný právní předpis náhradní doručení."
 *
 * Doručení fikcí lze podle § 17 odst. 5 napadnout žádostí o určení neúčinnosti
 * doručení; doručení přihlášením ne. Kdo ten rozdíl v evidenci nemá, nemůže
 * uživateli poradit, jestli má vůbec o co žádat.
 *
 * ── A proč tu jsou dvě hodnoty, které doručení netvrdí ──────────────────────
 * {@see self::Pending} a {@see self::Unknown} existují, aby prázdno nemuselo
 * lhát. Ani jedno neznamená „v pořádku":
 *   - `Pending` = zpráva je dodaná, desetidenní lhůta běží, doručeno ZATÍM není,
 *   - `Unknown` = nevíme, a proto se od toho nesmí odvíjet žádná lhůta.
 * Databáze to vynucuje: u obou musí být `delivered_on` NULL
 * (`chk_submission_inbox_delivery_basis`, migrace 1394).
 */
enum DeliveryBasis: string
{
    /** Doručeno přihlášením — § 17 odst. 3. */
    case Login = 'login';

    /** Doručeno fikcí — § 17 odst. 4, posledním dnem desetidenní lhůty. */
    case Fiction = 'fiction';

    /**
     * Doručení padlo přesně na den, kdy by nastala fikce. Které z toho platí,
     * z dat nepoznáme — ROZHODNÝ DEN je ale u obou týž, takže navazující lhůty
     * to neovlivní. Rozlišené proto, že u § 17 odst. 5 na tom záleží.
     */
    case LoginOrFiction = 'login_or_fiction';

    /** Dodáno, lhůta fikce běží. Doručeno není. */
    case Pending = 'pending';

    /** Nevíme. Ne „nedoručeno" a rozhodně ne „vyřízeno". */
    case Unknown = 'unknown';

    /** Nastalo doručení, ze kterého smí běžet navazující lhůta? */
    public function isDelivered(): bool
    {
        return match ($this) {
            self::Login, self::Fiction, self::LoginOrFiction => true,
            self::Pending, self::Unknown => false,
        };
    }
}
