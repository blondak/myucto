<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundUrlGuard;

/**
 * Stažení exportu objednávek z trvalého odkazu Shoptetu.
 *
 * Pravidla Shoptetu (podpora.shoptet.cz/export-objednavek/): kdo stahuje častěji než
 * jednou za 15 minut, smí jen s parametrem `updateTimeFrom`. MyÚčto proto:
 *   - první (plné) stažení bez kurzoru pustí nejvýš jednou za 15 minut,
 *   - každé další stahuje s `updateTimeFrom` = čas posledního úspěšného stažení
 *     minus překryv (duplicitu pohltí idempotence importu).
 *
 * Síť: jen https, přes {@see OutboundUrlGuard} (DNS se ověří a spojení jde jen na
 * veřejné IP, žádné loopback ani privátní rozsahy), limit velikosti a timeout.
 * Přesměrování se následuje nejvýš třikrát a každý cíl se ověřuje znovu.
 *
 * Odkaz je tajemství (hash partnera), proto se nikdy neobjeví v hlášce ani v logu.
 */
final class ShoptetOrderFetcher
{
    public const FULL_FETCH_MIN_INTERVAL_SECONDS = 15 * 60;
    public const CURSOR_OVERLAP_SECONDS = 120;
    private const TIMEOUT = 60;
    private const MAX_REDIRECTS = 3;

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * @param array<string,mixed> $settings řádek shoptet_settings
     * @return array{content:string, fetched_at:\DateTimeImmutable, update_time_from:?\DateTimeImmutable, full:bool}
     */
    public function fetch(string $url, array $settings, \DateTimeImmutable $now): array
    {
        $cursor = ($settings['fetch_cursor'] ?? null) !== null
            ? new \DateTimeImmutable((string) $settings['fetch_cursor'])
            : null;
        if ($cursor === null) {
            $lastFull = ($settings['last_full_fetch_at'] ?? null) !== null
                ? new \DateTimeImmutable((string) $settings['last_full_fetch_at'])
                : null;
            if ($lastFull !== null && $now->getTimestamp() - $lastFull->getTimestamp() < self::FULL_FETCH_MIN_INTERVAL_SECONDS) {
                throw new ShoptetImportException(
                    'shoptet_fetch_too_soon',
                    'Úplný export objednávek smí Shoptet vydat nejvýš jednou za 15 minut. Zkuste to později.',
                    429,
                );
            }
        }

        $target = ShoptetOrderUrl::withUpdateTimeFrom($url, $cursor);
        $content = $this->download($target);

        return ['content' => $content, 'fetched_at' => $now, 'update_time_from' => $cursor, 'full' => $cursor === null];
    }

    /** Nový kurzor po úspěšném zpracování stažení. */
    public static function nextCursor(\DateTimeImmutable $fetchedAt): \DateTimeImmutable
    {
        return $fetchedAt->modify('-' . self::CURSOR_OVERLAP_SECONDS . ' seconds');
    }

    private function download(string $url): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = $this->guard->request(
                    'GET',
                    $url,
                    ['Accept' => 'application/xml, text/xml, text/csv, */*', 'User-Agent' => 'MyUcto-Shoptet/1.0'],
                    null,
                    [],
                    self::TIMEOUT,
                    ShoptetOrderFileParser::MAX_BYTES,
                );
            } catch (OutboundRequestException $e) {
                throw new ShoptetImportException(
                    'shoptet_fetch_failed',
                    'Export objednávek se nepodařilo stáhnout: ' . self::reason($e) . '.',
                    502,
                );
            }
            if ($response->status >= 300 && $response->status < 400) {
                $location = (string) ($response->header('location') ?? '');
                $next = $location !== '' ? self::resolve($url, $location) : null;
                if ($next === null) {
                    break;
                }
                $url = $next;
                continue;
            }
            if ($response->status !== 200) {
                throw new ShoptetImportException(
                    'shoptet_fetch_failed',
                    sprintf(
                        'Shoptet odpověděl stavem HTTP %d. Zkontrolujte odkaz a povolenou IP adresu v zabezpečení exportů.',
                        $response->status,
                    ),
                    502,
                );
            }

            return $response->body;
        }

        throw new ShoptetImportException('shoptet_fetch_failed', 'Shoptet přesměroval stahování příliš mnohokrát nebo mimo https.', 502);
    }

    private static function resolve(string $base, string $location): ?string
    {
        if (preg_match('#^https://#i', $location)) {
            return $location;
        }
        if (str_starts_with($location, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return null;
        }
        $parts = parse_url($base) ?: [];
        $origin = 'https://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname((string) ($parts['path'] ?? '/')), '/');

        return $origin . $dir . '/' . $location;
    }

    /** Bezpečný popis chyby bez URL. */
    private static function reason(OutboundRequestException $e): string
    {
        return match ($e->reason) {
            OutboundRequestException::SIZE_LIMIT => 'odpověď je větší než 20 MB',
            OutboundRequestException::TARGET_BLOCKED => 'cílová adresa není povolená (jen veřejné https adresy)',
            OutboundRequestException::DNS_UNAVAILABLE => 'adresu e-shopu se nepodařilo přeložit',
            default => 'spojení selhalo nebo adresa není povolená',
        };
    }
}
