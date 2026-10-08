<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

/**
 * Předmět e-mailu s fakturou a název přiloženého PDF podle klienta (myinvoice#277).
 *
 * Firemní odběratelé zpracovávají přijaté faktury automaticky a předepisují tvar
 * předmětu i názvu souboru, např. `Klient_{DUZP_MM}_{DUZP_YYYY}_Dodavatel`. Formát
 * je prostý text se zástupnými znaky ve stylu číselných řad ({VS}, {MM}…), ne Twig:
 * Twig by HTML-escapoval (`A & B` → `A &amp; B` v hlavičce) a název klienta se
 * složenou závorkou by z něj udělal neplatnou šablonu.
 *
 * Jediné místo, které formát ověřuje a vykresluje — validace klienta, e-mail
 * (InvoiceEmailVarsBuilder) i náhled ve formuláři klienta (web/src/utils/
 * clientEmailFormat.ts, stejné testovací případy) se drží téhož pravidla.
 */
final class ClientEmailFormat
{
    public const SUBJECT_MAX_LENGTH = 200;
    public const ATTACHMENT_NAME_MAX_LENGTH = 120;

    /** Měsíc a rok bez prefixu jsou z data vystavení, s prefixem DUZP_ z data plnění. */
    public const TOKENS = ['VS', 'TYP', 'KLIENT', 'DODAVATEL', 'MM', 'YYYY', 'YY', 'DUZP_MM', 'DUZP_YYYY', 'DUZP_YY'];

    /** Znaky, které nesmí být v názvu souboru na žádné platformě. */
    private const FILE_NAME_FORBIDDEN = '/[\\\\\/:*?"<>|\x00-\x1F\x7F]/u';

    /** Prázdný či chybějící formát = výchozí chování (NULL v DB). */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $v = trim((string) $value);
        return $v === '' ? null : $v;
    }

    /**
     * Chyby formátu pro Validation::client (prázdné pole = OK).
     *
     * @return list<string>
     */
    public static function errors(mixed $value, int $maxLength, bool $fileName): array
    {
        if ($value !== null && !is_string($value)) {
            return ['Formát musí být text.'];
        }
        $v = self::normalize($value);
        if ($v === null) {
            return [];
        }

        $errors = [];
        if (mb_strlen($v) > $maxLength) {
            $errors[] = "Formát smí mít nejvýš {$maxLength} znaků.";
        }
        if (preg_match('/[\r\n]/', $v)) {
            $errors[] = 'Formát musí být na jednom řádku.';
        }
        preg_match_all('/\{([^{}]*)\}/', $v, $m);
        $unknown = array_values(array_diff(array_unique($m[1]), self::TOKENS));
        if ($unknown !== []) {
            $errors[] = 'Neznámý zástupný znak {' . implode('}, {', $unknown) . '}. Dovolené: {'
                . implode('} {', self::TOKENS) . '}.';
        }
        if (preg_match('/[{}]/', preg_replace('/\{[^{}]*\}/', '', $v) ?? '')) {
            $errors[] = 'Formát obsahuje neuzavřenou složenou závorku.';
        }
        if ($fileName) {
            $literal = preg_replace('/\{(' . implode('|', self::TOKENS) . ')\}/', '', $v) ?? '';
            if (preg_match(self::FILE_NAME_FORBIDDEN, $literal)) {
                $errors[] = 'Název souboru nesmí obsahovat znaky \\ / : * ? " < > |.';
            }
        }
        return $errors;
    }

    /**
     * Hodnoty zástupných znaků pro konkrétní doklad.
     *
     * @param array<string,mixed> $invoice Řádek z InvoiceRepository::find()
     * @return array<string,string>
     */
    public static function values(array $invoice, string $clientName, string $supplierName, string $typeLabel): array
    {
        $issued = self::date($invoice['issue_date'] ?? null);
        // Zálohová faktura DUZP nemá — plnění se pak bere ke dni vystavení.
        $taxed = self::date($invoice['tax_date'] ?? null) ?? $issued;

        return [
            'VS'        => (string) ($invoice['varsymbol'] ?? ''),
            'TYP'       => $typeLabel,
            'KLIENT'    => $clientName,
            'DODAVATEL' => $supplierName,
            'MM'        => $issued?->format('m') ?? '',
            'YYYY'      => $issued?->format('Y') ?? '',
            'YY'        => $issued?->format('y') ?? '',
            'DUZP_MM'   => $taxed?->format('m') ?? '',
            'DUZP_YYYY' => $taxed?->format('Y') ?? '',
            'DUZP_YY'   => $taxed?->format('y') ?? '',
        ];
    }

    /**
     * Předmět e-mailu; null = formát nevyplněný nebo po dosazení prázdný.
     *
     * @param array<string,string> $values
     */
    public static function subject(?string $format, array $values): ?string
    {
        if ($format === null) {
            return null;
        }
        $subject = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', self::render($format, $values)) ?? '';
        $subject = preg_replace(['/\s{2,}/u', '/^\s+|\s+$/u'], [' ', ''], $subject) ?? '';
        return $subject === '' ? null : $subject;
    }

    /**
     * Název přiloženého PDF včetně přípony; null = použije se výchozí název.
     *
     * @param array<string,string> $values
     */
    public static function attachmentName(?string $format, array $values): ?string
    {
        if ($format === null) {
            return null;
        }
        $name = preg_replace(self::FILE_NAME_FORBIDDEN, '_', self::render($format, $values)) ?? '';
        $name = preg_replace(['/\s{2,}/u', '/^[\s.]+|[\s.]+$/u'], [' ', ''], $name) ?? '';
        if ($name === '') {
            return null;
        }
        return mb_substr($name, 0, self::ATTACHMENT_NAME_MAX_LENGTH) . '.pdf';
    }

    /** @param array<string,string> $values */
    private static function render(string $format, array $values): string
    {
        return preg_replace_callback(
            '/\{(' . implode('|', self::TOKENS) . ')\}/',
            static fn (array $m): string => $values[$m[1]] ?? '',
            $format,
        ) ?? $format;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $date === false ? null : $date;
    }
}
