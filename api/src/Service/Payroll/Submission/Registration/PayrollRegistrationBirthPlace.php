<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookUnavailableException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookValueException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;

/**
 * Místo narození (`birth/@cit`, ID 10066) ve tvaru, který chce ČSSZ.
 *
 * REGZEC nese obec narození a stát zvlášť (`birth/@stat`, ID 10065). PREZEC
 * žádný atribut pro stát narození nemá, takže Všeobecné zásady Předregistrace
 * (verze 27. 5. 2026, ID 10066) říkají: „Je-li obec narození mimo území České
 * republiky, uvede se za názvem obce čárka, mezera a název státu narození."
 * Název státu se bere z připnutého číselníku CIS Stát (CZEMALFA), ne z hlavy.
 *
 * Atribut má v obou schématech nejvýš 50 znaků; delší hodnotu by odmítla až
 * kontrola proti XSD technickou hláškou, proto se hlídá tady lidskou větou.
 */
final class PayrollRegistrationBirthPlace
{
    public const MAX_LENGTH = 50;

    private static ?JmhzExternalCodebookCatalog $catalog = null;

    public static function forRegzec(string $birthPlace): string
    {
        return self::requireFits($birthPlace);
    }

    /**
     * @param string $validOn den, ke kterému se podání připravuje (výběr
     *                        platné verze číselníku států)
     */
    public static function forPrezec(
        string $birthPlace,
        string $birthCountryCode,
        string $validOn,
    ): string {
        if ($birthCountryCode === 'CZ') {
            return self::requireFits($birthPlace);
        }

        return self::requireFits(
            $birthPlace . ', ' . self::countryName($birthCountryCode, $validOn),
        );
    }

    private static function countryName(string $code, string $validOn): string
    {
        self::$catalog ??= new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog());
        try {
            $label = self::$catalog->requireCountry($code, $validOn)['label'] ?? null;
        } catch (JmhzCodebookUnavailableException|JmhzCodebookValueException $exception) {
            throw new PayrollRegistrationXmlException(
                'registration_identity_invalid',
                PayrollRegistrationFieldVocabulary::label('birth_country_code')
                    . ' „' . $code . '" se nepodařilo převést na název státu '
                    . 'z číselníku ČSSZ (' . $exception->getMessage() . ') '
                    . 'Částečné přihlášení (PREZEC) nese stát narození za '
                    . 'názvem obce, takže bez názvu ho nejde podat. '
                    . PayrollRegistrationFieldVocabulary::describe('birth_country_code'),
            );
        }
        if (!is_string($label) || trim($label) === '') {
            throw new PayrollRegistrationXmlException(
                'registration_identity_invalid',
                'Číselník států ČSSZ nemá pro kód „' . $code . '" název. '
                    . PayrollRegistrationFieldVocabulary::describe('birth_country_code'),
            );
        }

        return trim($label);
    }

    private static function requireFits(string $place): string
    {
        $length = mb_strlen($place);
        if ($length > self::MAX_LENGTH) {
            throw new PayrollRegistrationXmlException(
                'registration_identity_invalid',
                PayrollRegistrationFieldVocabulary::label('birth_place')
                    . ' „' . $place . '" je delší, než ČSSZ přijme: nejvýš '
                    . self::MAX_LENGTH . ' znaků včetně případného státu '
                    . 'narození za čárkou, teď jich má ' . $length . '. '
                    . 'Zkraťte název obce narození. '
                    . PayrollRegistrationFieldVocabulary::describe('birth_place'),
            );
        }

        return $place;
    }
}
