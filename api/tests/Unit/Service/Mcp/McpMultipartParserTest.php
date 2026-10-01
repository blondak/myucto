<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use MyInvoice\Service\Mcp\McpFileLimits;
use MyInvoice\Service\Mcp\McpMultipartParser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

final class McpMultipartParserTest extends TestCase
{
    private const BOUNDARY = '----MyUctoMcp0123456789abcdef';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-multipart-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testParsesFieldsAndBinaryFileIntact(): void
    {
        $pdf = "%PDF-1.7\r\n--\x00\xFF\x80 binární obsah";
        $body = $this->body([
            ['name="folder_id"', null, '4'],
            ['name="file"; filename="Smlouva nová.pdf"', 'application/pdf', $pdf],
        ]);

        [$fields, $files, $tmp] = (new McpMultipartParser())->parse($body, $this->type(), $this->tmpDir);

        self::assertSame(['folder_id' => '4'], $fields);
        self::assertInstanceOf(UploadedFileInterface::class, $files['file']);
        self::assertSame('Smlouva nová.pdf', $files['file']->getClientFilename());
        self::assertSame('application/pdf', $files['file']->getClientMediaType());
        self::assertSame(strlen($pdf), $files['file']->getSize());
        self::assertSame(UPLOAD_ERR_OK, $files['file']->getError());
        self::assertCount(1, $tmp);
        self::assertSame($pdf, file_get_contents($tmp[0]));

        $target = $this->tmpDir . DIRECTORY_SEPARATOR . 'moved.pdf';
        $files['file']->moveTo($target);
        self::assertSame($pdf, file_get_contents($target));
    }

    public function testListFieldsBecomeArrays(): void
    {
        $body = $this->body([
            ['name="file[]"; filename="a.pdf"', 'application/pdf', '%PDF-a'],
            ['name="file[]"; filename="b.png"', 'image/png', "\x89PNG"],
        ]);
        [, $files] = (new McpMultipartParser())->parse($body, $this->type(), $this->tmpDir);
        self::assertCount(2, $files['file']);
        self::assertSame('b.png', $files['file'][1]->getClientFilename());
    }

    public function testFilenameLosesPath(): void
    {
        $body = $this->body([['name="file"; filename="..\\..\\windows/system32/evil.pdf"', 'application/pdf', '%PDF-']]);
        [, $files] = (new McpMultipartParser())->parse($body, $this->type(), $this->tmpDir);
        self::assertSame('evil.pdf', $files['file']->getClientFilename());
    }

    public function testRejectsTypesTheBridgeNeverSends(): void
    {
        foreach (['text/html', 'image/svg+xml', 'application/octet-stream', 'application/x-php', ''] as $type) {
            $body = $this->body([['name="file"; filename="x.pdf"', $type === '' ? null : $type, '<script>']]);
            try {
                (new McpMultipartParser())->parse($body, $this->type(), $this->tmpDir);
                self::fail('Typ ' . $type . ' neměl projít.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(415, $e->getCode(), $type);
            }
        }
        self::assertSame([], glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    public function testExtensionMustMatchDeclaredType(): void
    {
        $rejected = [
            ['x.html', 'text/plain'],
            ['x.svg', 'image/png'],
            ['faktura.pdf', 'image/png'],
            ['obrazek.png', 'application/pdf'],
            ['bez-pripony', 'application/pdf'],
            ['skript.php', 'text/plain'],
        ];
        foreach ($rejected as [$filename, $type]) {
            $body = $this->body([
                ['name="a"', null, '1'],
                ['name="file"; filename="' . $filename . '"', $type, 'obsah'],
            ]);
            try {
                (new McpMultipartParser())->parse($body, $this->type(), $this->tmpDir);
                self::fail($filename . ' jako ' . $type . ' neměl projít.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(415, $e->getCode(), $filename);
            }
        }
        self::assertSame([], glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') ?: []);

        $accepted = [['data.csv', 'text/plain'], ['FAKTURA.PDF', 'application/pdf'], ['doklad.isdocx', 'application/zip']];
        foreach ($accepted as [$filename, $type]) {
            $body = $this->body([['name="file"; filename="' . $filename . '"', $type, 'obsah']]);
            [, $files] = (new McpMultipartParser())->parse($body, $this->type(), $this->tmpDir);
            self::assertSame($filename, $files['file']->getClientFilename());
        }
    }

    public function testRejectsOversizedFileAndCleansUpEarlierParts(): void
    {
        $body = $this->body([
            ['name="file[]"; filename="a.pdf"', 'application/pdf', '%PDF-a'],
            ['name="file[]"; filename="b.pdf"', 'application/pdf', str_repeat('x', McpFileLimits::MAX_FILE_BYTES + 1)],
        ]);
        try {
            (new McpMultipartParser())->parse($body, $this->type(), $this->tmpDir);
            self::fail('Velký soubor neměl projít.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(413, $e->getCode());
        }
        self::assertSame([], glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    public function testRejectsMalformedBodies(): void
    {
        $cases = [
            'bez hranice' => [$this->body([['name="a"', null, '1']]), 'multipart/form-data'],
            'neukončené' => ['--' . self::BOUNDARY . "\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1", $this->type()],
            'bez jména' => [$this->body([['filename="a.pdf"', 'application/pdf', '%PDF-']]), $this->type()],
            'jméno s cestou' => [$this->body([['name="../file"', null, '1']]), $this->type()],
            'prázdný název' => [$this->body([['name="file"; filename="../"', 'application/pdf', '%PDF-']]), $this->type()],
            'prázdný soubor' => [$this->body([['name="file"; filename="a.pdf"', 'application/pdf', '']]), $this->type()],
            'pole dvakrát' => [$this->body([['name="a"', null, '1'], ['name="a"', null, '2']]), $this->type()],
        ];
        foreach ($cases as $label => [$body, $type]) {
            try {
                (new McpMultipartParser())->parse($body, $type, $this->tmpDir);
                self::fail($label . ': mělo selhat.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode(), $label . ': ' . $e->getMessage());
            }
        }
    }

    private function type(): string
    {
        return 'multipart/form-data; boundary=' . self::BOUNDARY;
    }

    /** @param list<array{0:string,1:?string,2:string}> $parts */
    private function body(array $parts): string
    {
        $body = '';
        foreach ($parts as [$disposition, $type, $content]) {
            $body .= '--' . self::BOUNDARY . "\r\nContent-Disposition: form-data; " . $disposition . "\r\n"
                . ($type !== null ? 'Content-Type: ' . $type . "\r\n" : '') . "\r\n" . $content . "\r\n";
        }
        return $body . '--' . self::BOUNDARY . "--\r\n";
    }
}
