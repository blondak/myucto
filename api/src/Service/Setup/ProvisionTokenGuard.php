<?php

declare(strict_types=1);

namespace MyInvoice\Service\Setup;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Config\CfgLocalWriter;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * H-01 — jednorázový zřizovací token pro `POST /api/auth/setup`.
 *
 * Mezi okamžikem, kdy hosting instanci zřídí, a okamžikem, kdy na ní doběhne
 * headless setup, běží aplikace s prázdnou tabulkou `users` — a setup uspěje
 * tomu, kdo se ozve první. Slug je jméno firmy a DNS je wildcard, takže URL jde
 * uhodnout. Token je (dokud neexistuje omezení na naše IP) jediná vrstva, která
 * to okno zavírá.
 *
 * ⚠️ Pravidlo visí na `app.managed`, NE na přítomnosti klíče. Kdyby znělo
 * „je-li `setup.provision_token` vyplněný, vyžaduj shodu", pak by selhání zápisu
 * do `cfg.local.php` klíč vynechalo a setup by se otevřel všem — fail-open přesně
 * ve chvíli, kdy něco selhalo. Proto:
 *
 *  - `app.managed = true`  → token je POVINNÝ; chybí-li v konfiguraci, setup se
 *    odmítne úplně (radši instance, kterou nezaložíme, než instance, kterou
 *    založí někdo cizí),
 *  - `app.managed` false/nenastaveno → chování beze změny (self-hosted instalace).
 */
final class ProvisionTokenGuard
{
    /** Preferované místo pro token — nepotřebuje JSON schéma a neskončí v logu těla. */
    public const HEADER = 'X-Provision-Token';

    /** Fallback v těle: hlavička je čistší, tělo odolnější vůči proxy. */
    public const BODY_FIELD = 'provision_token';

    public const CONFIG_KEY = 'setup.provision_token';

    public const CODE_REQUIRED = 'provision_token_required';
    public const CODE_INVALID  = 'provision_token_invalid';

    /** Auditní událost neúspěšného pokusu — jediný signál pokusu o zabrání instance. */
    public const LOG_EVENT = 'setup.provision_token_rejected';

    /**
     * Neutrální text. Nesmí prozradit, jestli token v konfiguraci chybí, jestli
     * volající žádný neposlal, ani jak se poslaný liší (délka, prefix, …).
     */
    public const MESSAGE = 'Tuto instanci smí nastavit jen ten, kdo se prokáže platným zřizovacím tokenem.';

    /** Důvody jdou POUZE do auditního logu, nikdy do odpovědi. */
    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_NOT_SUPPLIED   = 'not_supplied';
    public const REASON_MISMATCH       = 'mismatch';

    public function __construct(private readonly Config $config) {}

    /** Vynucuje se jen ve spravovaném režimu. */
    public function isEnforced(): bool
    {
        return filter_var(
            $this->config->get('app.managed', false),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) === true;
    }

    /**
     * @return array{code:string,reason:string}|null null = průchod
     */
    public function verify(Request $request): ?array
    {
        if (!$this->isEnforced()) {
            return null;
        }

        $expected = trim((string) $this->config->get(self::CONFIG_KEY, ''));
        if ($expected === '') {
            // Fail-closed: v managed režimu je token povinný i tehdy, když ho
            // konfigurace nemá. Bez téhle větve by chybějící klíč setup otevřel.
            return ['code' => self::CODE_REQUIRED, 'reason' => self::REASON_NOT_CONFIGURED];
        }

        $supplied = $this->suppliedToken($request);
        if ($supplied === '') {
            return ['code' => self::CODE_REQUIRED, 'reason' => self::REASON_NOT_SUPPLIED];
        }

        if (!hash_equals($expected, $supplied)) {
            return ['code' => self::CODE_INVALID, 'reason' => self::REASON_MISMATCH];
        }

        return null;
    }

    /**
     * Spotřebování tokenu po úspěšném setupu. Setup je jednorázový už tím, že běží
     * jen nad prázdnou `users`; tohle je druhá pojistka.
     *
     * Píše se samostatně (ne v jednom `setKeys()` s MFA politikou), aby se token
     * pokusil zneplatnit i tehdy, když ten první zápis selže.
     */
    public function consume(string $targetDir): void
    {
        CfgLocalWriter::setKeys($targetDir, [self::CONFIG_KEY => '']);
    }

    private function suppliedToken(Request $request): string
    {
        $fromHeader = trim($request->getHeaderLine(self::HEADER));
        if ($fromHeader !== '') {
            return $fromHeader;
        }

        $body = $request->getParsedBody();
        if (is_array($body) && isset($body[self::BODY_FIELD]) && is_scalar($body[self::BODY_FIELD])) {
            return trim((string) $body[self::BODY_FIELD]);
        }

        return '';
    }
}
