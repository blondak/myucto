<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;

/**
 * Zařazení zmrazeného podání JMHZ do fronty podání datovou schránkou.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč tahle třída nic neodesílá
 * ═══════════════════════════════════════════════════════════════════════════
 * Odesílá {@see SubmissionOutboxService} a doručenku řeší
 * {@see \MyInvoice\Service\Submission\DeliveryReceiptService} — obojí existuje
 * a funguje pro daňová podání i přehledy pojišťovnám. Tahle třída je jen
 * PŘEKLADATEL: z mzdového světa (zmrazený artefakt, variabilní symbol, rozhodné
 * období) udělá to, čemu rozumí obecná fronta (příjemce z číselníku, věc,
 * artefakt), a předá to dál.
 *
 * Druhá odesílací cesta by znamenala druhý stavový automat, druhé místo, kde se
 * dá splést adresát, a druhou evidenci doručenek — přitom lhůta a povinnost
 * jsou jedny a tytéž bez ohledu na kanál.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Dvě cesty ven, obě přes tutéž frontu
 * ═══════════════════════════════════════════════════════════════════════════
 * **Odesílací brána ISDS** ({@see \MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayDispatchService}):
 * aplikace vloží koncept do perimetru ISDS a odeslání schválí uživatel přímo
 * tam. Přístupové údaje ke schránce naším serverem neprojdou vůbec, takže
 * § 9 odst. 2 zák. 300/2008 Sb. je splněn konstrukcí. Podmínkou je, aby
 * provozovatel bránu zaregistroval a zapnul — dokud to neudělá, hlásí
 * {@see enqueue()} poctivě `automatic: false`.
 *
 * **Ruční cesta** zůstává a je celá průchozí:
 *   1. tady se podání zařadí do fronty a dostane příjemce, věc i spisovou značku,
 *   2. uživatel si přílohu stáhne a odešle ze SVÉ datové schránky,
 *   3. `POST /api/submissions/outbox/{id}/mark-sent` zapíše, že odešla,
 *   4. nahraná doručenka (ZFO) podání uzavře; rozhodný den doručení včetně fikce
 *      spočítá {@see \MyInvoice\Service\Submission\DeliveryFictionCalculator}.
 *
 * Doručenku je nutné nahrát ručně i po odeslání branou: brána umí JEN odesílat
 * a schránku číst neumí. Strojový `IsdsTransport` proto zůstává nabindovaný na
 * {@see \MyInvoice\Service\Submission\Channel\Isds\UnavailableIsdsTransport}.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co tahle cesta NEMĚNÍ
 * ═══════════════════════════════════════════════════════════════════════════
 * Lhůtu ani povinnost. Zařazení do fronty nezakládá druhé podání — pracuje se
 * ZMRAZENÝM artefaktem téhož podání, které už v `payroll_submissions` existuje.
 * Volba kanálu je tedy rozhodnutí o dopravě, ne o tom, co a dokdy se podává.
 */
final readonly class JmhzIsdsSubmissionService
{
    /** Kód agendy je shodný s cestou VREP — je to totéž podání. */
    public const AGENDA_CODE = JmhzSubmissionBridgeService::AGENDA_CODE;

    /** Kód v číselníku `submission_recipients`, seed v migraci 1410. */
    public const RECIPIENT_CODE_PRODUCTION = 'cssz_epodani_jmhz';
    public const RECIPIENT_CODE_TEST = 'cssz_epodani_test';

    private const CHANNEL = 'isds';
    private const ARTIFACT_KIND = 'payroll_submission';

    public function __construct(
        private JmhzFrozenPayloadReader $frozen,
        private PayrollSubmissionRepository $submissions,
        private SubmissionRecipientRepository $recipients,
        private SubmissionOutboxService $outbox,
        private JmhzIsdsMessageBuilder $builder = new JmhzIsdsMessageBuilder(),
        /**
         * Volitelně schválně: mzdová větev nesmí spadnout jen proto, že brána
         * není nastavená. Když chybí, hlásí se poctivě „automaticky to nejde".
         */
        private ?IsdsGatewayRegistrationService $gateway = null,
    ) {}

    /**
     * Zařadí zmrazené podání do fronty a vrátí i hotovou datovou zprávu, aby ji
     * uživatel mohl v ručním režimu přesně opsat do své datové schránky.
     *
     * @return array{
     *   outbox_id:int,created:bool,row:array<string,mixed>,
     *   recipient:array{box_id:string,name:string,note:string},
     *   subject:string,sender_ident:string,
     *   attachment:array{filename:string,mime:string,sha256:string,bytes:int},
     *   transport:array{automatic:bool,channel:string,reason:?string}
     * }
     */
    public function enqueue(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?int $userId,
    ): array {
        $identity = $this->frozen->identity($supplierId, $environment, $submissionId);
        $payload = $this->frozen->bytes($supplierId, $environment, $submissionId);

        $artifactId = $this->submissions->findOutboundXmlArtifactId(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($artifactId === null) {
            throw new JmhzTransportException(
                'jmhz_submission_frozen_payload_missing',
                'Podání nemá uloženou zmrazenou datovou větu, takže ho nelze'
                    . ' zařadit k odeslání datovou schránkou.',
            );
        }

        $recipient = $this->recipient($supplierId, $environment);
        $periodLabel = $this->periodLabel($identity->month, $identity->year);
        $subject = $this->builder->subject(
            self::AGENDA_CODE,
            $periodLabel,
            $identity->variableSymbol,
        );

        $enqueued = $this->outbox->enqueue(
            $supplierId,
            $environment,
            self::CHANNEL,
            self::AGENDA_CODE,
            self::ARTIFACT_KIND,
            $artifactId,
            (int) $recipient['id'],
            $subject,
            $userId,
        );
        $row = $enqueued['row'];

        // Zpráva se staví AŽ TEĎ, protože spisovou značku přiděluje fronta.
        // V ručním režimu je to jediný údaj, který se musí do datové schránky
        // opsat přesně — podle něj se pak dohledá odpověď ČSSZ.
        $message = $this->builder->build(
            $payload,
            self::AGENDA_CODE,
            $identity->variableSymbol,
            $periodLabel,
            (string) $row['correlation_reference'],
            $environment,
        );

        return [
            'outbox_id' => (int) $row['id'],
            'created' => $enqueued['created'],
            'row' => $row,
            'recipient' => [
                'box_id' => $message->recipient->boxId,
                'name' => $message->recipient->boxName,
                'note' => $message->recipient->note,
            ],
            'subject' => $message->subject,
            'sender_ident' => $message->senderIdent,
            'attachment' => [
                'filename' => $message->attachmentFilename,
                'mime' => $message->attachmentMimeType,
                'sha256' => $message->attachmentSha256(),
                'bytes' => strlen($message->attachmentBytes),
            ],
            'transport' => $this->transportAvailability($environment),
        ];
    }

    /**
     * Je tahle zpráva ve schránce odpovědí ČSSZ na naše odeslané podání?
     *
     * V ručním režimu je tohle jediná pomoc, kterou uživateli umíme dát: ISDS
     * neumí došlé zprávy filtrovat podle věci ani podle spisové značky (protokol
     * ČSSZ v1.47, strana 24), takže odpověď musí najít člověk okem mezi ostatní
     * poštou. Rozhoduje `dmId` NAŠÍ odeslané zprávy, ne čas ani pořadí — vzít
     * „poslední od ČSSZ“ by u zaměstnavatele, který podává každý měsíc,
     * přiřadilo protokol jiného období.
     *
     * Kladná odpověď NEZNAMENÁ přijetí podání. Věc datové zprávy je nepodepsaný
     * text; obsah přílohy musí projít ověřením podpisu úplně stejně jako u VREP.
     *
     * @return array{matches:bool,reference:?array{class_name:string,correlation_id:string,original_message_id:string}}
     */
    public function matchResponse(?string $subject, string $sentMessageId): array
    {
        $matcher = new JmhzIsdsResponseMatcher();
        $reference = $matcher->parseSubject($subject);

        return [
            'matches' => $matcher->matches(
                $subject,
                $sentMessageId,
                JmhzDispatchService::SUBMISSION_CLASS,
            ),
            'reference' => $reference === null ? null : [
                'class_name' => $reference->className,
                'correlation_id' => $reference->correlationId,
                'original_message_id' => $reference->originalMessageId,
            ],
        ];
    }

    /**
     * Podle čeho uživatel odpověď ve schránce POZNÁ.
     *
     * Tvary slibuje protokol ČSSZ v1.47 na straně 24; posílají se do UI, aby
     * návod nebyl napsaný natvrdo ve frontendu, kde by se při změně protokolu
     * nedohledal.
     *
     * @return array{subject_prefix:string,attachment_prefix:string,note:string}
     */
    public function responseHint(): array
    {
        return [
            'subject_prefix' => JmhzIsdsResponseMatcher::SUBJECT_PREFIX,
            'attachment_prefix' => JmhzIsdsResponseMatcher::ATTACHMENT_PREFIX,
            'note' => 'ČSSZ pošle odpověď do schránky, ze které podání odešlo.'
                . ' Ve věci uvede v hranaté závorce ID vaší odeslané zprávy.'
                . ' Zpráva zůstává ve schránce 90 dnů.',
        ];
    }

    /**
     * Příjemce se bere Z ČÍSELNÍKU, ne z konstanty v kódu.
     *
     * Katalog {@see JmhzIsdsRecipientCatalog} drží tytéž hodnoty a slouží jako
     * kontrola: kdyby někdo v číselníku ID schránky přepsal, tahle brána to
     * zachytí dřív, než mzdové údaje odejdou jinam. Číselník je editovatelný
     * (aby šlo doplnit místně příslušnou OSSZ), takže se na něj u ČSSZ nespoléhá
     * slepě.
     */
    private function recipient(int $supplierId, string $environment): array
    {
        $code = strtolower(trim($environment)) === 'production'
            ? self::RECIPIENT_CODE_PRODUCTION
            : self::RECIPIENT_CODE_TEST;

        $row = $this->recipients->findVisibleByCode($supplierId, $code);
        if ($row === null) {
            throw new JmhzTransportException(
                'jmhz_isds_recipient_missing',
                'V číselníku příjemců není datová schránka ČSSZ pro JMHZ.'
                    . ' Spusťte migrace.',
            );
        }

        $expected = JmhzIsdsRecipientCatalog::forEnvironment($environment);
        $actual = strtolower(trim((string) ($row['isds_box_id'] ?? '')));
        if (!hash_equals($expected->boxId, $actual)) {
            throw new SubmissionChannelException(
                'jmhz_isds_recipient_mismatch',
                'Datová schránka ČSSZ v číselníku neodpovídá doložené hodnotě.'
                    . ' Podání s mzdovými údaji se neodešle, dokud se to nesrovná.',
                409,
            );
        }

        return $row;
    }

    /**
     * Jde JMHZ odeslat bez ručního opisování do datové schránky?
     *
     * Odpověď je tu, ne natvrdo v UI, protože se v čase mění: dokud
     * provozovatel nezaregistruje odesílací bránu a nezapne ji, zůstává
     * jedinou úplnou cestou ruční odeslání. Tvrdit opak by uživatele nechalo
     * čekat na odeslání, které nepřijde.
     *
     * `channel` říká, KTEROU cestou to půjde:
     *   `gateway`       — koncept vloží aplikace, odeslání schválí uživatel v ISDS,
     *   `manual_upload` — uživatel stáhne přílohu a odešle ji sám.
     *
     * @return array{automatic:bool,channel:string,reason:?string}
     */
    private function transportAvailability(string $environment): array
    {
        if ($this->gateway !== null && $this->gateway->isUsable($environment)) {
            return ['automatic' => true, 'channel' => 'gateway', 'reason' => null];
        }

        return [
            'automatic' => false,
            'channel' => 'manual_upload',
            // Strojový transport `IsdsTransport` nasazený není a odesílací
            // brána buď není zaregistrovaná, nebo je vypnutá.
            'reason' => 'isds_transport_unavailable',
        ];
    }

    /** Období pro člověka; do XML nevstupuje, takže je to čistě popisek. */
    private function periodLabel(int $month, int $year): string
    {
        return sprintf('%02d/%d', $month, $year);
    }
}
