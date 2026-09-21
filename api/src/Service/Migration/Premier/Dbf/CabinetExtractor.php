<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier\Dbf;

use MyInvoice\Service\Migration\Premier\PremierException;

/**
 * Rozbalení archivu Microsoft Cabinet (`.icab`, záloha PREMIER „Záloha dat" ve formátu CAB).
 *
 * Čistě v PHP, bez `expand.exe` / `cabextract` (v Docker image nejsou). Podporuje
 * nekomprimované složky a kompresi MSZIP - tu PREMIER používá. MSZIP je deflate po
 * blocích dat (CFDATA) do 32 KB se signaturou `CK`; každý blok je samostatný deflate
 * proud, který ale smí odkazovat do předchozích 32 KB rozbalených dat téže složky
 * (zlib: slovník). LZX a Quantum převod neumí - takovou zálohu je potřeba uložit jako iZIP.
 */
final class CabinetExtractor
{
    private const SIGNATURE = 'MSCF';
    private const FLAG_PREV = 0x0001;
    private const FLAG_NEXT = 0x0002;
    private const FLAG_RESERVE = 0x0004;
    private const COMPRESSION_NONE = 0;
    private const COMPRESSION_MSZIP = 1;
    private const WINDOW = 32768;

    /** @var resource */
    private $handle;

    /** @var list<array{offset:int,blocks:int,compression:int}> */
    private array $folders = [];

    /** @var list<array{name:string,size:int,offset:int,folder:int}> */
    private array $files = [];

    private int $dataReserve = 0;

    public function __construct(string $path)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new PremierException('backup_unreadable', 'Zálohu nejde otevřít.');
        }
        $this->handle = $handle;
        $this->readDirectory();
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public static function isCabinet(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return false;
        }
        $sig = (string) fread($h, 4);
        fclose($h);
        return $sig === self::SIGNATURE;
    }

    /** @return list<array{name:string,size:int}> */
    public function entries(): array
    {
        return array_map(static fn (array $f): array => ['name' => $f['name'], 'size' => $f['size']], $this->files);
    }

    /**
     * Rozbalí vybrané soubory (`$accept` rozhoduje podle jména) do `$targetDir` pod
     * jménem, které vrátí `$accept`. Soubory téže složky se rozbalují jedním průchodem,
     * protože MSZIP blok může patřit dvěma souborům.
     *
     * @param callable(string):?string $accept jméno v archivu → cílové jméno, `null` = přeskočit
     * @return int počet zapsaných bajtů
     */
    public function extract(string $targetDir, callable $accept, int $maxBytes): int
    {
        $written = 0;
        foreach ($this->folders as $folderIndex => $folder) {
            $members = array_values(array_filter($this->files, static fn (array $f): bool => $f['folder'] === $folderIndex));
            $wanted = [];
            foreach ($members as $f) {
                $target = $accept($f['name']);
                if ($target !== null) {
                    $wanted[] = $f + ['target' => $target];
                }
            }
            if ($wanted === []) {
                continue;
            }
            usort($wanted, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);
            $end = max(array_map(static fn (array $f): int => $f['offset'] + $f['size'], $wanted));
            if ($written + array_sum(array_column($wanted, 'size')) > $maxBytes) {
                throw new PremierException('backup_too_large', 'Rozbalená záloha by byla příliš velká.');
            }
            $open = [];
            $position = 0;
            foreach ($this->folderData($folder, $end) as $chunk) {
                $chunkEnd = $position + strlen($chunk);
                foreach ($wanted as $i => $f) {
                    $from = max($f['offset'], $position);
                    $to = min($f['offset'] + $f['size'], $chunkEnd);
                    if ($from >= $to) {
                        continue;
                    }
                    if (!isset($open[$i])) {
                        $h = @fopen($targetDir . DIRECTORY_SEPARATOR . $f['target'], 'wb');
                        if ($h === false) {
                            throw new PremierException('storage_not_writable', 'Zálohu nejde rozbalit do úložiště.', [], 500);
                        }
                        $open[$i] = $h;
                    }
                    fwrite($open[$i], substr($chunk, $from - $position, $to - $from));
                    $written += $to - $from;
                }
                $position = $chunkEnd;
                if ($position >= $end) {
                    break;
                }
            }
            foreach ($wanted as $i => $f) {
                if (!isset($open[$i])) {
                    // Prázdný soubor (tabulka bez záznamů má aspoň hlavičku, ale pro jistotu).
                    $open[$i] = fopen($targetDir . DIRECTORY_SEPARATOR . $f['target'], 'wb');
                }
                fclose($open[$i]);
            }
            if ($position < $end) {
                throw new PremierException('backup_corrupted', 'Záloha je poškozená (data končí dřív, než soubory v ní).');
            }
        }
        return $written;
    }

    private function readDirectory(): void
    {
        $h = $this->read(36);
        if (substr($h, 0, 4) !== self::SIGNATURE) {
            throw new PremierException('backup_not_cab', 'Soubor není archiv CAB.');
        }
        $filesOffset = (int) unpack('V', substr($h, 16, 4))[1];
        $folderCount = (int) unpack('v', substr($h, 26, 2))[1];
        $fileCount = (int) unpack('v', substr($h, 28, 2))[1];
        $flags = (int) unpack('v', substr($h, 30, 2))[1];
        if (($flags & (self::FLAG_PREV | self::FLAG_NEXT)) !== 0) {
            throw new PremierException('backup_multipart', 'Záloha je rozdělená do více souborů CAB. Uložte ji v PREMIER jako jeden soubor (iZIP).');
        }
        $folderReserve = 0;
        if (($flags & self::FLAG_RESERVE) !== 0) {
            $r = $this->read(4);
            $headerReserve = (int) unpack('v', substr($r, 0, 2))[1];
            $folderReserve = ord($r[2]);
            $this->dataReserve = ord($r[3]);
            if ($headerReserve > 0) {
                $this->read($headerReserve);
            }
        }
        for ($i = 0; $i < $folderCount; $i++) {
            $f = $this->read(8 + $folderReserve);
            $this->folders[] = [
                'offset' => (int) unpack('V', substr($f, 0, 4))[1],
                'blocks' => (int) unpack('v', substr($f, 4, 2))[1],
                'compression' => (int) unpack('v', substr($f, 6, 2))[1] & 0x000F,
            ];
        }
        fseek($this->handle, $filesOffset);
        for ($i = 0; $i < $fileCount; $i++) {
            $f = $this->read(16);
            $name = '';
            while (($c = fgetc($this->handle)) !== false && $c !== "\0") {
                $name .= $c;
                if (strlen($name) > 255) {
                    throw new PremierException('backup_corrupted', 'Záloha je poškozená (neplatné jméno souboru).');
                }
            }
            $folder = (int) unpack('v', substr($f, 8, 2))[1];
            if ($folder >= 0xFFFD) {
                throw new PremierException('backup_multipart', 'Záloha je rozdělená do více souborů CAB. Uložte ji v PREMIER jako jeden soubor (iZIP).');
            }
            $this->files[] = [
                'name' => str_replace('\\', '/', $name),
                'size' => (int) unpack('V', substr($f, 0, 4))[1],
                'offset' => (int) unpack('V', substr($f, 4, 4))[1],
                'folder' => $folder,
            ];
        }
        foreach ($this->folders as $folder) {
            if (!in_array($folder['compression'], [self::COMPRESSION_NONE, self::COMPRESSION_MSZIP], true)) {
                throw new PremierException('backup_compression', 'Záloha CAB používá kompresi, kterou převod nepodporuje. Uložte zálohu v PREMIER ve formátu iZIP.');
            }
        }
    }

    /**
     * Rozbalená data složky po blocích.
     *
     * @param array{offset:int,blocks:int,compression:int} $folder
     * @return \Generator<int,string>
     */
    private function folderData(array $folder, int $needed): \Generator
    {
        fseek($this->handle, $folder['offset']);
        $history = '';
        $produced = 0;
        for ($b = 0; $b < $folder['blocks'] && $produced < $needed; $b++) {
            $head = $this->read(8 + $this->dataReserve);
            $compressedSize = (int) unpack('v', substr($head, 4, 2))[1];
            $uncompressedSize = (int) unpack('v', substr($head, 6, 2))[1];
            $data = $this->read($compressedSize);
            if ($folder['compression'] === self::COMPRESSION_NONE) {
                $out = $data;
            } else {
                if (substr($data, 0, 2) !== 'CK') {
                    throw new PremierException('backup_corrupted', 'Záloha je poškozená (blok MSZIP bez signatury).');
                }
                $options = $history !== '' ? ['dictionary' => $history] : [];
                $ctx = inflate_init(ZLIB_ENCODING_RAW, $options);
                $out = $ctx !== false ? @inflate_add($ctx, substr($data, 2), ZLIB_FINISH) : false;
                if ($out === false) {
                    throw new PremierException('backup_corrupted', 'Záloha je poškozená (blok MSZIP nejde rozbalit).');
                }
            }
            if (strlen($out) !== $uncompressedSize) {
                throw new PremierException('backup_corrupted', 'Záloha je poškozená (nesedí velikost rozbaleného bloku).');
            }
            $history = substr($history . $out, -self::WINDOW);
            $produced += strlen($out);
            yield $out;
        }
    }

    private function read(int $length): string
    {
        if ($length === 0) {
            return '';
        }
        // fread() smí vrátit méně bajtů (stream wrapper, síťový disk) - čte se do plné délky.
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->handle, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        if (strlen($data) !== $length) {
            throw new PremierException('backup_corrupted', 'Záloha je poškozená (neočekávaný konec souboru).');
        }
        return $data;
    }
}
