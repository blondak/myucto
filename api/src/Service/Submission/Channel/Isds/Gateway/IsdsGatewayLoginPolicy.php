<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

/**
 * Čím se uživatel přihlásí na přihlašovací stránce odesílací brány.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ TOHLE JE POJMENOVANÁ NEJISTOTA, NE NASTAVENÍ CHOVÁNÍ ⚠️
 * ═══════════════════════════════════════════════════════════════════════════
 * Kód se podle téhle hodnoty NECHOVÁ jinak. Přihlášení probíhá celé v perimetru
 * ISDS ({@see \MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistration::loginUrl()}
 * jen přesměruje) a my do něj nemáme jak zasáhnout. Hodnota určuje jediné: co
 * uživateli napíšeme, než ho tam pošleme.
 *
 * ── Proč to vůbec musí být konfigurovatelné ─────────────────────────────────
 * Primární pramen si protiřečí a rozhodnout to jde jedině pokusem proti
 * zaregistrované bráně. Citace jsou z `odesilaci_brana_ISDS.pdf` v. 1.11
 * (aktualizováno 24. 6. 2026, Technická příloha 2 Provozního řádu ISDS ve verzi
 * k 26. 6. 2026) a z Provozního řádu samotného:
 *
 * PRO „heslo je povinné" — táž kapitola 2.2:
 *   „Jedná se o následující přístupové údaje, které bude uživatel zadávat:
 *    • uživatelské jméno (povinný údaj)
 *    • heslo (povinný údaj)
 *    • komerční certifikát nebo OTP nebo SMS (volitelně)"
 *
 * PROTI — táž kapitola 2.2, o dva odstavce dál:
 *   „Ověření má stejné metody a úroveň ověření uživatele přistupujícího do
 *    aplikace poskytovatele jako při přihlášení do ISDS."
 *   „Od 11.9.2016, pokud je už uživatel úspěšně přihlášen do portálu ISDS, není
 *    nucen znovu zadávat přihlašovací údaje a je automaticky přihlášen."
 *   Tedy: kdo je do portálu přihlášený (třeba Identitou občana), na bránu
 *   projde bez hesla.
 *
 * VÝLUKA, KTERÁ SE PODLE ZNĚNÍ NEUPLATNÍ — Provozní řád ISDS, kap. „Přihlášení
 * Identitou občana":
 *   „Přihlášení Identitou občana je možné jen v prostředí Klientského portálu
 *    ISDS. Z prostředí aplikací třetích stran (přihlášení pomocí webových
 *    služeb) není tato autentizační metoda podporována."
 *   Přihlašovací stránka brány ale NENÍ přihlášení webovými službami — běží
 *   na `www.<prostředí>/as/login`, tedy v perimetru ISDS. Výluka míří na Basic
 *   autentizaci strojového rozhraní. Výslovně to ale nikde napsané není.
 *
 * ── Proč to nejde ověřit bez registrace ─────────────────────────────────────
 * `GET https://www.datovka.gov.cz/as/login?atsId=<neplatné>` i jeho testovací
 * protějšek vracejí HTTP 404, takže přihlašovací formulář se bez zaregistrované
 * brány vůbec nezobrazí. Ověřit to musí provozovatel ve veřejném testu
 * (`datovka-test.gov.cz`, Nastavení → Pro vývojáře → Testovací schránky).
 *
 * ── Co z toho plyne prakticky ───────────────────────────────────────────────
 * Ani v nejhorším případě to není blokátor. Uživatel, který heslo nikdy neměl
 * (schránka zřízená online podle § 27 odst. 4 zák. 300/2008 Sb.), si ho může
 * nechat vydat rovnou na obrazovku po přihlášení Identitou občana — Provozní
 * řád, výčet způsobů vydání přístupových údajů, písm. d): „Zadáním požadavku
 * v prostředí klientského portálu ISDS po přihlášení Identitou občana."
 * Je to krok navíc, ne překážka.
 */
enum IsdsGatewayLoginPolicy: string
{
    /**
     * Výchozí a jediná poctivá hodnota, dokud to provozovatel neověří pokusem.
     * Uživateli se řekne, ať počítá s tím, že bude potřebovat jméno a heslo,
     * a zároveň že s aktivní relací portálu může projít bez nich.
     */
    case Unknown = 'unknown';

    /** Ověřeno pokusem: brána chce jméno a heslo vždy, SSO z portálu nestačí. */
    case PasswordRequired = 'password_required';

    /**
     * Ověřeno pokusem: kdo je přihlášený do portálu ISDS (libovolnou metodou,
     * tedy i Identitou občana), projde na bránu bez zadávání údajů.
     */
    case PortalSsoOrPassword = 'portal_sso_or_password';

    public static function fromDatabase(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Unknown;
    }

    /** Text pro uživatele PŘED přesměrováním. Nikdy netvrdí víc, než je doloženo. */
    public function userGuidance(): string
    {
        return match ($this) {
            self::Unknown => 'Přihlásíte se přímo v Informačním systému datových schránek — '
                . 'my vaše přihlašovací údaje nevidíme ani neukládáme. Připravte si jméno a heslo '
                . 'do datové schránky. Pokud už v datové schránce přihlášení jste, může vás systém '
                . 'pustit dál rovnou; jestli to platí i pro přihlášení Identitou občana, nemáme '
                . 'doložené a záleží to na Informačním systému datových schránek, ne na nás.',
            self::PasswordRequired => 'Přihlásíte se přímo v Informačním systému datových schránek — '
                . 'my vaše přihlašovací údaje nevidíme ani neukládáme. Budete potřebovat jméno a heslo '
                . 'do datové schránky. Pokud heslo nemáte (schránku máte jen přes Identitu občana), '
                . 'necháte si ho vydat v Portálu datových schránek.',
            self::PortalSsoOrPassword => 'Přihlásíte se přímo v Informačním systému datových schránek — '
                . 'my vaše přihlašovací údaje nevidíme ani neukládáme. Pokud jste do datové schránky '
                . 'právě přihlášeni (libovolným způsobem, i Identitou občana), projdete rovnou; '
                . 'jinak zadáte jméno a heslo.',
        };
    }

    /** Je odpověď na otázku z §7 rozboru doložená pokusem, nebo pořád otevřená? */
    public function isDocumented(): bool
    {
        return $this !== self::Unknown;
    }
}
