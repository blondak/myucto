<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Odchozí kanál podání — EPO, datová schránka, do budoucna cokoliv dalšího.
 *
 * ── Proč právě tyhle čtyři operace ───────────────────────────────────────────
 * `send` / `fetchStatus` / `downloadConfirmation` jsou tři kroky, které umí
 * každý kanál. Čtvrtá — `probe` — je tu kvůli jediné situaci, kterou jinak
 * nejde vyřešit: volání se přeruší a my nevíme, jestli zpráva odešla.
 * Bez dohledávací operace zbývají jen dvě špatné možnosti (poslat znovu =
 * duplicita u úřadu, nebo neposlat = zmeškaná lhůta), takže `probe` není
 * komfort, ale podmínka bezpečného provozu.
 *
 * ── Proč kanály nesdílejí jeden „status" ────────────────────────────────────
 * Kanály vracejí RŮZNĚ SILNÝ důkaz a ten rozdíl se nesmí ztratit v převodu:
 *   - EPO vrací strukturovaný protokol o přijetí → ví, jestli úřad podání
 *     přijal, nebo odmítl a proč,
 *   - ISDS vrací doručenku → ví jen, že zpráva DORAZILA.
 * {@see ChannelStatus} proto nese dvě nezávislé osy a {@see evidenceStrength()}
 * říká, kterou z nich smí ten který kanál vůbec hýbat. Kanál s
 * {@see ChannelEvidenceStrength::DeliveryOnly} nemá jak vědět, co úřad rozhodl,
 * takže se jeho případné tvrzení o přijetí zahodí ještě před zápisem do DB.
 */
interface SubmissionChannel
{
    /** Strojový kód kanálu — shodný s ENUM `submission_outbox.channel`. */
    public function code(): string;

    /**
     * Jak silný důkaz kanál dokáže vrátit. Není to popis, ale oprávnění:
     * podle téhle hodnoty se rozhoduje, jestli kanál smí posunout osu vyřízení.
     */
    public function evidenceStrength(): ChannelEvidenceStrength;

    /**
     * Ověří, že příjemce je dosažitelný — u ISDS dotazem do samotného ISDS,
     * protože náš číselník smí zestárnout, ale ISDS je autoritativní.
     *
     * Povinná brána před {@see send()}. Vrací `null`, když kanál příjemce
     * neadresuje (EPO míří na bránu, ne na schránku).
     *
     * @return array{usable:bool,reason:?string,owner_name:?string}|null
     * @throws SubmissionChannelException když se ověřit nepodařilo — nevědomost
     *         se nesmí vydávat za „schránka je v pořádku"
     */
    public function verifyRecipient(?string $recipientBoxId, ChannelContext $context): ?array;

    /**
     * Odešle podání.
     *
     * Implementace MUSÍ do zprávy vložit `$submission->correlationReference`
     * ještě před odesláním (u ISDS do `dmSenderIdent`, limit 50 znaků) — bez
     * něj nejde po přerušeném volání zjistit, jestli zpráva odešla.
     *
     * Přerušené volání se vrací jako {@see DispatchResult::uncertain()},
     * NIKOLI jako výjimka a nikdy ne jako `failed`.
     */
    public function send(OutboundSubmission $submission, ChannelContext $context): DispatchResult;

    /**
     * Dohledá, jestli zpráva s danou correlation reference odešla.
     * Volá se jen po {@see DispatchResult::uncertain()}.
     */
    public function probe(string $correlationReference, ChannelContext $context): DispatchProbe;

    /**
     * Co kanál ví o odeslaném podání.
     *
     * @throws SubmissionChannelException když se stav nepodařilo zjistit.
     *         Selhání se NIKDY nevrací jako „nic nového" — to by tiše zmrazilo
     *         podání v posledním známém stavu.
     */
    public function fetchStatus(string $externalMessageId, ChannelContext $context): ChannelStatus;

    /**
     * Stáhne potvrzení (doručenku, protokol) jako soubor k archivaci.
     * `null` = potvrzení zatím není k dispozici.
     *
     * @return array{filename:string,mime:string,bytes:string}|null
     */
    public function downloadConfirmation(string $externalMessageId, ChannelContext $context): ?array;
}
