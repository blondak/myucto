<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Vrep;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessChannelCatalog;

/**
 * Odeslání zmrazeného NEMPRI, HZUPN nebo OZUSPOJ přes VREP/APEP.
 *
 * Stejný princip jako {@see \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationTransportService}:
 * adaptér nic nesestavuje znovu. Načte archivované bajty, ověří jejich
 * formulář a variabilní symbol a předá je beze změny transportu JMHZ, který
 * podepíše, zašifruje a zapíše pokus do ledgeru. Tělo je tentýž dokument jako
 * u datové schránky (podací protokol v1.47, str. 24: ISDS = holé XML, VREP ho
 * jen obalí).
 *
 * Třídy a formuláře jsou z `CSSZSubmClasses.pdf` (Přehled obálek ČSSZ):
 * NEMPRI25 a HZUPN20 sdílí `CSSZ_NEM_PRI`, OZUSPOJ23 má `CSSZ_OZUSPOJ`.
 *
 * Kanál datovou schránkou zůstává: připravené podání je vedené na ISDS a na
 * VREP se přepne až ve chvíli, kdy jím opravdu odchází. Podání, které už čeká
 * v odchozí frontě datové schránky, se přes VREP neodešle.
 *
 * Ostré prostředí je zavřené ({@see self::PRODUCTION_OPEN}), dokud tvar obálky
 * a protokolu neověří zkušební podání do testovacího prostředí ČSSZ — stejně
 * se postupovalo u JMHZ a registrací. Žádný cron tahle podání sám neodesílá.
 */
final readonly class CsszFormVrepTransportService
{
    /**
     * Ostré podání přes VREP. Otevírá se až po ověření zkušebním podáním do
     * TEST ČSSZ a výslovném pokynu (`private/normy/VREP-MEZERY.md`, 4.1 bod 6).
     */
    public const PRODUCTION_OPEN = false;

    /** Agenda povinnosti → formulář, třída a místo VS v datové větě. */
    private const DOCUMENTS = [
        'NEMPRI' => [
            'form' => 'NEMPRI25',
            'class' => SicknessChannelCatalog::VREP_CLASS,
            'root' => 'NEMPRI',
            'namespace' => 'http://schemas.cssz.cz/nem/NEMPRI25',
            'symbol' => '/f:NEMPRI/f:datovaVeta/f:zamestnani/f:VSZamestnavatel',
        ],
        'HZUPN' => [
            'form' => 'HZUPN20',
            'class' => SicknessChannelCatalog::VREP_CLASS,
            'root' => 'PodaniHZUPN',
            'namespace' => 'http://schemas.cssz.cz/nem/HZUPN20',
            'symbol' => '/f:PodaniHZUPN/f:FormularHZUPN/f:zamestnani/f:variabilniSymbol',
        ],
        'OZUSPOJ' => [
            'form' => OzuspojSchemaCatalog::DOCUMENT_TYPE,
            'class' => 'CSSZ_OZUSPOJ',
            'root' => 'podaniOzuspoj',
            'namespace' => OzuspojSchemaCatalog::NAMESPACE,
            'symbol' => '/f:podaniOzuspoj/f:formularOzuspoj/f:zamestnavatel/f:vs',
        ],
    ];

    /** Stavy pokusu, u kterých má smysl se ČSSZ ptát na výsledek. */
    private const POLLABLE_ATTEMPT_STATUSES = ['awaiting_protocol', 'possibly_delivered'];

    /**
     * Stavy podání, u kterých je pokus k dotažení. `ready` zůstává podání,
     * jehož pokus je „možná doručeno" (potvrzení převzetí nedorazilo).
     */
    private const POLLABLE_SUBMISSION_STATUSES = [
        'ready',
        'submitted',
        'processing',
        'partially_accepted',
        'accepted',
        'rejected',
    ];

    public function __construct(
        private PayrollSubmissionRepository $submissions,
        private PayrollSubmissionTransportAttemptRepository $attempts,
        private JmhzFrozenPayloadReader $frozen,
        private JmhzDispatchService $dispatch,
        private PayrollSubmissionService $platform,
    ) {}

    /** @return list<string> agendy, které adaptér odesílá */
    public static function agendaCodes(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    /** @return array<string,mixed> */
    public function send(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $idempotencyKey,
        ?int $actorUserId,
    ): array {
        $context = $this->context($supplierId, $environment, $submissionId, []);
        $document = self::DOCUMENTS[$context['agenda_code']];
        $payload = $this->payload(
            $this->frozen->bytes($supplierId, $environment, $submissionId),
            $document,
        );
        $existing = $this->attempts->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $this->assertReplayScope($existing, $supplierId, $environment, $submissionId, $payload['sha256']);

            return $this->result(new JmhzDispatchOutcome($existing), $context['agenda_code'], $document, $payload);
        }
        if ($environment === 'production' && !self::PRODUCTION_OPEN) {
            // Výjimka zůstává: poslední brána před ostrým podáním. Tvar obálky
            // a protokolu ověří až zkušební podání do testu ČSSZ.
            throw new \DomainException(
                'Odeslání ' . $context['agenda_code'] . ' přes VREP je zatím otevřené jen'
                . ' v testovacím prostředí ČSSZ. Do ostrého prostředí podání odešlete'
                . ' datovou schránkou.',
            );
        }
        if ($context['status'] !== 'ready') {
            // Výjimka zůstává: druhé odeslání téhož podání by u ČSSZ založilo
            // duplicitní hlášení a vzít ho zpět nejde.
            throw new \DomainException(
                'Podání ' . $context['agenda_code'] . ' už bylo odesláno nebo není'
                . ' připravené, takže se přes VREP neodesílá. Jak dopadlo, zjistíte'
                . ' v historii odeslání u téhož podání.',
            );
        }
        if ($this->submissions->hasActiveIsdsOutbox($supplierId, $environment, $submissionId)) {
            throw new \DomainException(
                'Podání ' . $context['agenda_code'] . ' už čeká v odchozí frontě datové'
                . ' schránky nebo jí odešlo. Přes VREP se proto neodesílá — u ČSSZ by'
                . ' vzniklo dvakrát.',
            );
        }
        // Podání se připravilo na výchozí kanál agendy (datová schránka).
        // Odchází-li VREP, musí to platforma vědět: jinak by se protokol
        // z VREP k podání nedal přiřadit („Kanál protokolu neodpovídá podání").
        $this->platform->adoptDispatchChannel($supplierId, $submissionId, JmhzDispatchService::CHANNEL);
        $outcome = $this->dispatch->send(
            $supplierId,
            $environment,
            $submissionId,
            $payload['bytes'],
            $payload['variable_symbol'],
            $idempotencyKey,
            $actorUserId,
            $document['class'],
            $document['form'],
        );

        return $this->result($outcome, $context['agenda_code'], $document, $payload);
    }

    /** @return array{agenda_code:string,submission_class:string,form:string,attempt:?array<string,mixed>} */
    public function status(int $supplierId, string $environment, int $submissionId): array
    {
        $context = $this->context($supplierId, $environment, $submissionId, []);
        $document = self::DOCUMENTS[$context['agenda_code']];
        $history = $this->attempts->listForSubmission($supplierId, $environment, $submissionId);

        return [
            'agenda_code' => $context['agenda_code'],
            'submission_class' => $document['class'],
            'form' => $document['form'],
            'attempt' => $history === [] ? null : $history[array_key_last($history)],
        ];
    }

    public function poll(int $supplierId, string $environment, int $attemptId): JmhzDispatchOutcome
    {
        $attempt = $this->attempt($supplierId, $environment, $attemptId);
        if (!in_array((string) ($attempt['status'] ?? ''), self::POLLABLE_ATTEMPT_STATUSES, true)) {
            // Výjimka zůstává: dotaz na výsledek volá ČSSZ. U uzavřeného pokusu
            // není nač se ptát.
            throw new JmhzTransportException(
                'cssz_form_dispatch_poll_unavailable',
                'Odpověď ČSSZ se dá zjistit jen u odeslání, které na ni teprve čeká.'
                . ' Tohle odeslání už je uzavřené — jak dopadlo, najdete v historii odeslání.',
            );
        }
        $submissionId = (int) $attempt['submission_id'];
        $context = $this->context($supplierId, $environment, $submissionId, self::POLLABLE_SUBMISSION_STATUSES);
        $document = self::DOCUMENTS[$context['agenda_code']];
        $payload = $this->payload(
            $this->frozen->bytes($supplierId, $environment, $submissionId),
            $document,
        );

        return $this->dispatch->poll(
            $supplierId,
            $environment,
            $attemptId,
            $payload['variable_symbol'],
            1,
            $document['class'],
            $document['form'],
        );
    }

    /** @return array{closed:bool,already_closed:bool,attempt:array<string,mixed>} */
    public function close(int $supplierId, string $environment, int $attemptId): array
    {
        $attempt = $this->attempt($supplierId, $environment, $attemptId);
        $submissionId = (int) $attempt['submission_id'];
        $context = $this->context($supplierId, $environment, $submissionId, self::POLLABLE_SUBMISSION_STATUSES);
        $document = self::DOCUMENTS[$context['agenda_code']];
        $payload = $this->payload(
            $this->frozen->bytes($supplierId, $environment, $submissionId),
            $document,
        );

        return $this->dispatch->close(
            $supplierId,
            $environment,
            $attemptId,
            $payload['variable_symbol'],
            $document['class'],
            $document['form'],
        );
    }

    /**
     * @param list<string> $statuses prázdný seznam = jen vlastnictví, prostředí a agenda
     * @return array{agenda_code:string,status:string}
     */
    private function context(int $supplierId, string $environment, int $submissionId, array $statuses): array
    {
        $submission = $this->submissions->findSubmission($supplierId, $submissionId);
        if ($submission === null) {
            // Výjimka zůstává: hranice mezi firmami.
            throw new \DomainException(
                'Podání pod tímhle číslem u téhle firmy neexistuje. Otevřete případ'
                . ' nebo záměr znovu a odeslání spusťte z něj.',
            );
        }
        if ($submission['environment'] !== $environment) {
            // Výjimka zůstává: testovací a ostré prostředí ČSSZ jsou dvě identity.
            throw new \DomainException(
                'Podání bylo připraveno pro jiné prostředí (testovací × ostré), než ze'
                . ' kterého se teď odesílá. Přepněte prostředí zpět na to, ve kterém'
                . ' podání vzniklo.',
            );
        }
        if ($statuses !== [] && !in_array($submission['status'], $statuses, true)) {
            throw new \DomainException(
                'Podání zatím nebylo odesláno na ČSSZ, takže u něj nejde zjišťovat ani'
                . ' uzavírat odpověď. Nejdřív podání odešlete.',
            );
        }
        $obligation = $this->submissions->findObligationOfSubmission($supplierId, $environment, $submissionId);
        $agenda = $obligation === null ? '' : strtoupper(trim((string) $obligation['agenda_code']));
        if (!isset(self::DOCUMENTS[$agenda])) {
            // Výjimka zůstává: jinou agendu by obálka ohlásila pod cizím formulářem.
            throw new \DomainException(
                'Touhle cestou se přes VREP odesílá jen NEMPRI, HZUPN a OZUSPOJ. Tohle'
                . ' podání patří do jiné agendy — odešlete ho z obrazovky Mzdy → Podání'
                . ' a hlášení.',
            );
        }

        return ['agenda_code' => $agenda, 'status' => (string) $submission['status']];
    }

    /**
     * @param array{form:string,class:string,root:string,namespace:string,symbol:string} $document
     * @return array{bytes:string,sha256:string,variable_symbol:string}
     */
    private function payload(string $bytes, array $document): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML($bytes, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $dom->documentElement;
        if (!$loaded
            || !$root instanceof DOMElement
            || $root->localName !== $document['root']
            || $root->namespaceURI !== $document['namespace']
        ) {
            throw new \DomainException(
                'Připravené podání neodpovídá formuláři ' . $document['form'] . ', pod'
                . ' kterým je vedené. Připravte ho znovu — v tomhle stavu by ho ČSSZ'
                . ' odmítla.',
            );
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('f', $document['namespace']);
        $symbols = [];
        foreach ($xpath->query($document['symbol']) ?: [] as $node) {
            $value = trim((string) $node->textContent);
            if ($value !== '') {
                $symbols[$value] = true;
            }
        }
        $symbol = count($symbols) === 1 ? (string) array_key_first($symbols) : '';
        if (preg_match('/^[0-9]{10}$/D', $symbol) !== 1) {
            // Výjimka zůstává: pod nejasným VS by ČSSZ podání přiřadila jinému
            // zaměstnavateli nebo ho odmítla.
            throw new \DomainException(
                'Variabilní symbol zaměstnavatele u ČSSZ musí být v celém podání jediný'
                . ' a mít deset číslic. Doplňte ho na Mzdy → Nastavení mezd →'
                . ' Zaměstnavatel a účtárny a podání připravte znovu.',
            );
        }

        return [
            'bytes' => $bytes,
            'sha256' => hash('sha256', $bytes),
            'variable_symbol' => $symbol,
        ];
    }

    /** @param array<string,mixed> $attempt */
    private function assertReplayScope(
        array $attempt,
        int $supplierId,
        string $environment,
        int $submissionId,
        string $payloadSha256,
    ): void {
        if ((int) ($attempt['supplier_id'] ?? 0) !== $supplierId
            || (string) ($attempt['environment'] ?? '') !== $environment
            || (int) ($attempt['submission_id'] ?? 0) !== $submissionId
            || (string) ($attempt['channel'] ?? '') !== JmhzDispatchService::CHANNEL
            || !hash_equals($payloadSha256, (string) ($attempt['request_sha256'] ?? ''))
        ) {
            throw new \DomainException(
                'Odeslání se nedá zopakovat — pod stejným požadavkem je už evidované'
                . ' jiné podání nebo jiná data. Načtěte stránku znovu a odeslání spusťte'
                . ' od začátku.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function attempt(int $supplierId, string $environment, int $attemptId): array
    {
        $attempt = $this->attempts->find($supplierId, $environment, $attemptId);
        if ($attempt === null) {
            throw new JmhzTransportException(
                'cssz_form_dispatch_attempt_unknown',
                'Odeslání pod tímhle číslem u téhle firmy neexistuje. Otevřete historii'
                . ' odeslání znovu a vyberte konkrétní odeslání z ní.',
                404,
            );
        }

        return $attempt;
    }

    /**
     * @param array{form:string,class:string,root:string,namespace:string,symbol:string} $document
     * @param array{bytes:string,sha256:string,variable_symbol:string} $payload
     * @return array<string,mixed>
     */
    private function result(
        JmhzDispatchOutcome $outcome,
        string $agendaCode,
        array $document,
        array $payload,
    ): array {
        return [
            'agenda_code' => $agendaCode,
            'submission_class' => $document['class'],
            'form' => $document['form'],
            'payload_sha256' => $payload['sha256'],
            'attempt' => $outcome->attempt,
            'acknowledgement' => $outcome->acknowledgement === null ? null : [
                'correlation_id' => $outcome->acknowledgement->correlationId,
                'poll_interval_seconds' => $outcome->acknowledgement->pollIntervalSeconds,
                'gateway_timestamp' => $outcome->acknowledgement->gatewayTimestamp,
            ],
            'settled' => $outcome->isSettled(),
            'manual_review' => $outcome->manualReview,
        ];
    }
}
