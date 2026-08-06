<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Referenční kurzy ECB pro přepočet do měny OSS podání — fetch + cache + „nejbližší
 * následující den".
 *
 * ── Proč vlastní klient vedle {@see CnbExchangeRateClient} ──────────────────────────
 * ČNB je správný kurz pro TUZEMSKÝ základ daně (§ 4 odst. 8 ZDPH). Přepočet do měny OSS
 * podání je ale jiná otázka s jiným pravidlem: Finanční správa k režimu EU uvádí, že
 * „pro přepočet u plnění v jiné měně než euro se použije směnný kurz Evropské centrální
 * banky zveřejněný pro poslední den zdaňovacího období, nebo nejbližší následující den,
 * pokud pro poslední den zdaňovacího období není kurz zveřejněn" (shodně čl. 369h odst. 3
 * směrnice 2006/112/ES). Jde tedy o JINOU BANKU, JEDEN kurz na CELÉ období a určený DEN —
 * ne o denní kurz k datu plnění každého dokladu. Sdílet klienta s ČNB nejde ani technicky:
 * ECB publikuje opačnou orientaci kurzu (jednotky měny za 1 EUR).
 *
 * ── Feed ────────────────────────────────────────────────────────────────────────────
 * https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml (posledních 90 dní)
 * https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml     (od roku 1999)
 *
 *   <Cube><Cube time="2026-08-05"><Cube currency="CZK" rate="24.195"/>…
 *
 * Devadesátidenní soubor má ~70 kB a pokryje běžný případ (podání se sestavuje do měsíce
 * po konci kvartálu). Historický soubor je řádově megabajty a sáhne se po něm jen u starších
 * období — typicky při opravě minulého kvartálu.
 *
 * ── Cache a rozlišení „nezveřejněno" od „nestaženo" ─────────────────────────────────
 * Pravidlo „nejbližší následující den" se nedá vyhodnotit nad cachí, která umí jen říct
 * „pro tenhle den řádek nemám" — víkend a díra v cachi vypadají stejně a přepočet by tiše
 * použil pozdější den, než jaký zákon určuje. Proto se vedle kurzů ukládají i DNY
 * (`ecb_exchange_rate_days`, migrace 1299) s příznakem `published`. Den, který v tabulce
 * není, znamená „nevíme" a vynutí stažení feedu.
 *
 * Pokrytí feedu se bere z rozsahu dat, která feed sám obsahuje — dnešek před publikací
 * (ECB vydává kolem 16:00 SEČ) tedy zůstane „nevíme" a nezafixuje se jako nezveřejněný.
 *
 * ── Chybějící kurz se NEnahrazuje ───────────────────────────────────────────────────
 * Když kurz není (výpadek sítě, budoucí či právě končící období, demo režim), vrací se
 * `null`. Tichý fallback na ČNB nebo na starší den by dal číslo, které vypadá hotově,
 * ale do podání nepatří — a rozdíl by se našel až při kontrole správce daně. Volající
 * ({@see \MyInvoice\Service\Oss\OssLedgerService}) na `null` odpovídá varováním
 * a export XML takové období do podání nepustí.
 */
final class EcbExchangeRateClient
{
    /**
     * Kolik dní dopředu se hledá „nejbližší následující den" publikace. ECB nepublikuje
     * o víkendech a o svátcích TARGET; nejdelší souvislá mezera je velikonoční
     * pátek–pondělí a přelom roku, tedy do čtyř dnů. Deset je rezerva — ne pravidlo:
     * kdyby se hledalo bez omezení, „nejbližší následující" by u nedostupného feedu
     * spolklo libovolně vzdálený kurz.
     */
    public const MAX_FORWARD_DAYS = 10;

    private const FEED_90D = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';
    private const FEED_HIST = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml';

    /** Od kolika dnů zpět se sahá po historickém feedu místo devadesátidenního. */
    private const RECENT_DAYS = 60;

    private const TIMEOUT_SEC = 20;

    /** @var array<string, array{rates:array<string,float>, rate_date:string, days_forward:int}|null> */
    private array $memo = [];

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Kurzy zveřejněné pro poslední den zdaňovacího období, případně pro nejbližší
     * následující den publikace.
     *
     * Vrací celou sadu měn jednoho kurzového dne, ne jeden pár: období může obsahovat
     * doklady ve víc měnách a všechny se musí přepočíst TÝMŽ dnem. `EUR => 1.0` je
     * v sadě doplněno, aby přepočet nemusel řešit, že ECB samo euro nekótuje.
     *
     * @return array{rates:array<string,float>, rate_date:string, days_forward:int}|null
     *         `rates` = kolik jednotek měny za 1 EUR; `null` = kurz není k dispozici
     */
    public function ratesForPeriodEnd(DateTimeImmutable $periodEnd): ?array
    {
        $end = $periodEnd->setTime(0, 0);
        $key = $end->format('Y-m-d');
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->resolve($end);
    }

    /**
     * Kurz období pro jednu dvojici měn v orientaci, ve které ho čte i ruční pole na
     * položce (`invoice_items.oss_exchange_rate`): kolik jednotek CÍLOVÉ měny za
     * 1 jednotku měny ZDROJOVÉ. `null` = jednu z měn ECB nekótuje.
     *
     * @param array<string,float> $ratesPerEur
     */
    public static function crossRate(array $ratesPerEur, string $source, string $target): ?float
    {
        $from = self::unitRate($ratesPerEur, $source);
        $to = self::unitRate($ratesPerEur, $target);
        if ($from === null || $to === null) {
            return null;
        }

        return $to / $from;
    }

    /**
     * Částka × kurz, HALF_UP na dvě desetinná místa.
     *
     * Vzorec zaokrouhlení je TÝŽ jako u {@see \MyInvoice\Service\Invoice\CzkRecap::multiplyHalfUp()}
     * (bump ±0,005 a `bcadd` se scale 2, tedy truncate) — obě částky míří do podání, takže
     * se v zaokrouhlování nesmí rozejít. Liší se jedině PŘESNOST KURZU: `CzkRecap` formátuje
     * oba operandy na šest desetinných míst, což u ČNB (kurz na tři desetinná místa) nevadí,
     * ale křížový kurz CZK→EUR je 0,0413…, takže šest míst uřízne relativní rozdíl ~2e-6 —
     * na čtvrtletním základu v řádu milionů korun jsou to koruny navíc v podané částce.
     * Kurz proto vstupuje na deset desetinných míst.
     */
    public static function applyRate(float $amount, float $rate): float
    {
        if (function_exists('bcmul')) {
            $product = bcmul(sprintf('%.6F', $amount), sprintf('%.10F', $rate), 16);
            $bump = str_starts_with($product, '-') ? '-0.005' : '0.005';

            return (float) bcadd($product, $bump, 2);
        }

        return round($amount * $rate, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * Pure parser — testovatelný bez sítě. Vrací mapu `datum => [kód => kurz za 1 EUR]`.
     *
     * Feed je jeden velký strom `<Cube>` bez pořadové záruky, proto se čte streamově:
     * historický soubor má několik megabajtů a načíst ho celý do DOM by u importu
     * s opravami starých období zbytečně žralo paměť.
     *
     * @return array<string, array<string,float>>
     */
    public static function parse(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        $reader = new \XMLReader();
        // LIBXML_NONET: feed je statický soubor, žádné externí entity se dotahovat nesmí.
        if (@$reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) !== true) {
            return [];
        }

        $out = [];
        $currentDate = null;
        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Cube') {
                    continue;
                }

                $time = $reader->getAttribute('time');
                if ($time !== null) {
                    $currentDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($time)) === 1 ? trim($time) : null;
                    if ($currentDate !== null) {
                        // Den bez jediné měny je pořád DEN — prázdné pole je platná odpověď
                        // „ECB tenhle den zveřejnila" a nesmí se ztratit.
                        $out[$currentDate] ??= [];
                    }
                    continue;
                }

                $code = $reader->getAttribute('currency');
                $rate = $reader->getAttribute('rate');
                if ($currentDate === null || $code === null || $rate === null) {
                    continue;
                }
                $code = strtoupper(trim($code));
                $value = (float) trim($rate);
                if ($code === '' || $value <= 0.0) {
                    continue;
                }
                $out[$currentDate][$code] = $value;
            }
        } finally {
            $reader->close();
        }

        return $out;
    }

    /** @return array{rates:array<string,float>, rate_date:string, days_forward:int}|null */
    private function resolve(DateTimeImmutable $end): ?array
    {
        if (!$this->hasSchema()) {
            return null;
        }

        $hit = $this->fromCache($end);
        if ($hit !== null) {
            return $hit;
        }

        // Budoucí (i právě končící) období: kurz ještě neexistuje, není o co žádat.
        // Odchozí HTTP by stejně vrátilo starší den a ten do podání nepatří.
        if ($end->format('Y-m-d') > $this->today()) {
            return null;
        }
        if ($this->isDemo()) {
            return null;
        }
        if (!$this->refresh($end)) {
            return null;
        }

        return $this->fromCache($end);
    }

    /**
     * Cache-only vyhodnocení pravidla „poslední den období, jinak nejbližší následující".
     * `null` = o některém dni okna cache nic neví, takže se odpověď nedá dát bez stažení.
     *
     * @return array{rates:array<string,float>, rate_date:string, days_forward:int}|null
     */
    private function fromCache(DateTimeImmutable $end): ?array
    {
        $dates = [];
        for ($i = 0; $i <= self::MAX_FORWARD_DAYS; $i++) {
            $dates[] = $end->modify('+' . $i . ' day')->format('Y-m-d');
        }

        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT rate_date, currency_code, units_per_eur
               FROM ecb_exchange_rates
              WHERE rate_date IN ($placeholders)"
        );
        $stmt->execute($dates);
        $byDate = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byDate[(string) $row['rate_date']][strtoupper((string) $row['currency_code'])]
                = (float) $row['units_per_eur'];
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT rate_date FROM ecb_exchange_rate_days
              WHERE published = 0 AND rate_date IN ($placeholders)"
        );
        $stmt->execute($dates);
        $unpublished = array_flip(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));

        foreach ($dates as $offset => $date) {
            if (isset($byDate[$date]) && $byDate[$date] !== []) {
                return [
                    // ECB samo euro nekótuje — bez téhle jedničky by přepočet do EUR
                    // (tj. naprostá většina OSS podání) neměl cílový kurz.
                    'rates' => ['EUR' => 1.0] + $byDate[$date],
                    'rate_date' => $date,
                    'days_forward' => $offset,
                ];
            }
            if (!isset($unpublished[$date])) {
                return null;
            }
        }

        return null;
    }

    /** Stáhne a uloží okno kolem konce období. `false` = feed nedal použitelnou odpověď. */
    private function refresh(DateTimeImmutable $end): bool
    {
        $recent = $end->format('Y-m-d')
            >= (new DateTimeImmutable($this->today()))->modify('-' . self::RECENT_DAYS . ' day')->format('Y-m-d');
        $body = $this->fetch($recent ? self::FEED_90D : self::FEED_HIST);
        if ($body === null) {
            return false;
        }

        $parsed = self::parse($body);
        if ($parsed === []) {
            $this->logger->warning('ECB feed se nepodařilo rozparsovat', ['period_end' => $end->format('Y-m-d')]);
            return false;
        }

        $days = array_keys($parsed);
        $coverageFrom = min($days);
        $coverageTo = max($days);

        $pdo = $this->db->pdo();
        $rateStmt = $pdo->prepare(
            'INSERT INTO ecb_exchange_rates (rate_date, currency_code, units_per_eur) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE units_per_eur = VALUES(units_per_eur), fetched_at = NOW()'
        );
        $dayStmt = $pdo->prepare(
            'INSERT INTO ecb_exchange_rate_days (rate_date, published) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE published = VALUES(published), fetched_at = NOW()'
        );

        $stored = false;
        for ($i = 0; $i <= self::MAX_FORWARD_DAYS; $i++) {
            $date = $end->modify('+' . $i . ' day')->format('Y-m-d');
            // Mimo rozsah, který feed sám pokrývá, se NIC neznačí. Kdyby se den za koncem
            // feedu označil jako nezveřejněný, zafixovala by se tím nevědomost: dnešek před
            // publikací (ECB vydává kolem 16:00 SEČ) by se už nikdy nedotáhl.
            if ($date < $coverageFrom || $date > $coverageTo) {
                continue;
            }

            $rates = $parsed[$date] ?? [];
            $dayStmt->execute([$date, $rates === [] ? 0 : 1]);
            foreach ($rates as $code => $value) {
                $rateStmt->execute([$date, $code, $value]);
            }
            $stored = true;
        }

        return $stored;
    }

    private function fetch(string $url): ?string
    {
        try {
            $client = new Client([
                'timeout' => self::TIMEOUT_SEC,
                'connect_timeout' => self::TIMEOUT_SEC,
            ]);
            $resp = $client->get($url, [
                'http_errors' => false,
                'headers' => ['Accept' => 'application/xml'],
            ]);
            if ($resp->getStatusCode() !== 200) {
                $this->logger->warning('ECB feed neočekávaný status', [
                    'url' => $url,
                    'status' => $resp->getStatusCode(),
                ]);
                return null;
            }

            return (string) $resp->getBody();
        } catch (GuzzleException $e) {
            $this->logger->warning('ECB feed nedostupný: ' . $e->getMessage(), ['url' => $url]);
            return null;
        }
    }

    /** @param array<string,float> $ratesPerEur */
    private static function unitRate(array $ratesPerEur, string $currency): ?float
    {
        $code = strtoupper(trim($currency));
        if ($code === 'EUR') {
            return 1.0;
        }
        $rate = $ratesPerEur[$code] ?? null;

        return $rate !== null && $rate > 0.0 ? (float) $rate : null;
    }

    /**
     * Dnešek jako 'Y-m-d'. Schválně řetězec: `ClockInterface` může vracet UTC, kdežto
     * konec období vzniká z DATA v DB bez časové zóny — porovnávat je jako okamžiky by
     * na hranici půlnoci posouvalo o den.
     */
    private function today(): string
    {
        return $this->clock->now()->format('Y-m-d');
    }

    private function hasSchema(): bool
    {
        return $this->db->hasTable('ecb_exchange_rates') && $this->db->hasTable('ecb_exchange_rate_days');
    }

    private function isDemo(): bool
    {
        return (bool) $this->config->get('demo.enabled', false);
    }
}
