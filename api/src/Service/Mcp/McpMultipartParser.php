<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\UploadedFile;

/**
 * Rozebere multipart/form-data tělo interního požadavku MCP.
 *
 * Interní API běží v CLI procesu, kterému tělo předá most na standardním
 * vstupu, takže PHP ho samo nerozparsuje do `$_FILES` a akce by soubor
 * neviděla. Tahle třída udělá totéž co PHP u webového požadavku: pole
 * formuláře dá do parsed body a soubory uloží do dočasného adresáře jako
 * {@see UploadedFile}, které si akce přesune `moveTo()` jako obvykle.
 *
 * Je přísnější než PHP: nepustí soubor mimo {@see McpFileLimits::ALLOWED_UPLOAD_TYPES},
 * větší než {@see McpFileLimits::MAX_FILE_BYTES}, ani název s cestou.
 * Výjimka nese HTTP status v kódu (400 / 413 / 415).
 */
final class McpMultipartParser
{
    private const MAX_PARTS = 20;
    private const MAX_FIELD_BYTES = 64 * 1024;
    private const MAX_HEADER_BYTES = 8 * 1024;

    /**
     * @return array{0: array<string, mixed>, 1: array<string, UploadedFileInterface|list<UploadedFileInterface>>, 2: list<string>}
     *         pole formuláře, soubory a cesty dočasných souborů k úklidu
     */
    public function parse(string $body, string $contentType, string $tmpDir): array
    {
        $boundary = $this->boundary($contentType);
        $delimiter = '--' . $boundary;
        $start = strpos($body, $delimiter);
        if ($start === false) {
            throw new \InvalidArgumentException('Neplatné multipart tělo.', 400);
        }

        $parts = [];
        $offset = $start + strlen($delimiter);
        while (true) {
            $tail = substr($body, $offset, 2);
            if ($tail === '--') break;
            if ($tail !== "\r\n") {
                throw new \InvalidArgumentException('Neplatné multipart tělo.', 400);
            }
            $offset += 2;
            $next = strpos($body, "\r\n" . $delimiter, $offset);
            if ($next === false) {
                throw new \InvalidArgumentException('Neukončené multipart tělo.', 400);
            }
            $parts[] = substr($body, $offset, $next - $offset);
            if (count($parts) > self::MAX_PARTS) {
                throw new \InvalidArgumentException('Příliš mnoho částí formuláře.', 400);
            }
            $offset = $next + 2 + strlen($delimiter);
        }

        $fields = [];
        $files = [];
        $tmpFiles = [];
        try {
            foreach ($parts as $part) {
                $this->part($part, $tmpDir, $fields, $files, $tmpFiles);
            }
        } catch (\Throwable $e) {
            foreach ($tmpFiles as $path) {
                if (is_file($path)) @unlink($path);
            }
            throw $e;
        }
        return [$fields, $files, $tmpFiles];
    }

    private function boundary(string $contentType): string
    {
        if (preg_match('#^multipart/form-data\s*;.*?\bboundary=(?:"([^"]{1,70})"|([^;\s"]{1,70}))#i', $contentType, $m) !== 1) {
            throw new \InvalidArgumentException('Chybí hranice multipart těla.', 400);
        }
        return $m[1] !== '' ? $m[1] : $m[2];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $files
     * @param list<string> $tmpFiles
     */
    private function part(string $part, string $tmpDir, array &$fields, array &$files, array &$tmpFiles): void
    {
        $split = strpos($part, "\r\n\r\n");
        if ($split === false || $split > self::MAX_HEADER_BYTES) {
            throw new \InvalidArgumentException('Neplatná hlavička části formuláře.', 400);
        }
        $headers = [];
        foreach (explode("\r\n", substr($part, 0, $split)) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new \InvalidArgumentException('Neplatná hlavička části formuláře.', 400);
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
        $content = substr($part, $split + 4);

        $disposition = $headers['content-disposition'] ?? '';
        if (preg_match('/^form-data\s*;/i', $disposition) !== 1
            || preg_match('/;\s*name="([^"]*)"/i', $disposition, $nameMatch) !== 1) {
            throw new \InvalidArgumentException('Část formuláře nemá jméno.', 400);
        }
        if (preg_match('/^([A-Za-z0-9_-]{1,64})(\[\])?$/D', $nameMatch[1], $name) !== 1) {
            throw new \InvalidArgumentException('Neplatné jméno pole formuláře.', 400);
        }
        $key = $name[1];
        $isList = isset($name[2]) && $name[2] === '[]';

        if (preg_match('/;\s*filename="([^"]*)"/i', $disposition, $fileMatch) !== 1) {
            if (strlen($content) > self::MAX_FIELD_BYTES) {
                throw new \InvalidArgumentException('Hodnota pole formuláře je příliš dlouhá.', 400);
            }
            $this->put($fields, $key, $isList, $content);
            return;
        }

        $filename = McpFileLimits::sanitizeFilename($fileMatch[1]);
        if ($filename === null) {
            throw new \InvalidArgumentException('Neplatný název souboru.', 400);
        }
        $type = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
        if (!in_array($type, McpFileLimits::ALLOWED_UPLOAD_TYPES, true)) {
            throw new \InvalidArgumentException('Typ souboru „' . $type . '" nelze přes MCP nahrát.', 415);
        }
        $size = strlen($content);
        if ($size === 0) {
            throw new \InvalidArgumentException('Soubor je prázdný.', 400);
        }
        if ($size > McpFileLimits::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Soubor je příliš velký pro přenos přes MCP.', 413);
        }

        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Dočasný adresář pro nahrání nelze vytvořit.');
        }
        $path = rtrim($tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'mcp-' . bin2hex(random_bytes(12));
        if (@file_put_contents($path, $content) !== $size) {
            @unlink($path);
            throw new \RuntimeException('Nahraný soubor nelze uložit do dočasného adresáře.');
        }
        $tmpFiles[] = $path;
        $this->put($files, $key, $isList, new UploadedFile($path, $filename, $type, $size, UPLOAD_ERR_OK));
    }

    /** @param array<string, mixed> $target */
    private function put(array &$target, string $key, bool $isList, mixed $value): void
    {
        if ($isList) {
            if (isset($target[$key]) && !is_array($target[$key])) {
                throw new \InvalidArgumentException('Pole formuláře „' . $key . '" je zadané dvakrát.', 400);
            }
            $target[$key][] = $value;
            return;
        }
        if (array_key_exists($key, $target)) {
            throw new \InvalidArgumentException('Pole formuláře „' . $key . '" je zadané dvakrát.', 400);
        }
        $target[$key] = $value;
    }
}
