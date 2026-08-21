<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * H-02 — spravovaná instalace: co si aplikace nesmí přenastavit sama.
 *
 * Ve spravovaném provozu (`app.managed = true`) drží konfiguraci provozovatel,
 * ne uživatel. Aplikace přitom NESMÍ vědět, KDO ji hostuje — `app.managed_provider`
 * je čistě diagnostický údaj do `/api/health` a nesmí na něm viset žádné chování.
 * Jedna větev podle dodavatele je začátek konce přenositelnosti.
 *
 * Tahle třída je JEDINÉ místo, které ví, co je zamčené. Kdyby se podmínka
 * `app.managed` rozsypala do jednotlivých akcí, každá nová obrazovka by musela
 * na zámek přijít znovu — a přesně tak vznikne díra. Ostatní kód se ptá tady:
 *
 *   if (($locked = $this->managed->deny($response, ManagedModeGuard::KEY_APP_URL)) !== null) {
 *       return $locked;
 *   }
 *
 * Zámek MUSÍ platit i mimo UI. Skrytí tlačítka není zámek — kdo endpoint zavolá
 * přímo, musí dostat chybu {@see self::ERROR_CODE} se statusem {@see self::HTTP_STATUS}.
 *
 * Co je zamčené a proč:
 *
 *  - **self-update** — na flotile běží jedna verze a nasazuje ji provozovatel;
 *    samoaktualizace by ji rozešla,
 *  - **`app.url`** — visí na ní tenantový host gate (421) i licenční fingerprint,
 *    špatná hodnota instanci zamkne,
 *  - **SMTP zákazníka (vlastní transport, včetně obálkové adresy)** — SMTP účet
 *    zapisuje hosting, obálku určuje jeho MTA a na ní stojí SPF,
 *  - **`bank_import.scan_root` / `purchase_invoice.inbox_dir`** — čtení souborového
 *    systému mimo instanci; v multi-tenant provozu je to nejvážnější položka
 *    (`exec()` zůstává povolený),
 *  - **`epo_test`** — zkušební prostředí daňové správy v ostré instalaci znamená
 *    tiše nepodaná hlášení,
 *  - **`app.debug`** — stack trace v odpovědích API na cizí infrastruktuře,
 *  - **demo režim** — v ostré zákaznické instalaci nemá co dělat,
 *  - **vlastní domény firem (H-30)** — certifikát ani routing pro cizí hostname
 *    u nás nikdo nezřídí a wildcard je nepokrývá; zákazník by založil doménu,
 *    kterou nikdy neověří.
 */
final class ManagedModeGuard
{
    /** Strojový kód odpovědi. Frontend podle něj pozná „tohle řeší někdo jiný". */
    public const ERROR_CODE = 'managed_installation';

    /** 409 = konflikt se stavem instalace, ne chybějící oprávnění uživatele. */
    public const HTTP_STATUS = 409;

    // ── Konfigurační klíče (dot notation, přesně jak je čte Config::get) ──────
    public const KEY_APP_URL            = 'app.url';
    public const KEY_APP_DEBUG          = 'app.debug';
    public const KEY_DEMO_ENABLED       = 'demo.enabled';
    public const KEY_EPO_TEST           = 'epo_test';
    public const KEY_BANK_SCAN_ROOT     = 'bank_import.scan_root';
    public const KEY_PURCHASE_INBOX_DIR = 'purchase_invoice.inbox_dir';

    /**
     * Celý blok `smtp.*`. Zamyká se prefixem, ne výčtem: nový klíč (další
     * ověřovací mechanismus, obálková adresa) by se do výčtu doplnit zapomněl.
     */
    public const KEY_SMTP_PREFIX = 'smtp';

    // ── Schopnosti (nejsou to konfigurační klíče, ale zamykají se stejně) ─────
    public const CAPABILITY_SELF_UPDATE     = 'app.self_update';
    public const CAPABILITY_MAIL_TRANSPORT  = 'mail.custom_transport';
    public const CAPABILITY_FILESYSTEM_SCAN = 'filesystem.scan';
    public const CAPABILITY_CUSTOM_DOMAINS  = 'supplier_domains';

    /** Fallback, když se zeptá někdo na předmět, který v mapě není. */
    private const GENERIC_MESSAGE = 'Tuhle věc drží ve spravované instalaci provozovatel — z aplikace ji změnit nelze.';

    /**
     * Lidské vysvětlení ke každému předmětu zámku. Uživatel musí vidět, že to
     * řeší někdo jiný, ne že to nefunguje.
     *
     * @var array<string,string>
     */
    private const EXPLANATIONS = [
        self::CAPABILITY_SELF_UPDATE =>
            'Tohle je spravovaná instalace — aktualizace nasazuje provozovatel, aby na všech instancích běžela stejná verze.',
        self::KEY_APP_URL =>
            'Adresu aplikace nastavuje ve spravované instalaci provozovatel — je navázaná na směrování požadavků i na licenci.',
        self::CAPABILITY_MAIL_TRANSPORT =>
            'Odesílání e-mailů zajišťuje ve spravované instalaci provozovatel. Vlastní SMTP server ani obálkovou adresu tu nastavit nelze; odesílatele (From, Reply-To) měnit můžete.',
        self::KEY_SMTP_PREFIX =>
            'Odesílání e-mailů zajišťuje ve spravované instalaci provozovatel. Vlastní SMTP server ani obálkovou adresu tu nastavit nelze; odesílatele (From, Reply-To) měnit můžete.',
        self::CAPABILITY_FILESYSTEM_SCAN =>
            'Skenování adresářů na serveru je ve spravované instalaci vypnuté. Výpisy i doklady nahrávejte souborem nebo e-mailem.',
        self::KEY_BANK_SCAN_ROOT =>
            'Skenování adresářů na serveru je ve spravované instalaci vypnuté. Výpisy i doklady nahrávejte souborem nebo e-mailem.',
        self::KEY_PURCHASE_INBOX_DIR =>
            'Skenování adresářů na serveru je ve spravované instalaci vypnuté. Výpisy i doklady nahrávejte souborem nebo e-mailem.',
        self::KEY_EPO_TEST =>
            'Prostředí daňové správy nastavuje ve spravované instalaci provozovatel — ostrá instalace podává vždy naostro.',
        self::KEY_APP_DEBUG =>
            'Ladicí režim ve spravované instalaci zapíná provozovatel.',
        self::KEY_DEMO_ENABLED =>
            'Demo režim ve spravované instalaci není k dispozici.',
        self::CAPABILITY_CUSTOM_DOMAINS =>
            'Ve spravované instalaci nastavuje doménu provozovatel — certifikát a směrování pro vlastní doménu je potřeba zřídit na jeho straně.',
    ];

    public function __construct(private readonly Config $config) {}

    /**
     * Jediné čtení `app.managed` v aplikaci.
     *
     * Přes FILTER_VALIDATE_BOOLEAN, protože hodnota může přijít z ENV jako
     * `"1"`/`"true"` — `(bool) "false"` by byl true.
     */
    public function isManaged(): bool
    {
        return filter_var(
            $this->config->get('app.managed', false),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) === true;
    }

    /**
     * Konfigurační klíče, které si spravovaná instalace nesmí přepsat.
     * `smtp` je prefix celého bloku, ne jeden klíč.
     *
     * @return list<string>
     */
    public function lockedKeys(): array
    {
        return [
            self::KEY_APP_URL,
            self::KEY_APP_DEBUG,
            self::KEY_DEMO_ENABLED,
            self::KEY_EPO_TEST,
            self::KEY_BANK_SCAN_ROOT,
            self::KEY_PURCHASE_INBOX_DIR,
            self::KEY_SMTP_PREFIX,
        ];
    }

    /**
     * Schopnosti, které se ve spravovaném režimu zamykají, ale nejsou to
     * konfigurační klíče (self-update, vlastní domény, …).
     *
     * @return list<string>
     */
    public function lockedCapabilities(): array
    {
        return [
            self::CAPABILITY_SELF_UPDATE,
            self::CAPABILITY_MAIL_TRANSPORT,
            self::CAPABILITY_FILESYSTEM_SCAN,
            self::CAPABILITY_CUSTOM_DOMAINS,
        ];
    }

    /** Je předmět (klíč nebo schopnost) v TÉTO instalaci zamčený? */
    public function isLocked(string $subject): bool
    {
        return $this->isManaged() && $this->isLockedSubject($subject);
    }

    /**
     * Smí se konfigurační klíč v téhle instalaci měnit?
     * Klíče pod zamčeným prefixem (`smtp.host`) jsou zamčené taky.
     */
    public function isConfigurable(string $key): bool
    {
        return !$this->isLocked($key);
    }

    /**
     * Efektivní hodnota přepínače, který je ve spravovaném režimu zamčený.
     *
     * Existuje kvůli klíčům, které nikdo nezapisuje přes API — jen se čtou
     * z `cfg.php` (`epo_test`, `app.debug`, `demo.enabled`). Zamknout je
     * znamená ČÍST je jako vypnuté, ne odmítnout zápis, který stejně nikdo
     * nedělá.
     *
     * ⚠️ Klíč, který se čte na víc místech, se musí přepnout na VŠECH naráz.
     * Jedno místo přepnuté a druhé ne = rozhraní tvrdí něco jiného, než co se
     * doopravdy stane — u `epo_test` právě to znamená tiše nepodané hlášení.
     */
    public function effectiveFlag(string $key, bool $configured): bool
    {
        return $configured && !$this->isLocked($key);
    }

    /**
     * Tvrdá varianta pro zapisovače konfigurace a CLI.
     *
     * @throws ManagedInstallationException
     */
    public function assertConfigurable(string $key): void
    {
        if ($this->isLocked($key)) {
            throw new ManagedInstallationException($key, $this->explain($key));
        }
    }

    /** Lidské vysvětlení, proč to nejde. Nikdy neprozrazuje, kdo instanci hostuje. */
    public function explain(string $subject): string
    {
        return self::EXPLANATIONS[$this->lockedSubjectFor($subject) ?? $subject] ?? self::GENERIC_MESSAGE;
    }

    /**
     * HTTP odmítnutí pro Action vrstvu. Vrací null, když se nic nezamyká —
     * volající tak píše `if (($locked = $this->managed->deny(...)) !== null) return $locked;`
     * a nemusí `isManaged()` řešit sám.
     */
    public function deny(Response $response, string $subject): ?Response
    {
        if (!$this->isLocked($subject)) {
            return null;
        }

        return Json::error(
            $response,
            self::ERROR_CODE,
            $this->explain($subject),
            self::HTTP_STATUS,
            ['locked' => $subject],
        );
    }

    private function isLockedSubject(string $subject): bool
    {
        return $this->lockedSubjectFor($subject) !== null;
    }

    /**
     * Vrátí zamčený předmět, pod který dotaz spadá (kvůli prefixům jím nemusí
     * být sám dotaz), nebo null.
     */
    private function lockedSubjectFor(string $subject): ?string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return null;
        }

        foreach ($this->lockedCapabilities() as $capability) {
            if ($subject === $capability) {
                return $capability;
            }
        }

        foreach ($this->lockedKeys() as $key) {
            if ($subject === $key || str_starts_with($subject, $key . '.')) {
                return $key;
            }
        }

        return null;
    }
}
