<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Meze příloh registračního podání REGZEC (`attachs/attach`, ID 10396, 10397,
 * 10400; EDV 1.4.0.6 a Všeobecné zásady 1.4.6).
 *
 * Nejvýš devět příloh, název každé je jedinečný a nese příponu ze seznamu
 * podporovaných, jeden soubor má nejvýš 2 MB a všechny dohromady nejvýš 4 MB.
 * Přílohy nese přihláška A1 (profil) a storno A8 (zdůvodnění); obě cesty
 * se ptají tady.
 */
final class PayrollRegistrationAttachmentRules
{
    public const MAX_COUNT = 9;
    public const MAX_FILE_BYTES = 2 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 4 * 1024 * 1024;

    /** @var list<string> */
    public const EXTENSIONS = ['doc', 'docx', 'rtf', 'xls', 'xlsx', 'pdf', 'jpg', 'txt'];

    public const VIOLATION_EXTENSION = 'extension';
    public const VIOLATION_DUPLICATE_NAME = 'duplicate_name';
    public const VIOLATION_FILE_SIZE = 'file_size';
    public const VIOLATION_TOTAL_SIZE = 'total_size';

    public static function extensionAllowed(string $name): bool
    {
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === strlen($name) - 1) {
            return false;
        }

        return in_array(
            strtolower(substr($name, $dot + 1)),
            self::EXTENSIONS,
            true,
        );
    }

    /** Velikost souboru po dekódování base64 (neplatný obsah = 0, ten hlásí volající). */
    public static function decodedBytes(string $dataBase64): int
    {
        $decoded = base64_decode($dataBase64, true);

        return $decoded === false ? 0 : strlen($decoded);
    }

    /**
     * Porušení mezí pro seznam příloh; počet se hlídá zvlášť u volajícího.
     *
     * @param list<array{name:string,data_base64:string}> $attachments
     * @return list<array{kind:string,index:int,name:string,bytes:int}>
     */
    public static function violations(array $attachments): array
    {
        $result = [];
        $seen = [];
        $total = 0;
        foreach ($attachments as $index => $attachment) {
            $name = $attachment['name'];
            $bytes = self::decodedBytes($attachment['data_base64']);
            $total += $bytes;
            if ($name !== '' && !self::extensionAllowed($name)) {
                $result[] = self::violation(self::VIOLATION_EXTENSION, $index, $name, $bytes);
            }
            $key = mb_strtolower($name, 'UTF-8');
            if ($name !== '' && isset($seen[$key])) {
                $result[] = self::violation(self::VIOLATION_DUPLICATE_NAME, $index, $name, $bytes);
            }
            $seen[$key] = true;
            if ($bytes > self::MAX_FILE_BYTES) {
                $result[] = self::violation(self::VIOLATION_FILE_SIZE, $index, $name, $bytes);
            }
        }
        if ($total > self::MAX_TOTAL_BYTES) {
            $result[] = self::violation(self::VIOLATION_TOTAL_SIZE, count($attachments) - 1, '', $total);
        }

        return $result;
    }

    /** @return array{kind:string,index:int,name:string,bytes:int} */
    private static function violation(string $kind, int $index, string $name, int $bytes): array
    {
        return ['kind' => $kind, 'index' => $index, 'name' => $name, 'bytes' => $bytes];
    }

    public static function extensionsText(): string
    {
        return implode(', ', array_map(
            static fn (string $extension): string => '.' . $extension,
            self::EXTENSIONS,
        ));
    }

    /** @param array{kind:string,index:int,name:string,bytes:int} $violation */
    public static function message(array $violation): string
    {
        $name = $violation['name'] === '' ? '' : ' „' . $violation['name'] . '"';

        return match ($violation['kind']) {
            self::VIOLATION_EXTENSION => 'Příloha' . $name
                . ' nemá podporovanou příponu. ČSSZ přijme jen '
                . self::extensionsText() . '.',
            self::VIOLATION_DUPLICATE_NAME => 'Příloha' . $name
                . ' má stejný název jako jiná příloha. Názvy příloh se nesmí '
                . 'opakovat, přejmenujte ji.',
            self::VIOLATION_FILE_SIZE => 'Příloha' . $name
                . ' je větší než 2 MB, což je nejvíc, co ČSSZ přijme. '
                . 'Zmenšete ji, nebo rozdělte.',
            default => 'Přílohy jsou dohromady větší než 4 MB, což je nejvíc, '
                . 'co ČSSZ přijme. Odeberte nebo zmenšete některé z nich.',
        };
    }
}
