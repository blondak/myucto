<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Převod účtu z původního mzdového programu do tvaru účtové osnovy MyÚčta.
 *
 * Původní programy vedou analytiku jako SOUVISLÉ číslo (PAMICA `521000`,
 * `336001`), MyÚčto jako `syntetika.analytika` (`521`, `336.100`). Bez převodu
 * by se převzatý účet nikdy nepotkal s osnovou firmy a všechno by skončilo jako
 * „účet chybí".
 *
 * Převod je ZÁMĚRNĚ doslovný a nehádá: `336001` je `336.001`, ne `336.100`.
 * Uhodnout, že původní `336001` odpovídá zdejší `336.100`, nejde ničím jiným než
 * shodou názvu, a spletená analytika pojistného je přesně ta chyba, která se
 * pozná až při odvodu. Účet, který v osnově není, se proto ukáže jako
 * „z původního programu, v osnově chybí" a účetní k němu vybere protějšek sama.
 */
final class PayrollLegacyAccountCode
{
    /** Tvar účtu MyÚčta; shodně s `PayrollEmployerSettingsValidator` a osnovou. */
    private const NATIVE = '/^[0-9]{3}[.A-Z0-9]{0,13}$/D';

    /**
     * Účet ve tvaru osnovy MyÚčta, nebo `null` u hodnoty, která účet není.
     *
     * Nulová analytika se zahazuje (`521000` → `521`): původní program píše
     * nuly jen proto, že má pevnou šířku, kdežto tady by `521.000` byl jiný účet
     * než `521` a v osnově by nebyl ani jeden.
     */
    public static function normalize(string $raw): ?string
    {
        $value = strtoupper(trim($raw));
        if ($value === '') {
            return null;
        }
        // Souvislé číslo se rozdělí DŘÍV, než ho propustí tvar MyÚčta: `521000`
        // by mu vyhovělo jako syntetika 521 s analytikou „000" a v osnově by
        // takový účet nebyl.
        if (preg_match('/^([0-9]{3})([0-9]{1,13})$/D', $value, $match) === 1) {
            return trim($match[2], '0') === '' ? $match[1] : $match[1] . '.' . $match[2];
        }
        if (preg_match(self::NATIVE, $value) === 1) {
            return $value;
        }

        return null;
    }

    /** Syntetika účtu (první tři číslice), nebo `null`, když ji kód nemá. */
    public static function synthetic(string $code): ?string
    {
        return preg_match('/^[0-9]{3}/', $code, $match) === 1 ? $match[0] : null;
    }
}
