<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Pozná variabilní symbol zaměstnavatele, který vypadá jako zástupná hodnota.
 *
 * PROČ: variabilní symbol se u mzdové účtárny zadává ručně a v praxi tam
 * zůstává ukázková hodnota z testů nebo návodu (`9876543210`, `1234567890`,
 * samé nuly). Formálně je v pořádku — deset číslic — takže ji kontroly
 * přípravy registrace pustily a PREZEC by odešel s cizím nebo neexistujícím
 * zaměstnavatelem. Tady se neblokuje (skutečný symbol takhle vypadat v krajním
 * případě může), jen se na to upozorní s proklikem do nastavení.
 */
final class EmployerVariableSymbolPlausibility
{
    /** Vzestupné a sestupné řady číslic, jak je lidé píší do ukázek. */
    private const SEQUENCES = ['0123456789', '1234567890', '9876543210', '0987654321'];

    /**
     * Důvod podezření, nebo `null`, když symbol zástupně nevypadá.
     */
    public static function placeholderReason(?string $variableSymbol): ?string
    {
        $value = trim((string) $variableSymbol);
        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }
        if (strlen($value) >= 6 && count(array_unique(str_split($value))) === 1) {
            return 'je složený z jediné opakované číslice';
        }
        foreach (self::SEQUENCES as $sequence) {
            if (strlen($value) >= 6 && str_contains($sequence, $value)) {
                return 'je řada po sobě jdoucích číslic, jaká se používá v ukázkách';
            }
        }

        return null;
    }

    /**
     * Upozornění pro náhled registrace, nebo `null`.
     *
     * @return array{code:string,field:string,message:string,target:string}|null
     */
    public static function warning(?string $variableSymbol): ?array
    {
        $reason = self::placeholderReason($variableSymbol);
        if ($reason === null) {
            return null;
        }

        return [
            'code' => 'employer_variable_symbol_placeholder',
            'field' => 'employer_variable_symbol',
            'message' => PayrollRegistrationFieldVocabulary::label(
                'employer_variable_symbol',
            ) . ' ' . trim((string) $variableSymbol) . ' ' . $reason
                . ' — nejspíš jde o zástupnou hodnotu, ne o symbol, který '
                . 'firmě přidělila ČSSZ. Ověřte ho v '
                . PayrollRegistrationFieldVocabulary::WHERE_EMPLOYER
                . ' dřív, než podání odešlete.',
            'target' => PayrollRegistrationIdentityRequirements::TARGET_EMPLOYER_SETTINGS,
        ];
    }
}
