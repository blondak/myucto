<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Kanál podání po jednotlivých zdravotních pojišťovnách.
 *
 * Datová věta je od 1. 1. 2026 společná pro všech sedm, kanál ale ne: zákon
 * (§ 25 odst. 7 zákona č. 592/1992 Sb., § 10c zákona č. 48/1997 Sb.) nechává
 * formát i způsob zaručené identity na pojišťovně. Pět pojišťoven stojí pod
 * Portálem ZP, VZP a ZP MV ČR mají vlastní cestu.
 *
 * **Katalog je fail-closed.** U ŽÁDNÉ ze sedmi není veřejně popsaná
 * transportní obálka — endpoint, MIME, název přílohy, limity ani formát
 * odpovědi. Popis brány Portálu ZP je dostupný jen po dohodě s pojišťovnami,
 * B2B popis ZP MV ČR byl slíben na Q2 2026 a ke dni rešerše nevyšel.
 * `assertDispatchable()` proto skončí vždy a pojmenovaně — stejně jako
 * `jmhz_vrep_production_endpoint_unknown`, který je v repu `null` ze stejného
 * důvodu. Automatické odeslání na hádaný endpoint se nepřipravuje.
 *
 * Co katalog naopak umožňuje: vyrobit soubor a nechat ho účetní odeslat
 * doloženou cestou — datovou schránkou nebo ručním nahráním do portálu.
 */
final class HealthInsurerChannelCatalog
{
    public const REASON_TRANSPORT_UNDOCUMENTED =
        'zp_transport_envelope_undocumented';
    public const REASON_PORTAL_GATEWAY_ON_REQUEST =
        'zp_portal_gateway_description_on_request';
    public const REASON_B2B_NOT_PUBLISHED =
        'zp_b2b_interface_not_published';
    public const REASON_SHARED_MESSAGE_UNCONFIRMED =
        'zp_shared_data_message_acceptance_unconfirmed';

    /** @var array<string,HealthInsurerChannel>|null */
    private static ?array $channels = null;

    /** @return array<string,HealthInsurerChannel> */
    public function channels(): array
    {
        return self::$channels ??= self::build();
    }

    public function forInsurer(string $insurerCode): HealthInsurerChannel
    {
        $channel = $this->channels()[$insurerCode] ?? null;
        if ($channel === null) {
            throw new HealthNotificationException(
                'zp_insurer_code_unknown',
                'Kód zdravotní pojišťovny není v jednotné datové větě.',
            );
        }

        return $channel;
    }

    /**
     * Smí aplikace podání odeslat sama? Nikdy — a vždy s důvodem, který
     * pojmenuje, co přesně chybí.
     */
    public function assertDispatchable(string $insurerCode): never
    {
        $channel = $this->forInsurer($insurerCode);

        throw new HealthNotificationException(
            $channel->undocumentedReasonCode,
            sprintf(
                'Automatické odeslání pojišťovně %s (%s) není doložené: %s '
                . 'Soubor se vyrobí a stáhne, odeslání zůstává na účetní.',
                $channel->insurerCode,
                $channel->insurerName,
                $channel->note,
            ),
        );
    }

    /** @return array<string,HealthInsurerChannel> */
    private static function build(): array
    {
        $channels = [
            new HealthInsurerChannel(
                insurerCode: '111',
                insurerName: 'VZP ČR',
                kind: HealthInsurerChannelKind::OwnPortal,
                dataBoxId: 'i48ae3q',
                portalUrl: null,
                // VZP je AUTOREM jednotného schématu, ale sama ho na svém webu
                // nepublikuje a nikde neuvádí, že VZP Point nový formát přijímá.
                // Autorství není potvrzení příjmu.
                acceptsSharedDataMessage: false,
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_SHARED_MESSAGE_UNCONFIRMED,
                note: 'VZP Point vyžaduje registraci a dohodu o zabezpečené '
                    . 'elektronické komunikaci, B2B rozhraní certifikát '
                    . 'a smlouvu. Že VZP Point přijímá jednotné XML, veřejné '
                    . 'zdroje nepotvrzují; e-mail VZP výslovně nepodporuje.',
            ),
            new HealthInsurerChannel(
                insurerCode: '201',
                insurerName: 'VoZP ČR',
                kind: HealthInsurerChannelKind::SharedPortal,
                dataBoxId: null,
                portalUrl: 'https://portal.vozp.cz',
                acceptsSharedDataMessage: true,
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_PORTAL_GATEWAY_ON_REQUEST,
                note: 'Popis komunikační brány Portálu ZP je dostupný jen '
                    . 'po dohodě se zdravotními pojišťovnami.',
            ),
            new HealthInsurerChannel(
                insurerCode: '205',
                insurerName: 'ČPZP',
                kind: HealthInsurerChannelKind::SharedPortal,
                dataBoxId: 'mk5ab8i',
                portalUrl: 'https://portal.cpzp.cz',
                acceptsSharedDataMessage: true,
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_PORTAL_GATEWAY_ON_REQUEST,
                note: 'Nejúplněji zdokumentovaná ze sedmi: e-přepážka, datová '
                    . 'schránka, e-mail jen s elektronickým podpisem '
                    . 'a časovým razítkem. Transportní obálka brány přesto '
                    . 'veřejně popsaná není.',
            ),
            new HealthInsurerChannel(
                insurerCode: '207',
                insurerName: 'OZP',
                kind: HealthInsurerChannelKind::SharedPortal,
                dataBoxId: 'q9iadw9',
                portalUrl: 'https://portal.ozp.cz',
                acceptsSharedDataMessage: true,
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_PORTAL_GATEWAY_ON_REQUEST,
                note: 'Jedna ze dvou pojišťoven, které jednotné XSD veřejně '
                    . 'publikují. Transportní obálka brány popsaná není.',
            ),
            new HealthInsurerChannel(
                insurerCode: '209',
                insurerName: 'ZP Škoda',
                kind: HealthInsurerChannelKind::SharedPortal,
                dataBoxId: null,
                portalUrl: 'https://portal.zpskoda.cz',
                // Shoda datové věty je pravděpodobná (Portál ZP, standard
                // SZP–VZP), doložená ale není — na webu ZPŠ se XSD nenašlo.
                acceptsSharedDataMessage: false,
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_SHARED_MESSAGE_UNCONFIRMED,
                note: 'Přijetí jednotné datové věty se na webu pojišťovny '
                    . 'nepodařilo doložit; předpoklad shody se nepovažuje '
                    . 'za doklad.',
            ),
            new HealthInsurerChannel(
                insurerCode: '211',
                insurerName: 'ZP MV ČR',
                kind: HealthInsurerChannelKind::OwnPortal,
                dataBoxId: '9swaix3',
                portalUrl: 'https://eforms.zpmvcr.cz',
                acceptsSharedDataMessage: true,
                automatedDispatchDocumented: false,
                undocumentedReasonCode: self::REASON_B2B_NOT_PUBLISHED,
                note: 'Nové XML přijímá datovou schránkou až od 1. 7. 2026, '
                    . 'B2B rozhraní jede na starém formátu a jeho popis, '
                    . 'slíbený na druhé čtvrtletí 2026, zveřejněný není.',
            ),
            new HealthInsurerChannel(
                insurerCode: '213',
                insurerName: 'RBP',
                kind: HealthInsurerChannelKind::SharedPortal,
                dataBoxId: null,
                portalUrl: 'https://portal.rbp-zp.cz',
                acceptsSharedDataMessage: false,
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_SHARED_MESSAGE_UNCONFIRMED,
                note: 'Přijetí jednotné datové věty se na webu pojišťovny '
                    . 'nepodařilo doložit.',
            ),
        ];

        $indexed = [];
        foreach ($channels as $channel) {
            $indexed[$channel->insurerCode] = $channel;
        }

        return $indexed;
    }
}
