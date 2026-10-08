<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlId;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlPassability;

/**
 * Jedna chyba z protokolu. Kód chyby je podle katalogu kontrol offsetovaný:
 * DIS = ID kontroly + 20000, cJMHZ = ID kontroly + 40000, takže se z něj dá ID
 * kontroly spočítat zpět. Kulaté 20000 a 40000 jsou v ukázkách obálkové
 * „Technická chyba" s detailem v textu, ne kontroly — ID proto nemají.
 *
 * Ostatní kódy jsou platformní (odmítnutí na vstupu, obálka, podpis, šifrování)
 * nebo kódy post DIS validace evidence ČSSZ s doloženou kontrolou, a musí být
 * v doloženém katalogu; neznámý kód je tvrdá chyba, ne „nezařazeno".
 */
final readonly class JmhzProtocolError
{
    private const DIS_OFFSET = 20_000;
    private const CJMHZ_OFFSET = 40_000;
    private const RANGE = 19_999;

    /**
     * Prefix, kterým ČSSZ v textu popisu uvádí propustnost KONKRÉTNÍ chyby
     * z protokolu. Propustnost není samostatný strukturovaný element — ČSSZ
     * to v oficiální diskuzi potvrdila — a náš připnutý katalog kontrol se
     * v čase mění (kontroly se ruší, suspendují, mění propustnost), takže
     * není spolehlivým zdrojem pravdy o konkrétním podání. Text protokolu je
     * skutečnost od protistrany a má přednost.
     */
    private const PASSABILITY_PREFIX
        = '/^\(\s*propustnost\s*:\s*(nepropustn[áa]|propustn[áa])\s*\)/iu';

    /** Doložené platformní kódy z pokynů (atribut 10018 „Důvod odmítnutí"). */
    private const PLATFORM_CODES = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 16, 17, 18, 19, 20, 21,
        22, 23, 24, 25, 26, 27, 61, 62, 63, 64,
        101, 102, 103, 104, 105, 201, 202, 300, 302, 305, 310, 400,
        17800, 17801, 17803, 17804, 17805, 17806, 17807, 17808, 17810, 17814,
        17820, 17824, 17830, 17832, 17833, 17835, 17836, 17837, 17839, 17840,
    ];

    /**
     * Kódy post DIS validace evidence ČSSZ, které Katalog kontrol MH 1.4.2.10
     * přiřazuje kontrole ve sloupci poznámek („post DIS validace - kód:
     * 103901608 (akce 99)"). Kontroly 262 (ID PPV nenalezeno) a 263 (IK MPSV
     * nenalezeno) jsou DIS a nepropustné; ČSSZ je hlásí buď v rozsahu DIS
     * (20262, 20263), nebo tímhle kódem evidence. Mapa je jediné místo, kde se
     * kód evidence převádí na kontrolu — čte ji parser i vysvětlení protokolu.
     *
     * @var array<int,int>
     */
    private const POST_DIS_VALIDATION_CONTROLS = [
        103_901_608 => 262,
        103_901_609 => 263,
    ];

    private function __construct(
        public int $code,
        public string $message,
        public JmhzProtocolErrorOrigin $origin,
        public ?JmhzControlId $controlId,
        public JmhzControlPassability $passability,
    ) {}

    public static function fromCode(int $code, string $message): self
    {
        $passability = self::derivePassability($message);

        if ($code === self::DIS_OFFSET) {
            return new self($code, $message, JmhzProtocolErrorOrigin::Dis, null, $passability);
        }
        if ($code === self::CJMHZ_OFFSET) {
            return new self($code, $message, JmhzProtocolErrorOrigin::Cjmhz, null, $passability);
        }
        if ($code > self::DIS_OFFSET && $code <= self::DIS_OFFSET + self::RANGE) {
            return new self(
                $code,
                $message,
                JmhzProtocolErrorOrigin::Dis,
                new JmhzControlId($code - self::DIS_OFFSET),
                $passability,
            );
        }
        if ($code > self::CJMHZ_OFFSET && $code <= self::CJMHZ_OFFSET + self::RANGE) {
            return new self(
                $code,
                $message,
                JmhzProtocolErrorOrigin::Cjmhz,
                new JmhzControlId($code - self::CJMHZ_OFFSET),
                $passability,
            );
        }
        if (in_array($code, self::PLATFORM_CODES, true)) {
            return new self(
                $code,
                $message,
                JmhzProtocolErrorOrigin::Platform,
                null,
                $passability,
            );
        }
        $postDisControl = self::postDisValidationControl($code);
        if ($postDisControl !== null) {
            return new self(
                $code,
                $message,
                JmhzProtocolErrorOrigin::Dis,
                $postDisControl,
                $passability,
            );
        }

        throw new JmhzTransportException(
            'jmhz_protocol_error_code_unknown',
            "Kód chyby {$code} není v doloženém katalogu ani v rozsahu kontrol DIS a cJMHZ.",
        );
    }

    /**
     * Chyba z protokolu k registraci zaměstnance (PREZEC, REGZEC).
     *
     * Registrační protokoly nemají připnutý katalog: nesou kódy ePodání ČSSZ
     * (`061`, `306`, `604`) i kódy post DIS validace evidence ČSSZ
     * (`103901602`, Katalog kontrol MH 1.4.2.10 je uvádí ve sloupci poznámek).
     * Na rozdíl od JMHZ o výsledku podání nerozhodují — ten nese `result`
     * formuláře (Interpretace protokolů REGZEC v1.0). Kód se proto jen
     * přenáší s textem a neodvozuje se z něj kontrola katalogu.
     */
    public static function fromRegistrationCode(int $code, string $message): self
    {
        if ($code <= 0) {
            throw new JmhzTransportException(
                'jmhz_protocol_error_message_unreadable',
                'Chyba registračního protokolu nemá kladný kód.',
            );
        }

        return new self(
            $code,
            $message,
            JmhzProtocolErrorOrigin::Platform,
            null,
            self::derivePassability($message),
        );
    }

    /**
     * Propustnost z textu popisu, ne z připnutého katalogu (viz komentář
     * u {@see self::PASSABILITY_PREFIX}). Jediné sdílené místo, kudy prochází
     * všechna volání {@see self::fromCode()} — parser sem posílá text popisu
     * beze změny.
     *
     * Záměrně fail-closed: co nejde rozpoznat (starší protokol prefix
     * neuvádí, neočekávaný tvar), je „neuvedeno", nikdy tiše „propustná".
     */
    private static function derivePassability(string $message): JmhzControlPassability
    {
        if (preg_match(self::PASSABILITY_PREFIX, $message, $matches) !== 1) {
            return JmhzControlPassability::Unspecified;
        }

        return stripos($matches[1], 'ne') === 0
            ? JmhzControlPassability::Blocking
            : JmhzControlPassability::Passable;
    }

    /** Kód chyby DIS kontroly 22 (katalog 1.4.2.10), duplicita GUID podání. */
    public const DUPLICATE_SUBMISSION_CODE = 20_022;

    /**
     * Chyba komunikace 103 (`RaisedBy` CSSZDIS): pověření k e-službě dané třídy
     * není zaznamenané v registru podávajících na OSSZ, nebo tam není
     * certifikát, kterým je podání podepsané. Podání se vůbec nezpracovalo,
     * vada je v registraci u OSSZ, ne v obsahu podání.
     */
    public const SERVICE_AUTHORIZATION_MISSING_CODE = 103;

    public function reportsMissingServiceAuthorization(): bool
    {
        return $this->code === self::SERVICE_AUTHORIZATION_MISSING_CODE;
    }

    /**
     * Kontrola 22 ve variantě 3 nebo 4: shodné řádné (GUID, VS, období a balík)
     * nebo stornovací podání už ČSSZ MÁ. Taková „chyba" neříká, že podání
     * neprošlo, ale že jeho originál u ČSSZ je; typicky po opakování odeslání,
     * na které se nevrátila odpověď.
     *
     * Varianty 1 a 2 (idPodani použité s jiným VS nebo za jiné období) jsou
     * skutečné chyby a sem nepatří. Rozlišují se jen textem hlášky, strukturovaný
     * kód varianty protokol nenese; katalog u nich píše „je již použito",
     * u shodného podání „se stejným idPodani … již existuje".
     */
    public function reportsExistingIdenticalSubmission(): bool
    {
        if ($this->code !== self::DUPLICATE_SUBMISSION_CODE) {
            return false;
        }
        $message = mb_strtolower($this->message, 'UTF-8');

        return str_contains($message, 'již existuje')
            && preg_match('/se\s+stejným\s+idpodan[ií]/u', $message) === 1;
    }

    /**
     * Kontrola katalogu ke kódu post DIS validace ({@see self::POST_DIS_VALIDATION_CONTROLS}).
     * Registrační protokoly kód jen přenášejí (viz {@see self::fromRegistrationCode()}),
     * vysvětlení protokolu se ale ke kontrole dostat potřebuje.
     */
    public static function postDisValidationControl(int $code): ?JmhzControlId
    {
        $controlId = self::POST_DIS_VALIDATION_CONTROLS[$code] ?? null;

        return $controlId === null ? null : new JmhzControlId($controlId);
    }

    public function requireControlId(): JmhzControlId
    {
        if ($this->controlId === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_error_not_a_control',
                "Kód chyby {$this->code} neodkazuje na kontrolu z katalogu.",
            );
        }

        return $this->controlId;
    }
}
