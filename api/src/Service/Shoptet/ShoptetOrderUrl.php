<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

/**
 * Trvalý odkaz na export objednávek Shoptetu (podpora.shoptet.cz/export-objednavek/,
 * /zabezpeceni-exportu/): `https://<e-shop>/export/orders.xml?patternId=…&hash=…`.
 *
 * Při stahování častěji než jednou za 15 minut Shoptet vyžaduje parametr
 * `updateTimeFrom=YYYY-MM-DD HH:MM:SS`. Ten si MyÚčto připojuje samo, proto se
 * z uloženého odkazu vždycky odstraní.
 */
final class ShoptetOrderUrl
{
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000) {
            throw new ShoptetImportException('shoptet_url_invalid', 'Odkaz je prázdný nebo příliš dlouhý.');
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === '') {
            throw new ShoptetImportException('shoptet_url_invalid', 'Odkaz musí začínat https:// a obsahovat adresu e-shopu.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ShoptetImportException('shoptet_url_invalid', 'Odkaz nesmí obsahovat přihlašovací údaje.');
        }
        // E-shop běží na standardním portu https. Jiný port je buď překlep, nebo pokus
        // nasměrovat stahování na službu, která na veřejné adrese poslouchá jinde.
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new ShoptetImportException('shoptet_url_invalid', 'Odkaz smí mířit jen na standardní port https (443).');
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (!isset($query['hash']) || !is_string($query['hash']) || $query['hash'] === '') {
            throw new ShoptetImportException(
                'shoptet_url_invalid',
                'V odkazu chybí parametr hash. Zkopírujte celý trvalý odkaz z exportu objednávek v Shoptetu.'
            );
        }
        unset($query['updateTimeFrom']);

        return self::build($parts, $query);
    }

    public static function withUpdateTimeFrom(string $url, ?\DateTimeImmutable $from): string
    {
        $parts = parse_url($url) ?: [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['updateTimeFrom']);
        if ($from !== null) {
            $query['updateTimeFrom'] = $from->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d H:i:s');
        }

        return self::build($parts, $query);
    }

    /** Maskovaná podoba do UI: adresa e-shopu a cesta, hash jen poslední 4 znaky. */
    public static function mask(string $url): string
    {
        $parts = parse_url($url) ?: [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $hash = is_string($query['hash'] ?? null) ? (string) $query['hash'] : '';

        return sprintf(
            'https://%s%s?…hash=…%s',
            (string) ($parts['host'] ?? ''),
            (string) ($parts['path'] ?? ''),
            substr($hash, -4),
        );
    }

    /** @param array<string,mixed> $parts @param array<string,mixed> $query */
    private static function build(array $parts, array $query): string
    {
        // Port se nepřenáší: normalize() pustí jen 443, tedy výchozí port https.
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return 'https://' . strtolower((string) $parts['host']) . (string) ($parts['path'] ?? '/')
            . ($qs !== '' ? '?' . $qs : '');
    }
}
