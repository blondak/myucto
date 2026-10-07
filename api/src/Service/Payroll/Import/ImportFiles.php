<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import;

/**
 * Soubory importu tak, jak je posílá stránka Mzdy → Importy: pole
 * `[{name, content_base64}]` v těle JSON požadavku.
 *
 * Importy registrací i docházky pracují s více soubory najednou (hlavní sešit,
 * pomocné sešity, CSV), takže limity platí na soubor i na celou dávku. Obsah se
 * nikam neukládá — server z něj při náhledu i použití vždy počítá znovu.
 */
final class ImportFiles
{
    public const MAX_FILES = 20;
    public const MAX_FILE_BYTES = 5_000_000;
    public const MAX_TOTAL_BYTES = 15_000_000;
    /**
     * Limity importu registrací a hlášení: balík JMHZ má až 1500 formulářů po 7-8 kB,
     * což je kolem 11 MB. Tělo požadavku nese soubor v base64 (+ třetina), takže
     * 20 MB souborů je zhruba 27 MB těla - pod výchozími 30 MB IIS
     * (`maxAllowedContentLength`), 55 MB nginx a PHP `post_max_size` v Dockeru.
     */
    public const XML_MAX_FILE_BYTES = 20_000_000;
    public const XML_MAX_TOTAL_BYTES = 20_000_000;

    /**
     * @param list<string> $allowedExtensions přípony malými písmeny bez tečky
     * @return list<array{name:string,content:string,sha256:string,extension:string}>
     */
    public static function fromRequest(
        mixed $files,
        array $allowedExtensions,
        int $maxFileBytes = self::MAX_FILE_BYTES,
        int $maxTotalBytes = self::MAX_TOTAL_BYTES,
    ): array {
        if (!is_array($files) || !array_is_list($files) || $files === []) {
            throw new \InvalidArgumentException('Vyberte aspoň jeden soubor k importu.');
        }
        if (count($files) > self::MAX_FILES) {
            throw new \InvalidArgumentException(
                'Najednou lze nahrát nejvýše ' . self::MAX_FILES . ' souborů. Rozdělte import na víc dávek.',
            );
        }

        $result = [];
        $total = 0;
        $seen = [];
        foreach ($files as $index => $file) {
            if (!is_array($file)) {
                throw new \InvalidArgumentException('Soubor č. ' . ($index + 1) . ' nemá platný tvar.');
            }
            $name = self::name($file['name'] ?? null);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                throw new \InvalidArgumentException(
                    "Soubor „{$name}“ má nepodporovaný typ. Povolené jsou: "
                    . implode(', ', array_map(static fn (string $e): string => '.' . $e, $allowedExtensions)) . '.',
                );
            }
            $encoded = $file['content_base64'] ?? null;
            if (!is_string($encoded) || $encoded === '') {
                throw new \InvalidArgumentException("Soubor „{$name}“ je prázdný.");
            }
            if (strlen($encoded) > (int) ceil($maxFileBytes * 4 / 3) + 4) {
                throw new \InvalidArgumentException(
                    "Soubor „{$name}“ je větší než " . self::megabytes($maxFileBytes) . ' MB. Zmenšete ho nebo ho rozdělte.',
                );
            }
            $content = base64_decode($encoded, true);
            if ($content === false || $content === '') {
                throw new \InvalidArgumentException("Obsah souboru „{$name}“ se nepodařilo načíst. Nahrajte ho znovu.");
            }
            if (strlen($content) > $maxFileBytes) {
                throw new \InvalidArgumentException(
                    "Soubor „{$name}“ je větší než " . self::megabytes($maxFileBytes) . ' MB. Zmenšete ho nebo ho rozdělte.',
                );
            }
            $total += strlen($content);
            if ($total > $maxTotalBytes) {
                throw new \InvalidArgumentException(
                    'Soubory mají dohromady víc než ' . self::megabytes($maxTotalBytes) . ' MB. Rozdělte import na víc dávek.',
                );
            }
            $sha256 = hash('sha256', $content);
            if (isset($seen[$sha256])) {
                throw new \InvalidArgumentException(
                    "Soubor „{$name}“ je v dávce dvakrát (stejný obsah jako „{$seen[$sha256]}“). Jeden odeberte.",
                );
            }
            $seen[$sha256] = $name;
            $result[] = [
                'name' => $name,
                'content' => $content,
                'sha256' => $sha256,
                'extension' => $extension,
            ];
        }

        return $result;
    }

    private static function megabytes(int $bytes): int
    {
        return (int) round($bytes / 1_000_000);
    }

    private static function name(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Soubor nemá název.');
        }
        $name = basename(str_replace('\\', '/', trim($value)));
        if ($name === '' || mb_strlen($name) > 190 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new \InvalidArgumentException('Název importního souboru není platný.');
        }

        return $name;
    }
}
