<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * PSČ v datové větě REGZEC.
 *
 * Atribut `pnu` všech adres (trvalý pobyt, doručovací, pobyt v ČR i bydliště
 * ve státě rezidence) je v REGZEC25.xsd typu `simpleA_NN_ZZType`, jehož vzor
 * mezeru NEPŘIPOUŠTÍ. Účetní přitom PSČ píše zvykově „110 00", takže bez
 * normalizace skončí podání na technické hlášce XSD místo srozumitelné věty.
 * Jednu implementaci mají sdílet všechny cesty, které PSČ do věty dávají.
 */
final class PayrollRegistrationPostalCode
{
    /** Znaky, které vzor `simpleA_NN_ZZType` povoluje (mimo mezeru). */
    private const FOREIGN_PATTERN = "/^[\\p{L}\\d\\-,.+'\\/\\\\]+$/u";

    /** Odstraní všechny druhy mezer, včetně pevné a úzké pevné. */
    public static function normalize(string $postalCode): string
    {
        return (string) preg_replace(
            '/[\s\x{00A0}\x{202F}\x{2007}]+/u',
            '',
            $postalCode,
        );
    }

    /**
     * Normalizované PSČ, nebo null, když pro daný stát nemá platný tvar.
     * České PSČ je pětimístné; u cizích států se hlídá jen množina znaků,
     * kterou schéma povoluje (formáty států se liší).
     */
    public static function valid(string $postalCode, ?string $countryCode): ?string
    {
        $normalized = self::normalize($postalCode);
        if ($normalized === '') {
            return null;
        }
        if ($countryCode === 'CZ') {
            return preg_match('/^\d{5}$/D', $normalized) === 1 ? $normalized : null;
        }

        return preg_match(self::FOREIGN_PATTERN, $normalized) === 1
            ? $normalized
            : null;
    }
}
