<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Sink pro odpověď cizího serveru s tvrdým stropem na ROZBALENÁ data.
 * Při překročení stropu write() vrátí 0 (cURL přenos přeruší chybou zápisu) a zavolá
 * `$onOverflow` — volající tak pozná příčinu. Brání gzip bombě a nekonečnému tělu.
 */
final class CappedSinkStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;
    private int $written = 0;

    /** @param \Closure():void $onOverflow */
    public function __construct(private readonly int $maxBytes, private readonly \Closure $onOverflow)
    {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'w+'));
    }

    public function write(string $string): int
    {
        if ($this->written + strlen($string) > $this->maxBytes) {
            ($this->onOverflow)();
            return 0;
        }
        $this->written += strlen($string);
        return $this->stream->write($string);
    }
}
