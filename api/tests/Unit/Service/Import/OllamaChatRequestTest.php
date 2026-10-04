<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\OllamaChatRequest;
use PHPUnit\Framework\TestCase;

final class OllamaChatRequestTest extends TestCase
{
    public function testHybridPayload(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];
        $p = OllamaChatRequest::build('vision-model:7b', 'SYS', 'Vytáhni data.', 'VS 2026001', ['PNG1', 'PNG2'], $schema, ['think' => false]);

        self::assertSame('vision-model:7b', $p['model']);
        self::assertFalse($p['stream']);
        self::assertSame($schema, $p['format']);
        self::assertFalse($p['think']);
        self::assertSame(['num_ctx' => 32768, 'temperature' => 0], $p['options']);
        self::assertSame(['role' => 'system', 'content' => 'SYS'], $p['messages'][0]);
        $user = $p['messages'][1];
        self::assertSame('user', $user['role']);
        self::assertStringStartsWith('Vytáhni data.', $user['content']);
        self::assertStringContainsString(OllamaChatRequest::TEXT_HEADER . "\nVS 2026001", $user['content']);
        self::assertSame([base64_encode('PNG1'), base64_encode('PNG2')], $user['images']);
    }

    public function testTextOnlyHasNoImagesKey(): void
    {
        $p = OllamaChatRequest::build('m', 'S', 'U', 'text', [], 'json');
        self::assertArrayNotHasKey('images', $p['messages'][1]);
        self::assertSame('json', $p['format']);
        self::assertArrayNotHasKey('think', $p);
    }

    public function testImagesOnlyHasNoTextBlock(): void
    {
        $p = OllamaChatRequest::build('m', 'S', 'U', "  \n ", ['PNG'], null);
        self::assertSame('U', $p['messages'][1]['content']);
        self::assertArrayNotHasKey('format', $p);
    }

    public function testLongTextIsTruncatedByCharacters(): void
    {
        $p = OllamaChatRequest::build('m', 'S', 'U', str_repeat('ř', 25000), [], null);
        $text = substr($p['messages'][1]['content'], strlen('U' . "\n\n" . OllamaChatRequest::TEXT_HEADER . "\n"));
        self::assertSame(OllamaChatRequest::MAX_TEXT_CHARS, mb_strlen($text));
    }

    public function testNumPredict(): void
    {
        $p = OllamaChatRequest::build('m', 'S', 'U', null, [], null, [], 500);
        self::assertSame(500, $p['options']['num_predict']);
    }

    public function testInvalidUtf8IsScrubbedSoJsonEncodeSucceeds(): void
    {
        $p = OllamaChatRequest::build('m', "S\xff", "U\xfe", "VS 2026001 \xC3\x28 konec", [], null);
        self::assertTrue(mb_check_encoding($p['messages'][1]['content'], 'UTF-8'));
        self::assertStringContainsString('2026001', $p['messages'][1]['content']);
        self::assertNotFalse(json_encode($p));
    }

    /** Model po pauze nesmí Ollama hned uvolnit — cold start sežere většinu časového limitu. */
    public function testKeepAliveHoldsModelLoaded(): void
    {
        $p = OllamaChatRequest::build('m', 'S', 'U', 'text', [], 'json');
        self::assertSame('30m', $p['keep_alive']);
        self::assertSame(OllamaChatRequest::KEEP_ALIVE, $p['keep_alive']);
    }

    public function testExtraCannotOverrideCoreKeys(): void
    {
        $extra = [
            'model' => 'evil', 'messages' => [], 'stream' => true, 'options' => ['num_ctx' => 1],
            'format' => 'evil', 'keep_alive' => 0, 'think' => 'low',
        ];
        $schema = ['type' => 'object'];
        $p = OllamaChatRequest::build('m', 'S', 'U', 'text', [], $schema, $extra);
        self::assertSame('m', $p['model']);
        self::assertCount(2, $p['messages']);
        self::assertFalse($p['stream']);
        self::assertSame(['num_ctx' => OllamaChatRequest::NUM_CTX, 'temperature' => 0], $p['options']);
        self::assertSame($schema, $p['format']);
        self::assertSame(OllamaChatRequest::KEEP_ALIVE, $p['keep_alive']);
        self::assertSame('low', $p['think'], 'nejádrový klíč z $extra projde');

        // Ani bez formátu nesmí $extra formát podstrčit.
        self::assertArrayNotHasKey('format', OllamaChatRequest::build('m', 'S', 'U', 'text', [], null, $extra));
    }
}
